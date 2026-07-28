import { Router } from 'express';

const router = Router();

router.get('/about', (_req, res) => {
  res.render('about.njk');
});

export default router;
