-- Use the Symfony app's labels: one 'sync' for every Action Extension sync, 'webhook' for webhooks.
UPDATE "sync_attempt" SET "type" = 'sync' WHERE "type" IN ('syncByUuid', 'syncByIdentifier', 'syncByModelCode');
UPDATE "sync_attempt" SET "type" = 'webhook' WHERE "type" = 'productUpdated';
