import { Router } from 'express';
import { db } from '../lib/db';

const router = Router();

router.get('/logs', (_req, res) => {
  res.render('logs/index.njk');
});

router.get('/api/logs', async (req, res) => {
  const type = typeof req.query.type === 'string' && req.query.type ? req.query.type : undefined;
  const limit = Math.min(500, Math.max(1, parseInt(String(req.query.limit ?? '100'), 10) || 100));
  const offset = Math.max(0, parseInt(String(req.query.offset ?? '0'), 10) || 0);
  const order = req.query.order === 'asc' ? 'asc' : 'desc';
  const where = type ? { type } : undefined;

  try {
    const [attempts, total] = await Promise.all([
      db.syncAttempt.findMany({
        where,
        orderBy: { queuedAt: order },
        take: limit,
        skip: offset,
      }),
      db.syncAttempt.count({ where }),
    ]);

    const entries = attempts.map(a => {
      const duration = a.startedAt && a.finishedAt
        ? a.finishedAt.getTime() - a.startedAt.getTime()
        : null;

      return {
        id:         a.id,
        type:       a.type,
        status:     a.status,
        input:      a.input,
        count:      a.count,
        apiCalls:   a.apiCalls,
        error:      a.error,
        queuedAt:   a.queuedAt.getTime(),
        startedAt:  a.startedAt?.getTime() ?? null,
        finishedAt: a.finishedAt?.getTime() ?? null,
        duration,
        attempts:   a.attempts,
      };
    });

    res.json({ entries, total, limit, offset });
  } catch (err) {
    console.error('Logs API error:', err);
    res.json({ entries: [], total: 0, limit, offset });
  }
});

export default router;
