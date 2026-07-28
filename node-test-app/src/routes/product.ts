import { Router } from 'express';
import * as fs from 'fs';
import * as path from 'path';
import { db } from '../lib/db';
import { cacheInvalidateTags } from '../lib/cache';

const router = Router();

const imageStorageDir = process.env.PRODUCT_IMAGES_DIR ?? '/var/product-images';

router.delete('/api/model/:parentCode(*)', async (req, res) => {
  const { parentCode } = req.params;
  const variants = await db.product.findMany({ where: { parent: parentCode } });

  if (!variants.length) {
    res.status(404).json({ error: 'Model not found' });
    return;
  }

  for (const variant of variants) {
    for (const filename of (variant.imageFilenames as string[] | null) ?? []) {
      const p = path.join(imageStorageDir, filename);
      if (fs.existsSync(p)) fs.unlinkSync(p);
    }
  }

  await db.product.deleteMany({ where: { parent: parentCode } });
  await cacheInvalidateTags(['catalogue']);

  res.json({ deleted: variants.length });
});

router.delete('/api/products', async (_req, res) => {
  const rows = await db.$queryRaw<Array<{ image_filenames: string | null }>>`
    SELECT image_filenames FROM product WHERE image_filenames IS NOT NULL
  `;
  for (const row of rows) {
    for (const filename of JSON.parse(row.image_filenames ?? '[]') as string[]) {
      const p = path.join(imageStorageDir, filename);
      if (fs.existsSync(p)) fs.unlinkSync(p);
    }
  }

  const { count } = await db.product.deleteMany();
  await cacheInvalidateTags(['catalogue']);

  res.json({ deleted: count });
});

router.delete('/api/products/:identifier', async (req, res) => {
  const product = await db.product.findUnique({ where: { identifier: req.params.identifier } });

  if (!product) {
    res.status(404).json({ error: 'Product not found' });
    return;
  }

  for (const filename of (product.imageFilenames as string[] | null) ?? []) {
    const p = path.join(imageStorageDir, filename);
    if (fs.existsSync(p)) fs.unlinkSync(p);
  }

  await db.product.delete({ where: { identifier: req.params.identifier } });
  await cacheInvalidateTags(['catalogue']);

  res.json({ deleted: true });
});

export default router;
