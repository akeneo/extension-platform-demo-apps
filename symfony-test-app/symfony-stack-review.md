# Symfony App — Stack Review & Load-Test Monitoring (2026-07-10)

Context: reviewing the Symfony app's stack/configuration ahead of PIM-side load tests —
mass edits triggering webhook events per edit, and mass sync via the Action Extension.
Goal: understand app/infra limits and how to measure platform cost impact (Upsun pricing
calculator) before making any changes.

---

## 1. Stack review and expected behavior under load

**Prompt:**
> let's take time to review the stack of the symfony app and its configuration
> i am doing some tests on the pim and i want to see how the app is handling these tests
> i am doing mass edit on the pim that triggers events for each edit
> i am doing mass sync using the action extension
> solo edit is not a real chalenge as i am interested in the app and infra limits

**Answer:**

I read through the full stack — `.upsun/config.yaml`, Messenger config, the webhook and Action Extension controllers, and the message handlers. Here's the architecture as it stands, and what it means for the two load patterns you're about to hammer it with.

### Current stack

- **Web**: Symfony 7.4 / PHP 8.3, Upsun `type: php:8.3` — standard request-per-invocation runtime (no FrankenPHP worker mode configured, no persistent app state between requests).
- **DB**: Postgres 15, single instance, no read replica.
- **Cache**: Redis 7.2 (catalogue cache only, not used for queuing).
- **Queue**: RabbitMQ 3.12, single instance.
- **Messenger**: **one transport (`async`) carries both message types** — `ProductUpdatedMessage` (webhook) and `ProductSyncMessage` (Action Extension + daily cron) share the same queue.
- **Worker**: `messenger:consume async --time-limit=300 --memory-limit=128M`, **no `instances:` key anywhere** in config.yaml → exactly one worker process, and I confirmed earlier (tracing `Worker.php`/`Connection::get()`) that it processes **one message at a time**, full stop — no concurrency, and `prefetch_count` has no effect in this Symfony version.
- **Retry**: 3 retries, exponential backoff (1s/2s/4s), on the `async` transport.
- No rate limiter, no request throttling on either ingress endpoint.
- No `instances:`/`container_profile:`/`size:` anywhere — both `app` and `app--messenger` run on whatever Upsun's default sizing is.

### What happens under your two scenarios

**Mass PIM edits → webhook flood:** Each `/webhook/product-updated` POST is fast at the HTTP layer for a single event (HMAC check, one INSERT+flush, one dispatch to RabbitMQ). But if Akeneo batches several edits into one webhook call, `WebhookController::handle()` loops and does `persist()`+`flush()` **per event, sequentially, inside that one request** — no transaction wrapping the loop. A large batch could plausibly approach `max_execution_time=30` (set in `php.ini`), and if it does time out, whatever was already flushed+dispatched before the cutoff stays committed — so an Akeneo-side retry of that "failed" webhook call would **reprocess and re-dispatch events that already succeeded**, since there's no idempotency check on event ID.

**Mass Action Extension sync:** One POST creates up to 3 `SyncAttempt` rows (UUIDs/identifiers/model codes), each dispatched as **one message carrying the entire batch**. `persistBatch()` only calls `$this->em->flush()` once, at the end — so if the batch throws partway through (product 300 of 500, say), **nothing in that attempt is committed**, and the automatic retry re-fetches and re-processes the whole batch from Akeneo again.

**The part that matters most for your test — running both at once:** since webhook and Action Extension messages share the same queue and the same single-threaded consumer, a big Action Extension sync will **fully occupy the one worker for its entire duration**, during which webhook-triggered updates from your mass edits just sit in `queued` state, and vice versa. There's no isolation between the two load sources you're deliberately combining.

### What to watch on the Logs page while you test

- **`queued` count climbing and staying high** — expected; it confirms the single-consumer bottleneck rather than a crash. Use the pagination/sort-oldest-first to find how far back the queue is.
- **`attempts` field creeping above 1** (visible in the raw API response, not yet a table column) — signals transient retries in flight.
- Whether any `webhook` or `sync` rows land in **`failed`** with a signature/timeout-shaped error — would point at the `max_execution_time` risk above.
- **API calls per attempt** — should stay consistent with the per-product breakdown worked out earlier; a spike would suggest retried batches re-doing work.

Offered to fix any of this (split webhook/sync onto separate transports, wrap the webhook loop in a transaction, add worker `instances`, add webhook event-id idempotency) — but the user wanted to measure impact first before making changes.

---

## 2. Messaging system explained (RabbitMQ / Messenger basics)

**Prompt:**
> [pasted the Queue/Messenger/Worker/Retry summary from above]
> i am no expert on the messaging system, could you explain a bit more

**Answer:**

### The basic idea: decoupling "receive" from "process"

Without a queue, your web server would have to fully process each webhook/sync request before responding — slow, and a burst of requests would overwhelm it directly. A message queue breaks this into two independent halves:

1. **Producer** — your web app, when it receives a webhook or Action Extension call. It doesn't do the actual work; it just writes a small message ("go sync product X") onto a queue and immediately returns a response. This is fast and cheap.
2. **Consumer** ("worker") — a separate, long-running process that continuously pulls messages off that queue, one at a time, and does the real work (calling Akeneo's API, downloading images, writing to Postgres).

RabbitMQ is the thing sitting in between holding those messages durably (on disk) until a consumer picks them up. The benefit: the web tier stays fast and can absorb a burst instantly, and the queue acts as a buffer/shock-absorber while the consumer works through the backlog at its own pace.

### What a "transport" and "queue" actually are here

In Symfony Messenger, a "transport" is just the configured connection to a message broker — in `messenger.yaml`, `async` is the name given to "the RabbitMQ connection." Concretely, on RabbitMQ's side, that transport corresponds to **one physical queue** (think of it as one named line/conveyor belt).

Two different kinds of messages get routed onto that same conveyor belt:
- `ProductUpdatedMessage` — created by a webhook call
- `ProductSyncMessage` — created by an Action Extension sync or the daily cron

Because `messenger.yaml`'s routing config points *both* to `async`, they don't get separate belts — they interleave on the same one, in the order they arrive.

### What "one worker, one message at a time" means

The worker is a single running PHP process (`messenger:consume async ...`). Picture it as **one cashier at a single checkout line**, not a bank of checkout lines. The cashier:
1. Asks RabbitMQ "give me the next message"
2. RabbitMQ hands over exactly one
3. The cashier fully processes it (Akeneo API calls, DB writes — this can take seconds for a single product, much longer for a big batch)
4. Only once that's done does the cashier ask for the next one

There is no second cashier, and this one cashier cannot pick up a second message while working on the first. That's true even if the queue has 10,000 messages waiting — they just form a line.

**Why "prefetch" would normally help, and why it doesn't here:** In many queue systems, you can tell RabbitMQ "hand this consumer up to N unacknowledged messages at once" (called prefetch/QoS). If your consumer is written in an async language (like Node), it can then work on several of those N messages concurrently while waiting on network calls — that's exactly what the Node app does (`prefetch(2)`). PHP's Messenger consumer, however, executes synchronously line-by-line — even if RabbitMQ handed it 2 messages, the PHP process would just process the first fully, then the second, one after another. There's no PHP-side event loop to interleave them. So prefetch is a no-op for concurrency here (Symfony actually removed the config option entirely for this reason) — the only way to get real parallelism is to run a **second cashier**: a second worker process (another `instances:` count), each with its own connection, each independently pulling from the same queue.

### What retry/backoff actually does

When the handler throws an exception, Messenger doesn't just drop the message — it re-queues it with an increasing delay: 1 second, then 2 seconds, then 4 seconds (delay × multiplier² per your config), for up to 3 attempts. Mechanically, RabbitMQ implements this "wait N seconds, then redeliver" using a **dead-letter-exchange + message-TTL** trick (a message sits in a temporary holding queue for exactly N seconds, then automatically dead-letters back into the real queue) — this is the same pattern hand-built manually for the Node app since `amqplib` doesn't have this built in.

Important nuance for the test: retries **also** go through the same single cashier. A message that's waiting out its 2-second retry delay isn't blocking the worker during that wait — but the moment it comes back, it competes for the same one processing slot as everything else.

### Why "no rate limiter" is worth naming, but not necessarily alarming

Nothing on the ingress (`/webhook/product-updated`, `/api/products/sync`) caps how many requests per second the web tier will accept. In most queue-backed architectures that's actually *fine by design* — RabbitMQ is built to absorb bursts durably, so a flood of incoming requests just makes the queue longer, not the system crash. The two places this *can* still bite: (1) the web tier itself has to have enough capacity to accept and write a `SyncAttempt` row for every incoming request as it arrives, even before that request's actual work is queued/processed; (2) Postgres and RabbitMQ have finite disk — an unbounded backlog isn't infinite in practice, just larger than most demo scenarios will ever hit.

### Putting it together for the test

When firing off mass PIM edits (webhooks) and an Action Extension mass sync at roughly the same time: both produce messages onto the *same* conveyor belt, and there is exactly *one cashier* working through it, strictly in arrival order (with retries occasionally cutting back in after their delay). If the Action Extension sync's message is a single big batch, that one message occupies the cashier for its *entire* processing time — every webhook-triggered message behind it just waits, however long that takes. That's the mechanism behind the "queued count grows and stays high" behavior to watch for.

---

## 3. Investigating Upsun's monitoring options for cost impact

**Prompt:**
> before doing any modification, i would like to be able to mesure the impact of such synchro on the platform itself
> i am particularly interested in calulated the impacts linked to costs on the platform https://upsun.com/pricing/calculator/
> for example what is the origin bandwidht and orign reques
> for each service, worker and app, waht is the consumed cpu ram and storage
> i would like to be able to see if i need to app workers, add more storage, more cpu ram or instances of each components
> can you investigate the possiblities offerd by upsun to help monitoring that ?

**Answer:**

Investigated. Here's what Upsun actually exposes, split by what maps to which part of the pricing calculator.

### CPU / RAM / storage per app, worker, and service — available via CLI, live

The `upsun` CLI (confirmed by reading actual command help, not just docs) has two relevant command families:

**`resources:get`** — the *static provisioned allocation* per component (what you're paying for, regardless of usage):
```
upsun resources:get -e main
```
Returns one row per app/worker/service (`app`, `app--messenger`, `database`, `cache`, `queue`) showing `cpu`, `memory`, `disk`, `instance_count`, `profile_size`. This is the direct input for the calculator's CPU/RAM profile + instances + disk fields.

**`metrics:all` / `metrics:cpu` / `metrics:memory` / `metrics:disk-usage`** — *actual live consumption*, per component, e.g.:
```
upsun metrics --range 1h -s app,app--messenger,database,cache,queue
```
Gives `cpu_percent`, `mem_percent`, `disk_percent` plus raw used/limit, at **1-minute granularity**. Retention: live for the last 8 hours on standard Grid plans (up to 30 days if the org has the Observability Suite add-on). Filterable by `--service` or `--type`, so `app--messenger` can be isolated specifically during a sync test.

This is exactly the tool for the "do I need more CPU/RAM/instances" question — run `metrics:cpu -s app--messenger --range 15m -i 1m` while a mass sync is draining the queue, and it'll show directly whether the single worker process is CPU-pegged (→ needs more CPU per instance) or idling between messages (→ needs more instances/concurrency, not more power per instance).

### Origin bandwidth / origin requests — Console only, no CLI/API

Confirmed via search of the full `upsun` CLI command list (grepped for http/traffic/bandwidth/billing/usage — nothing). Two different views in Console instead:

- **"HTTP metrics" tab** (per environment): request-status-code distribution, inbound/outbound bandwidth graph, top-10 URL impact by traffic. 1-minute measurements, retained live for 8h (24h with Observability Suite). Grid environments only, and **excludes CDN traffic**. Best near-real-time view during an actual test window.
- **"Traffic this month"** (project level): the literal Origin Bandwidth (TB) and Origin Requests (millions) numbers that feed the pricing calculator's edge/CDN section — but this updates **once daily**, not live, so isolating "just today's test" means diffing two daily snapshots, not watching it move in real time.

Both let you set consumption alerts/thresholds, but there's no way to export or script-pull either — Console UI only.

### Mapping this to the calculator

| Calculator input | Source | Access |
|---|---|---|
| CPU/RAM profile, instances, disk (GB) | `resources:get` | CLI |
| Actual utilization (to judge if a bigger profile is warranted) | `metrics:cpu/memory/disk-usage` | CLI, live, 1-min granularity |
| Origin bandwidth (GB, €0.03/GB over 10GB free) | "Traffic this month" | Console, daily |
| Origin requests (K, €1.00/100k over 500k free) | "Traffic this month" | Console, daily |

### One more thing worth knowing: Blackfire is already wired up

Deploy logs showed "Blackfire configured for applications app and app--messenger" — `blackfire` is in the runtime extensions list. Blackfire profiles individual requests/CLI executions for CPU time, memory, I/O wait, broken down by function call. It won't give cost directly, but it's the right tool to answer *why* a component is CPU- or memory-heavy before deciding to just throw more resources at it.

### Suggested workflow for the test

1. Before starting: `resources:get` (baseline provisioning) + note the current daily "Traffic this month" figures.
2. During the test: watch `metrics:cpu`/`metrics:memory` on `app` and `app--messenger` live (short `--range`, e.g. 15m, `-i 1m`), and the Console's HTTP metrics tab for request/bandwidth shape.
3. After: re-check "Traffic this month" the next day to get the delta, and pull `metrics:all --range 8h` before the live window expires if reviewing afterward instead of watching live.

---

## 4. Reading the actual `resources:get` output (initial mix-up)

**Prompt:**
> app-sync is the worker ?
> ```
> +----------------+------+----------+-----+-------------+-----------+-----------+
> | App or service | Size | CPU type | CPU | Memory (MB) | Disk (MB) | Instances |
> +----------------+------+----------+-----+-------------+-----------+-----------+
> | app            | 0.5  | shared   | 0.5 | 224         | 512       | 1         |
> | app--sync      | 0.5  | shared   | 0.5 | 224         | N/A       | 1         |
> | cache          | 0.5  | shared   | 0.5 | 1088        | N/A       | 1         |
> | database       | 0.5  | shared   | 0.5 | 1408        | 512       | 1         |
> | queue          | 0.5  | shared   | 0.5 | 1408        | 512       | 1         |
> +----------------+------+----------+-----+-------------+-----------+-----------+
> ```

**Answer:**

Good catch to double check — but that output is very likely **not** the Symfony app. Look at the worker name: `app--sync`. Symfony's `.upsun/config.yaml` names its worker key `messenger` (`workers: messenger: commands: ...`), which would produce a service named **`app--messenger`**, not `app--sync`.

The Node app, on the other hand, names its worker key `sync` (`workers: sync: commands: ...`) — that matches exactly. So this `resources:get` output looked like it came from the **Node app's project** (`5l73tu5p63wae` or `mgdgxjbtxuxbu`), not Symfony's (`gtipgifdob6ek`/`oekdyzea5pr46`).

Notable in these numbers regardless of which app, since the "do I need more workers/storage/CPU/RAM/instances" question applies the same way to both:

- **Everything is on the smallest shared-CPU tier** (0.5 CPU, shared type) — including the database and queue services, not just the app/worker. Under sustained load, "shared" CPU means time-slicing with other tenants on the host; that's the first thing worth escalating to "guaranteed" if `metrics:cpu` shows high usage with unstable/throttled-looking spikes rather than a clean plateau.
- **The worker has less RAM than the DB, cache, or queue** (224 MB vs. 1088–1408 MB). For a single-process worker downloading product images and holding a batch's data in memory during one large sync message, 224 MB is the tightest ceiling in the whole stack.
- **The worker shows `N/A` disk** — expected, not a gap: workers don't get their own disk allocation on Upsun, they share whatever mounts the parent `app` definition declares.
- **`app`/`database`/`queue` each have only 512 MB disk.** For Symfony specifically, persisted product images live on a separate mount (`/var/product-images`) that draws from this same disk quota — worth watching via `metrics:disk-usage` if the mass-sync test pulls down a lot of new images.
- **Everything is `Instances: 1`** — confirms what was already known from the config file: exactly one worker process, so the "one message at a time" bottleneck is real and not offset by any hidden replica.

---

## 5. Confirmed: this is the Symfony project's actual allocation

**Prompt:**
> you are right this is the one from the symfony project
> ```
> +----------------+------+----------+-----+-------------+-----------+-----------+
> | App or service | Size | CPU type | CPU | Memory (MB) | Disk (MB) | Instances |
> +----------------+------+----------+-----+-------------+-----------+-----------+
> | app            | 0.5  | shared   | 0.5 | 224         | 512       | 1         |
> | app--messenger | 0.5  | shared   | 0.5 | 224         | N/A       | 1         |
> | cache          | 0.5  | shared   | 0.5 | 1088        | N/A       | 1         |
> | database       | 0.5  | shared   | 0.5 | 1408        | 512       | 1         |
> | queue          | 0.5  | shared   | 0.5 | 1408        | 512       | 1         |
> +----------------+------+----------+-----+-------------+-----------+-----------+
> ```

**Answer:**

Good, that confirms the analysis applies directly — same numbers, correct service (`app--messenger`). Exact commands scoped to this project's real components, ready to run during the next test:

**Full live snapshot, all 5 components, 1-minute samples over the last 15 minutes:**
```
upsun metrics --range 15m -i 1m -s app,app--messenger,database,cache,queue -e main
```

**Worker memory — the tightest ceiling (224 MB), watch this first:**
```
upsun metrics:memory -s app--messenger --range 15m -i 1m -e main
```
If this climbs toward 100% during a big Action Extension batch (hundreds of products + image downloads held in one message's execution), that's the worker capacity answer right there — and an OOM kill here would surface as stalled/retried messages, not an obvious error.

**CPU across everything — since it's all shared-tier, worth checking for throttling-shaped spikes, not just headline percent:**
```
upsun metrics:cpu -s app,app--messenger,database,queue --range 15m -i 1m -e main
```

**Disk — two different risks, so worth checking separately:**
```
upsun metrics:disk-usage -s app --range 1h -i 5m -e main      # product-images mount filling up
upsun metrics:disk-usage -s queue --range 1h -i 5m -e main    # RabbitMQ backlog piling up on disk during the flood
```

**Single latest reading across all 5, if just a quick gut-check without picking a range:**
```
upsun metrics --latest -s app,app--messenger,database,cache,queue -e main
```

Practical suggestion: run the full snapshot command once right before starting the test, then re-run the memory and disk ones every minute or two while the mass edits/sync are actively running (a simple loop with `sleep` works fine since retention is live for 8h). That'll tell you concretely whether the bottleneck is worker memory, disk fill-up, or just the single-instance throughput ceiling already established — rather than guessing from the static `resources:get` table alone.

---

## 6. Interpreting a `metrics:memory` sample — and what "router" is

**Prompt:**
> ```
> +---------------------------+----------------+-----------+-----------+--------+
> | Timestamp                 | Service        | Used      | Limit     | Used % |
> +---------------------------+----------------+-----------+-----------+--------+
> | 2026-07-10T15:45:00+00:00 | app            | 94.5 MiB  | 224.0 MiB | 42.2%  |
> | 2026-07-10T15:45:00+00:00 | app--messenger | 53.3 MiB  | 224.0 MiB | 23.8%  |
> | 2026-07-10T15:45:00+00:00 | cache          | 10.2 MiB  | 1.1 GiB   | 0.9%   |
> | 2026-07-10T15:45:00+00:00 | database       | 53.9 MiB  | 1.4 GiB   | 3.8%   |
> | 2026-07-10T15:45:00+00:00 | queue          | 130.5 MiB | 1.4 GiB   | 9.3%   |
> | 2026-07-10T15:45:00+00:00 | router         | 18.7 MiB  | 128.0 MiB | 14.6%  |
> ```
> here is an example of result for the metrics:memory command, currently there is not sync running on the app
> can you tell me what is the router service and why my app and app-messenger cpu are always 42% and 23%

**Answer:**

Two things to untangle here — a units mix-up and a genuine question.

### This table is memory, not CPU

The command run was `metrics:memory` (the columns — Used/Limit in MiB/GiB, Used %, no CPU column — confirm this), so **42.2% and 23.8% are RAM usage, not CPU**. Worth being precise about this because the diagnosis is different: high idle memory usage is about baseline footprint, while high CPU would mean something is actively computing. Nothing here tells us about CPU load at all — that would need `metrics:cpu` instead, and with no sync running it would be expected near-idle.

### Why app / app--messenger sit at a nonzero memory baseline even with nothing running

This is expected, not a leak or a problem. **93.5 MiB for `app`** and **53.3 MiB for `app--messenger`** aren't "sync work in progress" — they're the fixed cost of a **long-running, fully-bootstrapped Symfony process sitting idle, ready to handle the next request/message**. Specifically:

- The config sets `opcache.preload: config/preload.php`, which deliberately front-loads the *entire* compiled dependency-injection container and class metadata into memory once at process start — this is a performance optimization (avoids re-parsing/re-compiling PHP on every request) that trades permanent baseline RAM for faster requests. That's a big chunk of `app`'s 93.5 MiB.
- `app--messenger` is a single long-lived worker process (`messenger:consume`) that boots the Symfony kernel, Doctrine ORM metadata, and HTTP client **once** and keeps them resident so it doesn't re-bootstrap for every message — same tradeoff, smaller footprint since it's not also carrying the web-request stack.
- Open, kept-alive connections to Postgres/Redis/RabbitMQ each hold a small amount of memory too.

None of this moves when idle — it's a floor, not a leak. The useful number isn't this baseline in isolation, it's **how much it grows above baseline under load**: `app` has ~130 MiB of headroom before hitting its 224 MiB limit, `app--messenger` has ~171 MiB. Worth re-running the same command mid-test and comparing the delta, not the raw percentage.

### What "router" is

It's an Upsun-managed component, not something provisioned in `.upsun/config.yaml` — **every environment automatically gets exactly one router container**, which sits in front of the app(s) and does the actual work described by the `routes:` block (the `https://{all}/` → `upstream: app:http` mapping). It terminates TLS, applies the `expires: 1h` caching header set on the `/` location, handles the plain-HTTP→HTTPS redirect, and forwards everything else to `app`. That's why it didn't show up in `resources:get` (not sized directly) but does show up in `metrics:memory` (still a real container consuming real resources) — its 18.7 MiB / 14.6% is just routing overhead, not application code.
