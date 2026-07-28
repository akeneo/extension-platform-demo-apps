import { Router } from 'express';
import { db } from '../lib/db';
import { Prisma } from '@prisma/client';

const router = Router();

router.get('/stats', (_req, res) => {
  res.render('stats/index.njk');
});

router.get('/api/stats', async (_req, res) => {
  try {
    const totals = await db.$queryRaw<Array<{
      total: bigint; enabled: bigint; disabled: bigint; variants: bigint; simple: bigint;
    }>>`
      SELECT
        COUNT(*)                                            AS total,
        COUNT(*) FILTER (WHERE enabled = true)             AS enabled,
        COUNT(*) FILTER (WHERE enabled = false)            AS disabled,
        COUNT(*) FILTER (WHERE parent IS NOT NULL)         AS variants,
        COUNT(*) FILTER (WHERE parent IS NULL)             AS simple
      FROM product
    `;

    const byCategory = await db.$queryRaw<Array<{ label: string; count: bigint }>>`
      SELECT
        COALESCE(c.label, cat_code) AS label,
        COUNT(*)                    AS count
      FROM product p,
           jsonb_array_elements_text(
               CASE
                   WHEN p.categories IS NOT NULL AND p.categories::text != 'null'
                   THEN p.categories::jsonb
                   ELSE '[]'::jsonb
               END
           ) AS cat_code
      LEFT JOIN category c ON c.code = cat_code
      GROUP BY cat_code, c.label
      ORDER BY count DESC
      LIMIT 12
    `;

    const syncedByDay = await db.$queryRaw<Array<{ day: string; count: bigint }>>`
      SELECT
        TO_CHAR(DATE(synced_at), 'YYYY-MM-DD') AS day,
        SUM(count)                             AS count
      FROM sync_log
      WHERE synced_at >= NOW() - INTERVAL '14 days'
      GROUP BY DATE(synced_at)
      ORDER BY DATE(synced_at) ASC
    `;

    const t = totals[0] ?? { total: 0n, enabled: 0n, disabled: 0n, variants: 0n, simple: 0n };

    res.json({
      total:    Number(t.total),
      enabled:  Number(t.enabled),
      disabled: Number(t.disabled),
      variants: Number(t.variants),
      simple:   Number(t.simple),
      by_category:   byCategory.map(r => ({ label: r.label, count: Number(r.count) })),
      synced_by_day: syncedByDay.map(r => ({ day: r.day, count: Number(r.count) })),
    });
  } catch (e) {
    console.error('Stats error:', e);
    res.json({ total: 0, enabled: 0, disabled: 0, variants: 0, simple: 0, by_category: [], synced_by_day: [] });
  }
});

export default router;
