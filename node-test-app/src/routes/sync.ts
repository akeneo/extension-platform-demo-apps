import { Router, Request, Response } from 'express';
import * as crypto from 'crypto';
import { db } from '../lib/db';
import { publish } from '../lib/queue';

const router = Router();

const akeneoBaseUrl = (process.env.AKENEO_BASE_URL ?? '').replace(/\/$/, '');

function hmacValid(body: string, header: string, secrets: string[], algorithm = 'sha512'): boolean {
  if (!header) return false;
  const prefix = `${algorithm}=`;
  const received = header.startsWith(prefix) ? header.slice(prefix.length) : header;

  for (const secret of secrets) {
    const expected = crypto.createHmac(algorithm, secret).update(body).digest('hex');
    if (crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(received))) {
      return true;
    }
  }
  return false;
}

function corsHeaders(req: Request, res: Response): void {
  const origin = req.headers.origin ?? '';
  if (origin && (origin.startsWith(akeneoBaseUrl) || origin.includes('.akeneo.com'))) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Akeneo-Request-Signature');
    res.setHeader('Access-Control-Max-Age', '3600');
  }
}

router.get('/api/sync/status', async (_req, res) => {
  try {
    const since = new Date(Date.now() - 60 * 60 * 1000);
    const pending = await db.syncAttempt.count({ where: { status: 'queued', queuedAt: { gt: since } } });
    const processing = await db.syncAttempt.count({ where: { status: 'running', queuedAt: { gt: since } } });
    res.json({ in_progress: pending + processing > 0, pending, processing });
  } catch {
    res.json({ in_progress: false, pending: 0, processing: 0 });
  }
});

router.options('/api/products/sync', (req, res) => {
  corsHeaders(req, res);
  res.status(204).end();
});

router.post('/api/products/sync', async (req: Request, res: Response) => {
  corsHeaders(req, res);

  const secrets = (process.env.AKENEO_SYNC_SECRET ?? '').split(',').map(s => s.trim()).filter(Boolean);
  const signature = req.headers['signature'] as string ?? '';
  const rawBody = (req as any).rawBody ?? '';

  if (!hmacValid(rawBody, signature, secrets)) {
    await db.syncAttempt.create({
      data: {
        type: 'rejected',
        status: 'failed',
        input: String(rawBody).slice(0, 500),
        error: 'Invalid signature',
        startedAt: new Date(),
        finishedAt: new Date(),
      },
    });
    res.status(401).json({ error: 'Invalid signature' });
    return;
  }

  const data = req.body?.data ?? {};
  const uuids = extractValues(data, ['productUuid', 'productUuids']);
  const identifiers = extractValues(data, ['productIdentifier', 'productIdentifiers']);
  const modelCodes = extractValues(data, ['productModelCode', 'productModelCodes']);

  if (!uuids.length && !identifiers.length && !modelCodes.length) {
    res.status(400).json({ error: 'No product UUID, identifier, or model code found in payload data' });
    return;
  }

  if (uuids.length) {
    const attempt = await db.syncAttempt.create({ data: { type: 'syncByUuid', input: describeCount(uuids, 'UUID') } });
    await publish(attempt.id, { type: 'syncByUuid', uuids });
  }
  if (identifiers.length) {
    const attempt = await db.syncAttempt.create({ data: { type: 'syncByIdentifier', input: describeCount(identifiers, 'identifier') } });
    await publish(attempt.id, { type: 'syncByIdentifier', identifiers });
  }
  if (modelCodes.length) {
    const attempt = await db.syncAttempt.create({ data: { type: 'syncByModelCode', input: describeCount(modelCodes, 'model code') } });
    await publish(attempt.id, { type: 'syncByModelCode', modelCodes });
  }

  res.json({ queued: uuids.length + identifiers.length });
});

function extractValues(data: Record<string, unknown>, keys: string[]): string[] {
  for (const key of keys) {
    if (key in data) {
      const v = data[key];
      return Array.isArray(v) ? v : [v as string];
    }
  }
  return [];
}

function describeCount(values: string[], noun: string): string {
  return values.length === 1 ? values[0] : `${values.length} ${noun}s`;
}

export default router;
