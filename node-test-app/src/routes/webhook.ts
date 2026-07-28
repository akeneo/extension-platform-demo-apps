import { Router, Request } from 'express';
import * as crypto from 'crypto';
import { db } from '../lib/db';
import { publish } from '../lib/queue';

const router = Router();

function hmacValid(body: string, signature: string, secrets: string[]): boolean {
  if (!signature) return false;
  for (const secret of secrets) {
    const expected = crypto.createHmac('sha256', secret).update(body).digest('hex');
    try {
      if (expected.length === signature.length &&
          crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(signature, 'hex'))) {
        return true;
      }
    } catch { /* length mismatch */ }
  }
  return false;
}

router.post('/webhook/product-updated', async (req: Request, res) => {
  const secrets = (process.env.AKENEO_WEBHOOK_SECRET ?? '').split(',').map(s => s.trim()).filter(Boolean);
  const body = req.rawBody ?? '';
  const primary = req.headers['x-akeneo-signature-primary'] as string ?? '';
  const secondary = req.headers['x-akeneo-signature-secondary'] as string ?? '';

  const valid = (primary && hmacValid(body, primary, secrets)) ||
                (secondary && hmacValid(body, secondary, secrets));

  if (!valid) {
    res.status(401).json({ error: 'Invalid signature' });
    return;
  }

  const parsed = req.body;
  if (!parsed || typeof parsed !== 'object') {
    res.status(400).json({ error: 'Invalid payload' });
    return;
  }

  const events = Array.isArray(parsed) ? parsed : [parsed];
  let queued = 0;

  for (const event of events) {
    const uuid: string | null = event?.data?.product?.uuid ?? null;
    if (!uuid) continue;
    const attempt = await db.syncAttempt.create({ data: { type: 'productUpdated', input: uuid } });
    await publish(attempt.id, { type: 'productUpdated', identifier: uuid, isUuid: true });
    queued++;
  }

  res.json({ queued });
});

export default router;
