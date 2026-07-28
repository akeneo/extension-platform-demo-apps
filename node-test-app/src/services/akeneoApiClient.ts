import { akeneoTokenProvider } from './akeneoTokenProvider';
import { recordApiCall } from '../lib/apiCallTracker';
import * as fs from 'fs';

const LOCALE = 'en_US';

function chunk<T>(arr: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
  return out;
}

function unique(arr: string[]): string[] {
  return [...new Set(arr)];
}

export class AkeneoApiClient {
  private readonly baseUrl = (process.env.AKENEO_BASE_URL ?? '').replace(/\/$/, '');
  private readonly imageAttributeEnv = process.env.AKENEO_IMAGE_ATTRIBUTE ?? '';
  private readonly labelAttribute = process.env.AKENEO_LABEL_ATTRIBUTE ?? 'name';
  private readonly descriptionAttribute = process.env.AKENEO_DESCRIPTION_ATTRIBUTE ?? 'description';

  getLabelAttribute(): string { return this.labelAttribute; }
  getDescriptionAttribute(): string { return this.descriptionAttribute; }
  getImageAttributes(): string[] {
    return this.imageAttributeEnv.split(',').map(s => s.trim()).filter(Boolean);
  }

  private baseAttributes(extra: string[] = []): string[] {
    return unique([this.labelAttribute, this.descriptionAttribute, ...this.getImageAttributes(), ...extra].filter(Boolean));
  }

  async getProducts(identifiers: string[], extraAttributes: string[] = []): Promise<unknown[]> {
    if (!identifiers.length) return [];
    const attributes = this.baseAttributes(extraAttributes);
    const items: unknown[] = [];
    for (const batch of chunk(identifiers, 50)) {
      const res = await this.request('GET', '/api/rest/v1/products', {
        search: JSON.stringify({ identifier: [{ operator: 'IN', value: batch }] }),
        locales: LOCALE,
        attributes: attributes.join(','),
        limit: String(batch.length),
      });
      items.push(...((res as any)._embedded?.items ?? []));
    }
    return items;
  }

  async getProductsByUuid(uuids: string[], extraAttributes: string[] = []): Promise<unknown[]> {
    if (!uuids.length) return [];
    const attributes = this.baseAttributes(extraAttributes);
    const items: unknown[] = [];
    for (const batch of chunk(uuids, 50)) {
      const res = await this.request('GET', '/api/rest/v1/products-uuid', {
        search: JSON.stringify({ uuid: [{ operator: 'IN', value: batch }] }),
        locales: LOCALE,
        attributes: attributes.join(','),
        limit: String(batch.length),
      });
      items.push(...((res as any)._embedded?.items ?? []));
    }
    return items;
  }

  async getProductsByModelCodes(codes: string[], extraAttributes: string[] = []): Promise<unknown[]> {
    if (!codes.length) return [];
    const attributes = this.baseAttributes(extraAttributes);
    const items: unknown[] = [];
    for (const batch of chunk(codes, 25)) {
      let page = 1;
      while (true) {
        const res = (await this.request('GET', '/api/rest/v1/products-uuid', {
          search: JSON.stringify({ parent: [{ operator: 'IN', value: batch }] }),
          locales: LOCALE,
          attributes: attributes.join(','),
          limit: '100',
          page: String(page),
        })) as any;
        const pageItems: unknown[] = res._embedded?.items ?? [];
        items.push(...pageItems);
        if (!res._links?.next || pageItems.length === 0) break;
        page++;
      }
    }
    return items;
  }

  async getCategories(codes: string[]): Promise<Record<string, string>> {
    if (!codes.length) return {};
    const labels: Record<string, string> = {};
    for (const batch of chunk(codes, 100)) {
      const res = (await this.request('GET', '/api/rest/v1/categories', {
        search: JSON.stringify({ code: [{ operator: 'IN', value: batch }] }),
        limit: String(batch.length),
      })) as any;
      for (const item of res._embedded?.items ?? []) {
        labels[item.code] = item.labels?.[LOCALE] ?? item.code;
      }
    }
    return labels;
  }

  // A single GET per code carries the label, family, and family_variant, so
  // this replaces what used to be two separate per-code loops (label lookup +
  // family/variant lookup) each fetching the exact same resource.
  async getProductModelInfo(codes: string[]): Promise<Record<string, { label: string; family: string | null; family_variant: string | null }>> {
    const result: Record<string, { label: string; family: string | null; family_variant: string | null }> = {};
    for (const code of codes) {
      const item = await this.fetchProductModelByCode(code);
      if (!item) continue;

      let label: string | null = null;
      for (const v of item.values?.[this.labelAttribute] ?? []) {
        if (v.locale === LOCALE || v.locale === null) { label = v.data; break; }
      }

      result[code] = {
        label: label ?? code,
        family: item.family ?? null,
        family_variant: item.family_variant ?? null,
      };
    }
    return result;
  }

  async getFamilyVariantAxes(family: string, variantCode: string): Promise<string[]> {
    const res = (await this.request(
      'GET',
      `/api/rest/v1/families/${encodeURIComponent(family)}/variants/${encodeURIComponent(variantCode)}`,
    )) as any;
    const axes: string[] = [];
    for (const set of res.variant_attribute_sets ?? []) {
      for (const axis of set.axes ?? []) axes.push(axis);
    }
    return [...new Set(axes)];
  }

  async downloadMediaFile(mediaCode: string, targetPath: string): Promise<void> {
    const encodedCode = mediaCode.split('/').map(encodeURIComponent).join('/');
    const token = await akeneoTokenProvider.getToken();
    recordApiCall();
    const response = await fetch(`${this.baseUrl}/api/rest/v1/media-files/${encodedCode}/download`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!response.ok) {
      throw new Error(`Media file download failed with HTTP ${response.status}: ${mediaCode}`);
    }
    const buffer = await response.arrayBuffer();
    fs.writeFileSync(targetPath, Buffer.from(buffer));
  }

  private async fetchProductModelByCode(code: string): Promise<any | null> {
    try {
      return await this.request('GET', `/api/rest/v1/product-models/${encodeURIComponent(code)}`);
    } catch (e: any) {
      if (e?.message?.includes('HTTP 404')) return null;
      throw e;
    }
  }

  private async request(method: string, path: string, query?: Record<string, string>): Promise<unknown> {
    const makeRequest = async (): Promise<Response> => {
      const token = await akeneoTokenProvider.getToken();
      const url = new URL(this.baseUrl + path);
      if (query) {
        for (const [k, v] of Object.entries(query)) url.searchParams.set(k, v);
      }
      recordApiCall();
      return fetch(url.toString(), { method, headers: { Authorization: `Bearer ${token}` } });
    };

    let response = await makeRequest();

    if (response.status === 401) {
      await akeneoTokenProvider.invalidateToken();
      response = await makeRequest();
    }

    if (!response.ok) {
      throw new Error(`Akeneo API ${method} ${path} failed with HTTP ${response.status}: ${await response.text()}`);
    }

    return response.json();
  }
}

export const akeneoApiClient = new AkeneoApiClient();
