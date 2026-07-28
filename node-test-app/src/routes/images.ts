import { Router } from 'express';
import * as path from 'path';
import * as fs from 'fs';

const router = Router();

const imageStorageDir = process.env.PRODUCT_IMAGES_DIR ?? '/var/product-images';

router.get('/images/:filename', (req, res) => {
  const filename = path.basename(req.params.filename);
  if (!/^[^/]+\.[a-zA-Z]{2,5}$/.test(filename)) {
    res.status(404).end();
    return;
  }
  const filePath = path.join(imageStorageDir, filename);
  if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
    res.status(404).end();
    return;
  }
  res.setHeader('Cache-Control', 'public, max-age=86400');
  res.sendFile(filePath);
});

export default router;
