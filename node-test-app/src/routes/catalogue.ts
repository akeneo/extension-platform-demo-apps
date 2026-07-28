import { Router } from 'express';
import * as crypto from 'crypto';
import { db } from '../lib/db';
import { cacheGet, cacheSet } from '../lib/cache';
import type { Product } from '@prisma/client';

const router = Router();
const PER_PAGE = 12;

router.get('/', async (_req, res) => {
  const categories = await db.$queryRaw<Array<{ code: string; label: string | null }>>`
    SELECT c.code, c.label
    FROM category c
    WHERE c.code IN (
      SELECT DISTINCT jsonb_array_elements_text(p.categories::jsonb)
      FROM product p
      WHERE p.categories IS NOT NULL AND p.categories::text != 'null'
    )
    ORDER BY COALESCE(c.label, c.code)
  `;
  res.render('catalogue/index.njk', { categories });
});

router.get('/product/:identifier', async (req, res) => {
  const product = await db.product.findUnique({ where: { identifier: req.params.identifier } });
  if (!product || product.parent !== null) {
    res.status(404).render('404.njk');
    return;
  }
  const labelMap = await getCategoryLabelMap();
  res.render('catalogue/product.njk', {
    product: serializeProduct(product, labelMap),
  });
});

router.get('/model/:parentCode(*)', async (req, res) => {
  const variants = await db.product.findMany({
    where: { parent: req.params.parentCode },
    orderBy: { syncedAt: 'desc' },
  });
  if (!variants.length) {
    res.status(404).render('404.njk');
    return;
  }
  const labelMap = await getCategoryLabelMap();
  const modelLabel = (variants[0].parentLabel as string | null) ?? req.params.parentCode;
  const serializedVariants = variants.map(v => serializeProduct(v, labelMap));
  const allCatCodes = [...new Set(serializedVariants.flatMap(v => v.categories.map((c: { code: string }) => c.code)))];
  const catMap = Object.fromEntries(serializedVariants.flatMap(v => v.categories.map((c: { code: string; label: string }) => [c.code, c.label])));
  res.render('catalogue/model.njk', {
    model_code:   req.params.parentCode,
    model_label:  modelLabel,
    variants:     serializedVariants,
    variantsJson: JSON.stringify(serializedVariants),
    allCatCodes,
    catMap,
  });
});

router.get('/api/products', async (req, res) => {
  const page     = Math.max(1, parseInt(req.query.page as string ?? '1'));
  const category = (req.query.category as string) || null;
  const enabledQ = req.query.enabled as string | undefined;
  const enabled  = enabledQ !== undefined ? enabledQ === '1' || enabledQ === 'true' : null;

  const cacheKey = 'catalogue_' + crypto.createHash('md5').update(JSON.stringify([page, category, enabled])).digest('hex');
  const cached = await cacheGet(cacheKey);
  if (cached) {
    res.setHeader('Content-Type', 'application/json');
    res.send(cached);
    return;
  }

  const allProducts = await db.product.findMany({
    where: enabled !== null ? { enabled } : undefined,
    orderBy: { syncedAt: 'desc' },
  });

  const filtered = category
    ? allProducts.filter(p => (p.categories as string[] | null)?.includes(category))
    : allProducts;

  const labelMap = await getCategoryLabelMap();

  const simple: Product[] = [];
  const modelGroups: Record<string, Product[]> = {};
  for (const product of filtered) {
    if (product.parent) {
      (modelGroups[product.parent] ??= []).push(product);
    } else {
      simple.push(product);
    }
  }

  type Entry =
    | { type: 'product'; product: Product; ts: number }
    | { type: 'model'; code: string; variants: Product[]; ts: number };

  const entries: Entry[] = [
    ...simple.map(p => ({ type: 'product' as const, product: p, ts: p.syncedAt?.getTime() ?? 0 })),
    ...Object.entries(modelGroups).map(([code, variants]) => ({
      type: 'model' as const,
      code,
      variants,
      ts: Math.max(...variants.map(v => v.syncedAt?.getTime() ?? 0)),
    })),
  ];

  entries.sort((a, b) => b.ts - a.ts);

  const total       = entries.length;
  const pageEntries = entries.slice((page - 1) * PER_PAGE, page * PER_PAGE);

  const serialized = pageEntries.map(e =>
    e.type === 'model'
      ? serializeModel(e.code, e.variants, labelMap)
      : serializeProduct(e.product, labelMap),
  );

  const payload = JSON.stringify({
    products: serialized,
    total,
    page,
    per_page: PER_PAGE,
    has_more: page * PER_PAGE < total,
  });

  await cacheSet(cacheKey, payload, 300, ['catalogue']);

  res.setHeader('Content-Type', 'application/json');
  res.send(payload);
});

async function getCategoryLabelMap(): Promise<Record<string, string>> {
  const cats = await db.category.findMany();
  return Object.fromEntries(cats.map(c => [c.code, c.label ?? c.code]));
}

function serializeProduct(p: Product, labelMap: Record<string, string>) {
  const label = (p.label as Record<string, string> | null);
  const imageFilenames = (p.imageFilenames as string[] | null) ?? [];
  const categories = (p.categories as string[] | null) ?? [];
  return {
    type:            'product',
    identifier:      p.identifier,
    label:           label?.en_US ?? p.identifier,
    image_urls:      imageFilenames.map(f => '/images/' + f),
    categories:      categories.map(code => ({ code, label: labelMap[code] ?? code })),
    enabled:         p.enabled,
    is_variant:      p.parent !== null,
    parent:          p.parent,
    parent_label:    p.parentLabel,
    variation_label: p.variationLabel,
    description:     p.description,
    completeness:    p.completeness,
    price:           p.price ?? null,
    stock:           p.stock ?? null,
    synced_at:       p.syncedAt?.toISOString() ?? null,
  };
}

function serializeModel(parentCode: string, variants: Product[], labelMap: Record<string, string>) {
  const cover = variants.find(v => (v.imageFilenames as string[] | null)?.length) ?? variants[0];
  const imageFilenames = (cover.imageFilenames as string[] | null) ?? [];
  const categoryCodes = [...new Set(variants.flatMap(v => (v.categories as string[] | null) ?? []))];
  const enabledCount = variants.filter(v => v.enabled).length;
  return {
    type:          'model',
    parent:        parentCode,
    parent_label:  cover.parentLabel ?? parentCode,
    image_urls:    imageFilenames.map(f => '/images/' + f),
    categories:    categoryCodes.map(code => ({ code, label: labelMap[code] ?? code })),
    variant_count: variants.length,
    enabled_count: enabledCount,
  };
}

export default router;
