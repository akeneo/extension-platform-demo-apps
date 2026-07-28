import * as syncService from '../services/productSyncService';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

async function run(): Promise<void> {
  const args = process.argv.slice(2);
  if (!args.length) {
    console.error('Usage: sync-now <uuid|identifier> [...]');
    process.exit(1);
  }

  const uuids       = args.filter(id => UUID_PATTERN.test(id));
  const identifiers = args.filter(id => !UUID_PATTERN.test(id));

  console.log(`Syncing ${uuids.length} UUID(s) and ${identifiers.length} identifier(s) directly…`);

  try {
    if (uuids.length) {
      const count = await syncService.syncProductsByUuid(uuids);
      console.log(`Synced ${count} product(s) by UUID.`);
    }
    if (identifiers.length) {
      const count = await syncService.syncProducts(identifiers);
      console.log(`Synced ${count} product(s) by identifier.`);
    }
    process.exit(0);
  } catch (e) {
    console.error(e);
    process.exit(1);
  }
}

run();
