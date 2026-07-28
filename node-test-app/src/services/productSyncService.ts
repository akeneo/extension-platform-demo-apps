import * as fs from 'fs';
import * as path from 'path';
import { Prisma } from '@prisma/client';
import { db } from '../lib/db';
import { cacheInvalidateTags } from '../lib/cache';
import { akeneoApiClient } from './akeneoApiClient';
import { getPrices } from './priceService';
import { getStocks } from './stockService';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const imageStorageDir = process.env.PRODUCT_IMAGES_DIR ?? '/var/product-images';

interface ParentInfo {
  label: string;
  axes: string[];
}

export async function syncProductsByUuid(uuids: string[], trigger = 'sync'): Promise<number> {
  ensureImageDir();
  return persistBatch(await akeneoApiClient.getProductsByUuid(uuids), trigger);
}

export async function syncProducts(identifiers: string[], trigger = 'sync'): Promise<number> {
  ensureImageDir();
  return persistBatch(await akeneoApiClient.getProducts(identifiers), trigger);
}

// Unlike syncProductsByUuid/syncProducts, the model codes ARE the parent
// codes — no need to fetch products first to discover them. Resolving axes
// upfront lets the single products fetch include axis attribute values
// directly, entirely avoiding the fetch-then-re-fetch-for-axes round trip
// that the other two entry points need.
export async function syncProductsByModelCodes(modelCodes: string[], trigger = 'sync'): Promise<number> {
  ensureImageDir();

  const parentInfo = await resolveParentInfoForCodes(modelCodes);
  const axes = [...new Set(Object.values(parentInfo).flatMap(p => p.axes))];

  const akeneoProducts = await akeneoApiClient.getProductsByModelCodes(modelCodes, axes);

  return persistBatch(akeneoProducts, trigger, parentInfo);
}

export async function updateIfExists(identifier: string, isUuid = false): Promise<boolean> {
  const existing = await db.product.findUnique({ where: { identifier } });
  if (!existing) return false;

  const results = isUuid
    ? await akeneoApiClient.getProductsByUuid([identifier])
    : await akeneoApiClient.getProducts([identifier]);

  if (!results.length) return false;

  ensureImageDir();
  await persistBatch(results, 'webhook');
  return true;
}

// `resolvedParentInfo` can be passed in already resolved (see
// syncProductsByModelCodes) when the caller fetched products with axis
// attributes already included — this skips both re-deriving it from the
// product data and the redundant axis-enrichment re-fetch.
async function persistBatch(akeneoProducts: unknown[], trigger: string, resolvedParentInfo?: Record<string, ParentInfo>): Promise<number> {
  const products = akeneoProducts as any[];
  await preSyncCategories(products);

  let parentInfo = resolvedParentInfo;
  let enriched = products;
  if (!parentInfo) {
    parentInfo = await preSyncParentInfo(products);
    enriched = await enrichVariantAxisValues(products, parentInfo);
  }

  // Fetched concurrently for the whole batch rather than one blocking
  // request per product — see priceService/stockService's get*() comments.
  const [prices, stocks] = await Promise.all([
    getPrices(enriched.length),
    getStocks(enriched.length),
  ]);

  let count = 0;
  for (const [i, data] of enriched.entries()) {
    await persistProduct(data, parentInfo, prices[i] ?? null, stocks[i] ?? null);
    count++;
  }

  if (count > 0) {
    await db.syncLog.create({ data: { count, trigger } });
    await cacheInvalidateTags(['catalogue']);
  }

  return count;
}

async function enrichVariantAxisValues(products: any[], parentInfo: Record<string, ParentInfo>): Promise<any[]> {
  const axes = [...new Set(Object.values(parentInfo).flatMap(p => p.axes))];
  if (!axes.length) return products;

  const variantUuids = products
    .filter(d => d.parent)
    .map(d => d.uuid)
    .filter(Boolean);

  if (!variantUuids.length) return products;

  try {
    const enriched = await akeneoApiClient.getProductsByUuid(variantUuids, axes);
    const enrichedMap = new Map((enriched as any[]).map(p => [p.uuid, p]));

    return products.map(data => {
      const enrichedProduct = data.uuid ? enrichedMap.get(data.uuid) : null;
      if (!enrichedProduct) return data;
      const updated = { ...data };
      for (const axis of axes) {
        if (enrichedProduct.values?.[axis]) {
          updated.values = { ...updated.values, [axis]: enrichedProduct.values[axis] };
        }
      }
      return updated;
    });
  } catch {
    return products;
  }
}

async function persistProduct(data: any, parentInfo: Record<string, ParentInfo>, price: number | null, stock: number | null): Promise<void> {
  const uuid = data.uuid ?? null;
  const legacyId = data.identifier ?? null;

  let existing = uuid ? await db.product.findUnique({ where: { identifier: uuid } }) : null;
  if (!existing && legacyId) {
    existing = await db.product.findUnique({ where: { identifier: legacyId } });
  }

  const identifier = existing?.identifier ?? uuid ?? legacyId;
  const parentCode: string | null = data.parent ?? null;
  const pi = parentCode ? parentInfo[parentCode] ?? null : null;
  const parentLabel = pi?.label ?? null;
  const axes = pi?.axes ?? [];

  const descAttr = akeneoApiClient.getDescriptionAttribute();
  let description: string | null = null;
  for (const v of data.values?.[descAttr] ?? []) {
    if (v.locale === 'en_US' || v.locale === null) { description = v.data; break; }
  }

  const labelAttr = akeneoApiClient.getLabelAttribute();
  let labelEn: string = legacyId ?? identifier;
  let labelSet = false;
  for (const v of data.values?.[labelAttr] ?? []) {
    if (v.locale === 'en_US' || v.locale === null) { labelEn = v.data; labelSet = true; break; }
  }
  if (!labelSet && parentLabel !== null) labelEn = parentLabel;

  // Build variation label from axis attribute values
  const variationParts: string[] = [];
  for (const axis of axes) {
    for (const v of data.values?.[axis] ?? []) {
      if (v.data !== null) {
        variationParts.push(Array.isArray(v.data) ? v.data.join(', ') : String(v.data));
        break;
      }
    }
  }
  if (!variationParts.length && legacyId && parentCode) {
    const prefix = parentCode + '_';
    if (legacyId.startsWith(prefix)) {
      const suffix = legacyId.slice(prefix.length);
      if (suffix) variationParts.push(suffix);
    } else if (legacyId !== parentCode) {
      variationParts.push(legacyId);
    }
  }
  const variationLabel = variationParts.length ? variationParts.join(' / ') : null;

  // Download images
  const safeId = identifier.replace(/[^a-zA-Z0-9_\-]/g, '_');
  const imageFilenames: string[] = [];
  const imageAttrs = akeneoApiClient.getImageAttributes();
  for (let i = 0; i < imageAttrs.length; i++) {
    const imageValues = data.values?.[imageAttrs[i]] ?? [];
    if (!imageValues.length) continue;
    const mediaCode: string | null = imageValues[0]?.data ?? null;
    if (!mediaCode) continue;
    const ext = path.extname(mediaCode).replace('.', '') || 'jpg';
    const filename = `${safeId}_${i}.${ext}`;
    const targetPath = path.join(imageStorageDir, filename);
    try {
      await akeneoApiClient.downloadMediaFile(mediaCode, targetPath);
      imageFilenames.push(filename);
    } catch {
      // non-fatal — skip the image
    }
  }

  const productData = {
    label: { en_US: labelEn } as Prisma.InputJsonValue,
    imageFilenames: (imageFilenames.length ? imageFilenames : Prisma.DbNull) as Prisma.InputJsonValue,
    categories: (data.categories ?? Prisma.DbNull) as Prisma.InputJsonValue,
    enabled: data.enabled ?? false,
    parent: parentCode,
    parentLabel,
    variationLabel,
    description,
    completeness: data.completeness ?? null,
    price,
    stock,
    syncedAt: new Date(),
  };

  await db.product.upsert({
    where: { identifier },
    create: { identifier, ...productData },
    update: productData,
  });
}

async function preSyncParentInfo(products: any[]): Promise<Record<string, ParentInfo>> {
  const codes = [...new Set(products.map(d => d.parent).filter(Boolean))] as string[];
  return resolveParentInfoForCodes(codes);
}

// Same as preSyncParentInfo, but for when the parent/model codes are already
// known upfront (syncProductsByModelCodes) instead of derived from fetched
// product data.
async function resolveParentInfoForCodes(codes: string[]): Promise<Record<string, ParentInfo>> {
  if (!codes.length) return {};

  let modelInfo: Record<string, { label: string; family: string | null; family_variant: string | null }> = {};
  try { modelInfo = await akeneoApiClient.getProductModelInfo(codes); } catch { /* non-fatal */ }

  // Fetch family variant axes once per unique (family, family_variant) pair
  const pairAxes: Record<string, string[]> = {};
  for (const info of Object.values(modelInfo)) {
    if (!info.family || !info.family_variant) continue;
    const key = `${info.family}|${info.family_variant}`;
    if (key in pairAxes) continue;
    try {
      pairAxes[key] = await akeneoApiClient.getFamilyVariantAxes(info.family, info.family_variant);
    } catch {
      pairAxes[key] = [];
    }
  }

  const result: Record<string, ParentInfo> = {};
  for (const code of codes) {
    const info = modelInfo[code];
    const key = info?.family && info?.family_variant ? `${info.family}|${info.family_variant}` : null;
    result[code] = {
      label: info?.label ?? code,
      axes: key !== null ? (pairAxes[key] ?? []) : [],
    };
  }
  return result;
}

async function preSyncCategories(products: any[]): Promise<void> {
  const codes = [...new Set(products.flatMap(d => d.categories ?? []))];
  if (!codes.length) return;

  const labels = await akeneoApiClient.getCategories(codes);
  for (const code of codes) {
    await db.category.upsert({
      where: { code },
      create: { code, label: labels[code] ?? null },
      update: { label: labels[code] ?? null },
    });
  }
}

function ensureImageDir(): void {
  if (!fs.existsSync(imageStorageDir)) {
    fs.mkdirSync(imageStorageDir, { recursive: true });
  }
}
