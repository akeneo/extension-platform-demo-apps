import { db } from '../lib/db';
import { publish, closeConnection } from '../lib/queue';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const BATCH = 50;

function chunk<T>(arr: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
  return out;
}

async function run(): Promise<void> {
  const products = await db.product.findMany({ select: { identifier: true } });

  if (!products.length) {
    console.log('No products in catalogue — nothing to sync.');
    process.exit(0);
  }

  const uuids       = products.map(p => p.identifier).filter(id => UUID_PATTERN.test(id));
  const identifiers = products.map(p => p.identifier).filter(id => !UUID_PATTERN.test(id));

  let dispatched = 0;

  for (const batch of chunk(uuids, BATCH)) {
    const attempt = await db.syncAttempt.create({ data: { type: 'daily-sync', input: `${batch.length} UUIDs` } });
    await publish(attempt.id, { type: 'syncByUuid', uuids: batch });
    dispatched++;
  }
  for (const batch of chunk(identifiers, BATCH)) {
    const attempt = await db.syncAttempt.create({ data: { type: 'daily-sync', input: `${batch.length} identifiers` } });
    await publish(attempt.id, { type: 'syncByIdentifier', identifiers: batch });
    dispatched++;
  }

  console.log(`Queued ${dispatched} batch message(s) for ${products.length} product(s) (${uuids.length} UUID-based, ${identifiers.length} identifier-based).`);
  await closeConnection();
  process.exit(0);
}

run().catch(async e => { console.error(e); await closeConnection(); process.exit(1); });
