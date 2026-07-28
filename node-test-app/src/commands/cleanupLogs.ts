import { db } from '../lib/db';

const RETENTION_DAYS = 14;

async function run(): Promise<void> {
  const cutoff = new Date(Date.now() - RETENTION_DAYS * 24 * 60 * 60 * 1000);

  const { count } = await db.syncAttempt.deleteMany({ where: { queuedAt: { lt: cutoff } } });

  console.log(`Deleted ${count} sync attempt log(s) older than ${RETENTION_DAYS} days.`);
  process.exit(0);
}

run().catch(e => { console.error(e); process.exit(1); });
