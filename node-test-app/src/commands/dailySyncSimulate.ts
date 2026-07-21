import { db } from '../lib/db';
import { publish, closeConnection } from '../lib/queue';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const BATCH = 50;

function chunk<T>(arr: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
  return out;
}

function cycle<T>(arr: T[], count: number): T[] {
  const out: T[] = [];
  for (let i = 0; i < count; i++) out.push(arr[i % arr.length]);
  return out;
}

async function run(): Promise<void> {
  const countArg = process.argv.find(a => a.startsWith('--count='));
  const count = countArg ? parseInt(countArg.split('=')[1], 10) : NaN;

  if (!count || count <= 0) {
    console.error('Usage: daily-sync-simulate --count=N');
    process.exit(1);
  }

  const products = await db.product.findMany({ select: { identifier: true } });

  if (!products.length) {
    console.log('No products in catalogue — nothing to simulate.');
    process.exit(0);
  }

  const identifiers = products.map(p => p.identifier);
  const simulated = cycle(identifiers, count);

  const uuids = simulated.filter(id => UUID_PATTERN.test(id));
  const idents = simulated.filter(id => !UUID_PATTERN.test(id));

  let dispatched = 0;

  for (const batch of chunk(uuids, BATCH)) {
    const attempt = await db.syncAttempt.create({
      data: { type: 'daily-sync-simulate', input: `${batch.length} UUIDs (simulated)` },
    });
    await publish(attempt.id, { type: 'syncByUuid', uuids: batch });
    dispatched++;
  }
  for (const batch of chunk(idents, BATCH)) {
    const attempt = await db.syncAttempt.create({
      data: { type: 'daily-sync-simulate', input: `${batch.length} identifiers (simulated)` },
    });
    await publish(attempt.id, { type: 'syncByIdentifier', identifiers: batch });
    dispatched++;
  }

  console.log(
    `Queued ${dispatched} batch message(s) for ${simulated.length} simulated product(s), cycled from ${products.length} real product(s) (${uuids.length} UUID-based, ${idents.length} identifier-based).`
  );
  await closeConnection();
  process.exit(0);
}

run().catch(async e => {
  console.error(e);
  await closeConnection();
  process.exit(1);
});
