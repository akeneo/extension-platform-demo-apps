import * as path from 'path';
import express, { Request, Response } from 'express';
import nunjucks from 'nunjucks';

import catalogueRouter from './routes/catalogue';
import productRouter   from './routes/product';
import syncRouter      from './routes/sync';
import webhookRouter   from './routes/webhook';
import statsRouter     from './routes/stats';
import imagesRouter    from './routes/images';
import pagesRouter     from './routes/pages';
import logsRouter      from './routes/logs';

const app = express();

app.use(express.json({
  verify: (req: any, _res, buf) => { req.rawBody = buf.toString('utf8'); },
}));
app.use(express.urlencoded({
  extended: true,
  verify: (req: any, _res, buf) => { req.rawBody ??= buf.toString('utf8'); },
}));

// Nunjucks
const env = nunjucks.configure(path.join(__dirname, '..', 'views'), {
  autoescape: true,
  express: app,
});

env.addFilter('json', (value: unknown) => JSON.stringify(value));
env.addFilter('number_format', (value: number, decimals = 0) => Number(value).toFixed(decimals));
env.addFilter('dateformat', (value: string | null) => {
  if (!value) return '';
  return new Date(value).toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false,
  });
});

// Routes
app.use(syncRouter);
app.use(webhookRouter);
app.use(imagesRouter);
app.use(catalogueRouter);
app.use(productRouter);
app.use(statsRouter);
app.use(pagesRouter);
app.use(logsRouter);

// 404 fallback
app.use((_req: Request, res: Response) => {
  res.status(404).send('Not found');
});

const port = parseInt(process.env.PORT ?? '3000');
app.listen(port, () => {
  console.log(`Server listening on http://localhost:${port}`);
});

export default app;
