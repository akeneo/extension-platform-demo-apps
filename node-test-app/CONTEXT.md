# Extension Platform Node.js Test App

Node.js/TypeScript port of the Symfony marketplace test app. Built to run side-by-side
on Upsun so the two stacks can be compared for cost, scaling, and resource usage.

## Stack

| Layer | Technology |
|---|---|
| Runtime | Node.js 22 |
| HTTP | Express 4 |
| Templates | Nunjucks (Twig-compatible) |
| ORM | Prisma 5 |
| Database | PostgreSQL 15 |
| Queue | BullMQ (Redis-backed) |
| Cache | ioredis with manual tag invalidation |

## Key architecture decisions vs the Symfony app

- **Queue transport**: BullMQ uses Redis for job storage (no `messenger_messages` table).
  Sync status is read from BullMQ job counts (`waiting`, `active`, `delayed`).
- **Cache invalidation**: Tag-aware cache simulated with Redis sets — each key is added to
  a per-tag set, and `cacheInvalidateTags` deletes all members of that set.
- **Token cache**: Akeneo access token stored directly in Redis with a 3500s TTL
  (matching the Symfony `AkeneoTokenProvider`).
- **Worker**: `src/workers/syncWorker.ts` → compiled to `dist/workers/syncWorker.js`,
  run as a separate Upsun worker container.
- **Raw body**: Express captures the raw request body before JSON parsing so HMAC
  signatures can be verified against the original bytes.

## Environment variables

```
DATABASE_URL         postgresql://... (set automatically by Upsun relationship)
REDIS_URL            redis://...      (set automatically by Upsun relationship)
AKENEO_BASE_URL
AKENEO_CLIENT_ID
AKENEO_CLIENT_SECRET
AKENEO_USERNAME
AKENEO_PASSWORD
AKENEO_IMAGE_ATTRIBUTE   comma-separated attribute codes
AKENEO_LABEL_ATTRIBUTE
AKENEO_DESCRIPTION_ATTRIBUTE
AKENEO_SYNC_SECRET       HMAC-SHA512 secret(s) for Action UI Extension, comma-sep
AKENEO_WEBHOOK_SECRET    HMAC-SHA256 secret(s) for Event Platform webhook, comma-sep
PRICE_API_URL
PRODUCT_IMAGES_DIR   /var/product-images
PORT                 3000
```

## Local dev

```bash
# Start PostgreSQL + Redis
docker compose up -d

# Install dependencies and generate Prisma client
npm install
npx prisma generate
npx prisma migrate deploy

# Run the app
npm run dev

# Run the worker (separate terminal)
npm run worker:dev
```
