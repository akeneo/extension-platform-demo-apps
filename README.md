# extension-platform-demo-apps

Holds demo apps for the extension platform feature.

Both apps implement the same integration pattern — an Akeneo PIM product catalogue synced via an Action UI extension and Event Platform webhooks — in two different stacks, so they can be compared side by side (features, architecture, cost, scaling).

## Apps

| App | Stack | Description |
|---|---|---|
| [`symfony-test-app`](./symfony-test-app) | Symfony 7.4, PostgreSQL, Redis, RabbitMQ | Product catalogue with manual bulk sync (Action UI extension), automatic updates (Event Platform webhook), image proxy, and a stats dashboard |
| [`node-test-app`](./node-test-app) | Node.js 22, Express, Prisma, PostgreSQL, BullMQ | Node.js/TypeScript port of the Symfony app, same features and sync flow |

See each app's own `CONTEXT.md` for architecture details, routes, and environment variables.

## How sync works (both apps)

1. **Manual bulk sync** — an Akeneo Action UI extension ("Export to Marketplace") posts selected product IDs to the app, which fetches full product data (including images) from the Akeneo REST API.
2. **Automatic updates** — the app subscribes to Akeneo product-updated events via webhook (Akeneo Event Platform); updates are processed asynchronously through a message queue (RabbitMQ / BullMQ).

Both endpoints validate an HMAC signature and both apps store synced products in PostgreSQL, with Redis used for caching.
