# Project Context

## What is this app?

A Symfony 7.4 product catalogue that syncs products from an Akeneo PIM instance and displays them. It runs on Upsun with PostgreSQL, Redis, and RabbitMQ.

---

## Main Features

- **Product catalogue** — paginated grid with lazy loading, category filter, and enabled/disabled status filter
- **Manual bulk sync** — an Akeneo Action UI extension ("Export to Marketplace") posts selected product IDs to the app, which fetches full product data (including images) from the Akeneo REST API
- **Automatic updates** — the app subscribes to Akeneo product-updated events via webhook (Akeneo Event Platform); updates are processed asynchronously via RabbitMQ
- **Image proxy** — product images are downloaded from Akeneo and stored locally; served through `/images/{filename}`
- **Stats dashboard** — live charts showing product counts by status, type, category, and sync activity over the last 14 days

---

## How Sync Works (end-to-end)

1. A user selects products in Akeneo PIM and triggers the "Export to Marketplace" Action UI extension.
2. The extension POSTs a signed JSON payload (HMAC-SHA256 via `X-Akeneo-Request-Signature`) with product UUIDs or identifiers to `POST /api/products/sync`.
3. `SyncController` validates the signature, dispatches a `ProductSyncMessage` to the `async` Messenger transport (RabbitMQ on Upsun, Doctrine on local dev), and immediately returns `202 Accepted`.
4. `ProductSyncHandler` (Messenger worker) calls `ProductSyncService`, which:
   - Fetches full product data from the Akeneo REST API (locale `en_US`, channel `ecommerce`)
   - Downloads the main product image (attribute configured via `AKENEO_IMAGE_ATTRIBUTE`) and saves it to `/var/product-images`
   - Upserts the product in PostgreSQL and invalidates the `catalogue` Redis cache tag

---

## How Auto-Update Works (end-to-end)

1. The Akeneo Event Platform sends a signed webhook (`POST /webhook/product-updated`) when a product is updated.
2. `WebhookController` validates dual HMAC signatures (`x-akeneo-signature-primary` / `x-akeneo-signature-secondary` for key rotation).
3. For each event in the payload, a `ProductUpdatedMessage` (containing the product UUID) is dispatched to RabbitMQ and `200 OK` is returned immediately.
4. `ProductUpdatedHandler` (Messenger worker) calls `ProductSyncService` to re-fetch and re-save the product — but only if the product already exists locally (no automatic addition of new products via webhook).
5. The Redis cache key for that product is invalidated.

---

## Key Environment Variables

| Variable | Purpose |
|---|---|
| `DATABASE_URL` | PostgreSQL connection string |
| `REDIS_URL` | Redis connection string (cache adapter) |
| `MESSENGER_TRANSPORT_DSN` | RabbitMQ AMQP DSN (or Doctrine for local dev) |
| `AKENEO_BASE_URL` | Base URL of the Akeneo PIM instance |
| `AKENEO_CLIENT_ID` | Akeneo API OAuth client ID |
| `AKENEO_CLIENT_SECRET` | Akeneo API OAuth client secret |
| `AKENEO_USERNAME` | Akeneo API username |
| `AKENEO_PASSWORD` | Akeneo API password |
| `AKENEO_IMAGE_ATTRIBUTE` | Akeneo attribute code used as the product image (default: `main_image`) |
| `AKENEO_LABEL_ATTRIBUTE` | Akeneo attribute code used as the product label (default: `name`) |
| `AKENEO_WEBHOOK_SECRET` | HMAC secret(s) for validating Event Platform webhook payloads (comma-separated for rotation) |
| `AKENEO_SYNC_SECRET` | HMAC secret(s) for validating Action UI extension sync requests (comma-separated) |

---

## Infrastructure

| Service | Type | Used for |
|---|---|---|
| PostgreSQL | `postgresql:15` | Product and category storage |
| Redis | `redis:7.2` | Cache adapter (product list pages) |
| RabbitMQ | `rabbitmq:3.12` | Async Messenger transport |

PHP extensions declared: `pdo_pgsql`, `redis`, `amqp`

Messenger worker: `messenger:consume async --time-limit=300` (auto-starts on Upsun)

Mount: `/var/product-images` (shared across app instances)

---

## Routes

| Method | Path | Name | Description |
|---|---|---|---|
| `GET` | `/` | `catalogue_index` | Product catalogue page (HTML) |
| `GET` | `/api/products` | `api_products` | Paginated product list (JSON), supports `page`, `category`, `enabled` query params |
| `DELETE` | `/api/products/{identifier}` | `api_product_delete` | Remove a product from the local catalogue |
| `POST` | `/api/products/sync` | `api_sync` | Receive product IDs from the Akeneo Action UI extension and queue a sync |
| `OPTIONS` | `/api/products/sync` | `api_sync_preflight` | CORS preflight for the sync endpoint |
| `POST` | `/webhook/product-updated` | `webhook_product_updated` | Receive product-updated events from the Akeneo Event Platform |
| `GET` | `/images/{filename}` | `product_image` | Serve a locally stored product image |
| `GET` | `/stats` | `stats_index` | Stats dashboard page (HTML) |
| `GET` | `/api/stats` | `api_stats` | Live stats JSON (totals, charts data) |
