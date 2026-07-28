# Load-test investigation handoff (Node app)

This file exists so a fresh Claude Code session opened in this folder picks up
where a prior session (anchored in the Symfony sibling repo) left off. Delete
or trim this file once the investigation described below is finished and
folded into permanent documentation.

## What this is

Mirroring a load-test/capacity investigation already completed on the Symfony
sibling app (`~/Projects/extension-platform-symfony-test-app`), now being
repeated on this Node.js app. Same integration pattern: Akeneo PIM <-> a
marketplace storefront, three sync triggers (Action UI Extension POST,
Event Platform webhook, nightly cron).

**Results live on Notion, both pages now exist and cross-reference each
other**:
- Node: https://app.notion.com/p/3a412ed8c59081bd878cc7a1ed26a304 (still
  tagged `[DRAFT]` — Option A/B scaling and a couple of break-tests remain)
- Symfony (reference/methodology + cross-app comparison notes in its own
  Appendix): https://app.notion.com/p/39e12ed8c590813aacbee4acbf2a006a

## Branch/worktree situation — read before touching anything

- This repo (`~/Projects/extension-platform-node-test-app`) was on
  `add_stock_feature` (a demo-prep branch) at the start of the investigation,
  but **the user explicitly asked to check out `main` here** partway through
  — it's on `main` now. `add_stock_feature` still exists as a branch
  (local + `upsun` remote), just not checked out; nothing on it was touched
  or discarded.
- Real code changes for this investigation are made in a **separate git
  worktree** checked out to `main` at
  `/home/julien-verbrugge/Projects/extension-platform-node-test-app-worktree`
  (branch `worktree/node-load-test`). Confirmed still present and in use.
  If it's ever gone, recreate with `git worktree add <path> -b <branch> main`.

## Deployment target

- **`5l73tu5p63wae`** ("Node test app") — same org as Symfony's
  `gtipgifdob6ek` (`01KR12BEXT9B6MJ0CV47DE38PA`, "Akeneo PaaS Internal
  Testing"). Manage via the `akeneo-extension-platform` CLI (the standard
  `upsun` CLI cannot reach this org's control plane); the same CLI also
  reaches `gtipgifdob6ek` directly if you ever need to inspect Symfony's
  live infra (e.g. `akeneo-extension-platform ssh -p gtipgifdob6ek -e main
  --app app -- '<cmd>'`).
- Push to Node directly by full refspec, no named remote needed:
  `git push 5l73tu5p63wae@git.eu-5.platform.sh:5l73tu5p63wae.git <local-branch>:main`
- Live URL: `https://main-bvxea6i-5l73tu5p63wae.eu-5.platformsh.site/`
- `AKENEO_WEBHOOK_SECRET`: `my-super-secret-primary-key-123456` — reconfirmed
  live via SSH mid-investigation (34 chars, unrotated since first noted).
- Real connected-PIM product count: **544** (Symfony's sandbox has 571 —
  different sandboxes, don't assume parity).

## Architecture findings (current `main`, verified by reading code directly)

- Webhook: `POST /webhook/product-updated`, `src/routes/webhook.ts`. HMAC-SHA256,
  dual headers `x-akeneo-signature-primary`/`-secondary`, comma-separated
  secret list, `crypto.timingSafeEqual`. Synchronous Prisma `syncAttempt.create`
  per event before responding (still present, unlike Symfony's fixed version),
  but AMQP publish uses a persistent, pooled connection from process start
  (`src/lib/queue.ts`) — so only one of Symfony's two original bottlenecks
  exists here.
- Concurrency model: plain single Node process per instance, no PHP-FPM-style
  fixed slot ceiling. Constraint under load is Postgres pool / AMQP channel
  serialization, not a hard slot count — confirmed by the 4.1 sweep (Node
  stays safely under Akeneo's 5s timeout even at c=100; Symfony's baseline
  misses it at c=100 due to `pm.max_children=5`).
- Worker: `src/workers/syncWorker.ts`, RabbitMQ consumer, **`prefetch(2)`**
  — 2 concurrent jobs per instance. **This is a hardcoded literal in the
  worker file, not an infra setting** — trivially changeable, but nothing
  downstream is currently sized for higher concurrency (see "Concurrency
  limits anatomy" below).
- **Worker renamed `sync` → `messenger`** partway through the investigation,
  for naming parity with Symfony's `app--messenger` — commit `7b8f832` in
  the worktree, pushed and redeployed; live as `app--messenger` now,
  confirmed via `resources:get`. All docs/diagrams updated to match.
- `src/lib/apiCallTracker.ts`: AsyncLocalStorage-based per-job API call
  counter (needed specifically because of the concurrency-2 worker).
- Daily sync: `src/commands/dailySync.ts`, real cron at 02:00, batches of 50.
- `src/commands/dailySyncSimulate.ts` — added for this investigation
  (mirrors Symfony's `app:daily-sync-simulate --count=N`), pushed to
  `5l73tu5p63wae`'s `main` (commit `fc59fbd`). Invoke via SSH:
  ```
  export DATABASE_URL="postgresql://${DATABASE_USERNAME}:${DATABASE_PASSWORD}@${DATABASE_HOST}:${DATABASE_PORT}/${DATABASE_PATH}"
  export RABBITMQ_URL="${QUEUE_SCHEME:-amqp}://${QUEUE_USERNAME:-guest}:${QUEUE_PASSWORD:-guest}@${QUEUE_HOST:-localhost}:${QUEUE_PORT:-5672}/${QUEUE_PATH}"
  node dist/commands/dailySyncSimulate.js --count=N
  ```
- No 429/backpressure logic in either app's webhook controller — confirmed
  by grep, neither codebase has any rate-limit/capacity check.
- Neither app throttles outbound Akeneo API calls — Node's
  `priceService`/`stockService` already fire fully concurrent `Promise.all`
  per batch, uncapped. Node's Prisma `DATABASE_URL` has no explicit
  `connection_limit` set either (Postgres server allows 100 connections
  total, checked live — comfortable today, but worth pinning down before
  raising worker concurrency further).

## Cross-app baseline comparison & key findings (session 2)

Full baseline numbers, both apps (Node's Option A/B not yet run):

**4.1 webhook concurrency, p95, single-event / 10-event-bulk:**
| c | Symfony single | Node single | Symfony bulk | Node bulk |
|---|---|---|---|---|
| 5 | 252ms | 248ms | 268-413ms | 533ms |
| 10 | 269ms | 285ms | 338-514ms | 542ms |
| 25 | 1.01s | 336ms | 672ms-1.50s | 621ms |
| 50 | 2.18s | 799-1698ms | 1.77-1.91s | 780-1514ms |
| 75 | 3.5-3.6s | 2.01-2.05s | 4.58-4.97s | 1.05-1.89s |
| 100 | **7.4-7.8s (misses 5s timeout)** | 2.02-2.06s | **4.73-5.35s (borderline)** | 2.12-2.14s |

**4.2 webhook-driven sync (300 real products), baseline:** Symfony
374s / 0.802 products/sec / ~8 API calls per product. Node 191.95s / 1.56
products/sec / 8.49 API calls per product.

**4.3 daily-sync sweep, baseline:** Symfony ~1.55-1.6 products/sec across
500/5k/10k. Node ~2.64-3.05 products/sec across the same sizes.

**Why Node's p95 stays low at c=100 while Symfony's blows past 5s**:
verified live, Symfony's `pm.max_children=5` (PHP-FPM pool config, no
override found in the repo — looks like Upsun's own auto-calculation from
the instance's 224MB RAM) is a hard per-instance concurrency ceiling.
Node's event loop has no equivalent slot count.

**Why Node is ~1.9-2x faster on both 4.2 and 4.3 despite near-identical
API-calls-per-product**: checked how each worker actually consumes its
queue. Symfony's is started as `php bin/console messenger:consume async
--time-limit=300` — the `ConsumeMessagesCommand` has *no* concurrency
option at all (checked its full option list: limit, failure-limit,
memory-limit, time-limit, sleep, bus, queues, no-reset, all,
exclude-receivers, keepalive — nothing like a prefetch), and
`Worker::run()`'s core loop (`$receiver->get()` then `handleMessage()`)
is fully synchronous/blocking — one message, start to finish, before the
next `get()`. **This isn't a config value OR a code constant — it's
structural**, a consequence of PHP's synchronous single-threaded execution
model plus Messenger's `Worker` class design. The only way to get more
concurrent processing is more whole `messenger:consume` *processes*
(exactly what Option A=3 / Option B=6 instances already are — scaling by
instance count is the *only* lever available, there's no cheaper one).
Node's `prefetch(2)` gives it 2x per-instance concurrency "for free" by
comparison, which lines up closely with the ~2x baseline throughput gap.

**The webhook-vs-daily-sync throughput finding (Node only)**: webhook
sync (1.56/sec) is slower than daily-sync (3.0/sec) on the *same* worker,
*same* instance count, *same* `prefetch(2)`. Not a slot-ceiling story —
it's batching. Daily-sync's queue messages are 50-product batches, so the
worker's 2 concurrent slots are each amortizing category/parent/price/stock
overhead across 50 products at once (effectively ~100 products' worth of
work in flight, mostly-shared cost). Webhook's messages are 1 product each
(`webhook.ts` creates one message per event, even within a 10-event bulk
call) — the same 2 slots each carry the *full* per-item overhead for just
1 product apiece. Same concurrency setting, radically different effective
throughput, because what's inside each concurrent slot differs. The
"false parity trap" to avoid: don't assume "Node has no PHP-FPM ceiling,
so all its paths scale uniformly" — the webhook path's limit here is a
batching-granularity problem, invisible to and unaffected by the ingress
concurrency picture in 4.1. Scaling `app` wouldn't move this number at
all; only scaling `app--messenger` (or batching the webhook path itself)
would.

**Could webhook events be batched?** Yes, technically, in both apps — group
same-call events (up to 10, the bulk-edit max) into one batch message
instead of N individual ones. Now documented in both pages' Appendix.
The real tradeoff, confirmed by reading both `persistBatch` implementations
directly: **neither has per-item try/catch** — a single bad product in a
batch aborts the *whole* batch, forcing all of it to retry as a unit (up
to `MAX_RETRIES`/`max_retries` = 3) before failing wholesale. Today's
batch-size-1 webhook design isolates failures to just the affected
product. Batching trades some throughput for a bigger failure blast
radius — not an unambiguous win. Not implemented or tested.

**Does implementing 429/backpressure raise the ceiling?** No — clarified
explicitly because it's easy to conflate. 429 signaling doesn't make
processing faster; it tells Akeneo's Event Platform to *slow its send
rate down* (adaptive, 1-100 events/sec) instead of silently building an
unbounded backlog. It's a protective mechanism against overshooting an
*existing* limit, not a way to increase that limit. Raising the actual
ceiling needs more resources (bigger/more instances) or more efficient
code (fewer API calls/product, batching) — orthogonal to 429 handling.

## Test methodology (mirrors Symfony)

- `ab` against `/webhook/product-updated`, HMAC-SHA256 signed with a
  placeholder non-existent UUID (isolates ingress only), sweeping
  concurrency 5/10/25/50/75/100 at 1-event and 10-event payload shapes.
  Rerun noisy levels (established this session: c=50/75 showed real
  run-to-run variance, c=100 was consistently stable both times).
- Webhook-driven sync (4.2): real, already-catalogued product UUIDs (not
  placeholders), 30 calls × 10 events, HMAC-signed, dispatched with ~10
  concurrent `curl` calls, then poll `sync_attempt` (`type='productUpdated'`)
  until every row is terminal — **watch for shell pipeline bugs when
  polling**: piping the check through `tail`/`head` for readability
  silently swallows the real exit code (`until ... | tail -3; do sleep;
  done` exits after the first check, always, since `tail` always exits 0)
  — caused one false "test finished" notification this session before the
  bug was caught. Poll the raw command's exit code directly, no pipe.
- Resource configs: baseline done for both apps. Symfony's Option A (2
  app / 3 messenger) and Option B (3 app / 6 messenger) were done in the
  prior investigation. Node's equivalents (Option A: app=2/messenger=3,
  Option B: app=3/messenger=6) are **not yet applied** — user scales via
  Console UI, don't attempt it via CLI.

## Progress as of this note

- [x] Architecture analyzed, both apps
- [x] Node baseline resource config confirmed (`app`=1, `app--messenger`=1,
      renamed from `app--sync`)
- [x] Node webhook concurrency sweep at baseline, both shapes, full p95
      numbers with reruns on noisy levels
- [x] Node webhook-driven sync volume/duration at baseline (real products,
      never tested before this session)
- [x] Node daily-sync-simulate at count=500/5,000/10,000, all 0 failures
- [x] Node baseline cost (145 credits — matches Symfony's baseline exactly,
      same resource footprint)
- [x] Cross-app baseline comparison done and explained (see above) —
      user understood everything through the concurrency-limits anatomy
- [x] Batching tradeoff documented in both pages' Appendix
- [x] **`app` (ingress) break-test, both apps** — ramped `ab` concurrency
      100→200→300→400, 3min sustained per step, against
      `/webhook/product-updated` with a placeholder non-existent UUID
      (same isolation approach as the 4.1 baseline sweep). Results:

      **Node** (clean data, after fixing a methodology bug — see below):
      | c | complete | failed | fail% | p50 | p95 | p99 | max | req/s |
      |---|---|---|---|---|---|---|---|---|
      | 100 | 7378 | 0 | 0% | 1135ms | 6314ms | 17042ms | 43952ms | 40.99 |
      | 200 | 6878 | 41 | 0.60% | 1704ms | 19123ms | 50606ms | 136090ms | 38.21 |
      | 300 | 6490 | 82 | 1.26% | 2193ms | 35187ms | 85304ms | 133240ms | 36.05 |
      | 400 | 6994 | 94 | 1.34% | 2153ms | 37588ms | 84429ms | 149176ms | 38.85 |

      No "Non-2xx responses" at any level — every completed request got a
      genuine success response; all "failed" are TLS/connection-layer
      (SSL handshake failures, mid-response resets), never app-level
      4xx/5xx. Throughput plateaus at ~36-41 req/s regardless of
      concurrency 100→400 — Node doesn't error out under sustained
      overload, it queues (p95/p99/max balloon) while accepting almost
      everything eventually. No hard break found up to c=400 sustained
      3min; matches the architecture note that Node has no hard
      per-instance slot ceiling like Symfony's `pm.max_children`.
      Sustained p95 at c=100 (6.3s) is ~3x worse than the original 4.1
      burst-test p95 at the same concurrency (2.02-2.06s) — sustained
      load matters even where a hard ceiling doesn't exist.

      **Methodology bug hit and fixed**: the Node ramp and the Symfony
      agent's parallel run both used the *same shared scratchpad path*
      for their signed payload file. The Symfony agent overwrote Node's
      `payload.json` mid-test, so Node's c=200/300/400 steps signed one
      body but sent a different (Symfony-shaped) one — HMAC mismatch,
      401 "Invalid signature" on ~100% of requests. This looked exactly
      like a catastrophic break (it wasn't) — caught by checking the
      access log's status-code timeline, which showed a suspiciously
      clean 200→401 flip exactly at a concurrency-step boundary rather
      than a load-correlated degradation. Fixed by isolating each app's
      test files in separate directories; **rerun this in isolated dirs
      per app whenever two break-tests run concurrently again.** Also
      hit mid-test: this session's sandbox was suspended for ~15.5
      real hours between two tool calls, which silently corrupted `ab`'s
      own `-t 180` timer for the c=200 step (it thinks 55569s elapsed,
      stops almost instantly) — reran that step alone to get clean data.

      **Symfony**: `pm.max_children=5` bites immediately — p95 already
      27.3s at sustained c=100 (vs the 4.1 burst-test's 7.4-7.8s), and
      failure rate (also TLS/connection-layer only, never app 4xx/5xx)
      climbs with concurrency: 0.31%→1.95%→6.26% at c=100/200/300, before
      dropping to 2.91% at c=400 (likely `ab` client-side connection-pool
      contention at very high concurrency rather than a further
      server-side regression). p99/max latency exceeds 130s at c≥200 —
      the queue backs up minutes deep and never drains inside the
      3-minute window.

      **Cross-app takeaway**: Node's failure rate stays under 1.5% even
      at c=400 sustained; Symfony's reaches 6%+ by c=300. Neither app
      produced app-level errors — both apps' "failures" under this test
      are purely TLS/connection-layer, consistent with connections timing
      out or resetting before the app instance gets to respond, not with
      Postgres pool exhaustion or an application crash (checked Node's
      `app.log` for the test window — no exceptions/OOM messages, though
      the log has no timestamps so this isn't fully conclusive; worth a
      closer look with `--tail` live next time rather than after the
      fact).
- [ ] **`app--messenger` (worker) break-test, both apps** — not yet run.
      A much larger sustained backlog (e.g. `daily-sync-simulate
      --count=100000`, or many more webhook-driven syncs than the worker
      can drain), watched over time on the worker + database instances
      specifically, decoupled from ingress entirely.
      Rationale for testing baseline first, before Option A/B: user wants
      to understand where Node's bottleneck actually sits before scaling
      (Node's bottleneck may not be the same resource as Symfony's, per
      the false-parity-trap finding above — don't assume parity).
      User's own observation going in: no resource has come close to
      saturating yet (Node: app RAM 44%, messenger RAM 52%, app storage
      61%; Symfony: app RAM 60%, messenger RAM 40%, app storage 63%) —
      current test volumes (≤10k products, brief bursts ≤c=100) are
      realistic-customer-sized, not calibrated to break anything. Storage
      in particular is suspected to be mostly baseline app footprint
      rather than load-driven, since `daily-sync-simulate` cycles the same
      544/571 real products regardless of N (same images re-fetched, not
      accumulated) — worth confirming via a `du -sh` breakdown before
      assuming more test volume would grow it.
- [ ] Node resource scaling (Option A/B) — not yet requested from the user,
      intentionally deferred until after break-tests are done
- [ ] Node Associated costs for Option A/B — TBD, blocked on scaling
- [x] Event Platform suspension-risk capacity investigation — root cause
      found (TLS/connection-setup overhead, not DB/queue/CPU/RAM — all
      ruled out by direct measurement), real Event Platform delivery
      behavior confirmed from its own source (persistent connections,
      concurrent+rate-limited, default 50/sec, max 100/sec, no batching),
      realistic ceiling found at ~140-150 events/sec with 100% success at
      Akeneo's actual max rate of 100/sec. See full section above.
- [ ] 1-hour sustained trial at 100/sec — in progress, confirms whether the
      45s snapshot holds for the real rolling-hour suspension window
- [ ] Multiple-concurrent-subscriptions scenario (combined rate could
      exceed the per-subscription 100/sec cap) — not tested this session

## Event Platform suspension-risk capacity investigation (session 3)

**Goal**: turn the break-test data into a concrete customer-facing statement:
"with this resource config, you risk Event Platform subscription suspension
if event volume reaches X." Suspension criteria (confirmed via
https://api.akeneo.com/event-platform/key-platform-behaviors.html plus an
internal note for the one detail not in the public page): rolling **last
hour** window, minimum **500 events** in that window before a subscription
is even eligible for evaluation (internal note, not in public docs),
suspends when success rate drops **below 90%**. A **5-second response
timeout counts as a failure** toward that rate (confirmed in the public
docs, not just carried over from the earlier 4.1 assumption), as do 4xx/5xx.
A 429 only counts as a failure if the event stays undelivered for **more
than 1 hour** — not relevant here since neither app sends 429s.

**Root cause of the app-tier capacity ceiling — both original suspects
ruled out by direct measurement, not inference:**
- **Not Postgres**: watched `pg_stat_activity` live during a 38/sec burst —
  total connections stayed flat at 21 the entire time, never more than 1-3
  in `active` state. No pool growth, no queueing there at all.
- **Not RabbitMQ**: wrote a raw publish+confirm micro-benchmark
  (`amqp_bench.js`, run directly on the `app` instance via
  `NODE_PATH=/app/node_modules`) — 2222 publishes/sec sequential (fully
  awaited, matching how `queue.ts` actually uses it), 11764/sec pipelined.
  Nowhere close to being the bottleneck.
- **Actually TLS/connection-establishment overhead**: `ab` without
  keep-alive plateaus at ~37-45 req/s regardless of concurrency (matches
  the break-test table above). `ab -k` (reused connections) jumped to
  **356-418 req/s with zero failures up to c=150** — an 8x difference from
  reusing connections alone. Neither DB, queue, app, nor messenger CPU/RAM
  came anywhere near saturation during any of this (checked via
  `metrics:cpu`/`metrics:memory` for the exact burst windows — app CPU
  topped out at ~15-18% of its 0.5 vCPU, messenger ~19-21%, router CPU hit
  ~31-35% of its own tiny 0.05 vCPU allocation, the highest relative
  utilization of any tier but still not maxed).

**How Akeneo's Event Platform actually delivers events — read directly
from source, not assumed**, at `~/Projects/event-platform` (separate local
repo, this is the sender, not either subscriber app):
- Persistent/keep-alive connections: a single shared `http.DefaultClient`
  is built once at process startup and reused for every delivery
  (`internal/delivery/services.go:91-119`, `internal/delivery/executors/https.go:33-45,140`).
  Go's default transport keeps 2 idle conns/host but opens as many
  *concurrent* ones as actually needed — it does **not** cap concurrent
  connections, only idle/spare ones.
- Delivery is concurrent, not serialized through one connection — each
  event runs on its own goroutine (`internal/delivery/handlers/api/job.go`),
  gated only by a rate limiter, not a connection pool.
- Rate limiter models rate as **time-spacing** (interval = 1/rate seconds
  between dispatch decisions), not a fixed concurrency count —
  `internal/infrastructure/ratelimiter/ratelimiter.go:30-39` (default 50/sec,
  range 1-100/sec), `.../distributed/distributed.go:29-118`. Increases on
  200, decreases on 429 (`job.go:233-259,335`). **No batching** — one event
  per HTTP request, confirmed by grep.

**First attempt at a "realistic" test had a methodology bug of its own —
worth remembering**: an open-loop Node script (`realistic_rate_test.js`,
v1) fired all R requests as one synchronous burst every 1000ms rather than
spacing them evenly. This produced an apparent catastrophic collapse (30/s
→ 90.2% success, 40/s → 55.6%, worsening further at higher rates, with
`maxConcurrentSockets` ballooning into the thousands) that looked like a
real, worse-than-before capacity wall. It wasn't — caught by checking the
per-request latency trend within a single run: latency climbed steadily
from ~220ms to ~2800ms over the 45s window, the signature of an
artificially bursty arrival pattern overwhelming the process once a second
(thundering-herd-every-tick), not a genuine sustained-rate limit. Real
Akeneo traffic is time-spaced per the source above, not bursty.

**Corrected methodology (v2)**: `realistic_rate_test_v2.js` — one request
every `1000/rate` ms (smooth spacing, matching the real rate-limiter model)
over a persistent `https.Agent({keepAlive: true, maxFreeSockets: 2})`
(matching Go's default transport behavior). Results, 45s sustained per
rate:

| events/sec | success rate |
|---|---|
| 30 | 99.78% |
| 50 | 100% |
| **100 (Akeneo's actual max rate)** | **100%** |
| 110 | 98.81% |
| 120 | 92.50% |
| 130 | 92.50% |
| 140 | 92.54% |
| 150 | 70.30% |
| 200-700 | 56-76% (degraded but doesn't collapse to near-zero) |

The real ceiling sits **between 140 and 150 events/sec** under realistic
traffic — a soft slope down from 100%, not a hard wall. Akeneo's own rate
limiter hard-caps at 100/sec max, which sits with real margin under this
ceiling (clean 100% at exactly 100/sec). **The earlier 36-38 events/sec
cliff (in the break-test table above) is still a valid measurement, but it
only applies to a "fresh TLS connection per request" traffic pattern that
does not reflect how Akeneo actually sends events** — keep it documented
as a footnote/edge case (relevant if some other client hits this endpoint
without connection reuse), not as the customer-facing number.

**Important caveat not yet tested**: this all assumes a single Event
Platform subscription pointed at this webhook. The rate limiter key is
per-subscription (`ratelimiter:subscription:<id>`) — a customer running
**multiple concurrent subscriptions** to the same endpoint could see a
combined effective rate above the per-subscription 100/sec cap, potentially
into the 100-150/sec zone where the margin shrinks. Not tested this
session.

**Stopped early, rerun Monday**: 1-hour sustained trial at rate=100/sec
(v2 methodology) to confirm the clean 45s snapshot holds for the *actual*
rolling-hour window the suspension rule uses, not just a short burst. User
stopped it partway through (needed to close the computer) — no output file
was written (the script only writes results at the very end), so there's
nothing to salvage; just rerun from scratch: `node
realistic_rate_test_v2.js 100 3600 <output path>`. Script itself may no
longer exist (scratchpad is session-ephemeral) — recreate from this file's
description above if needed, or from the session transcript.

**Question queued for Monday (user's own note)**: user wants an
explanation of what "traffic pattern" means in general — flagged after I
used the phrase "a traffic pattern (fresh connection every time) that
doesn't match how Akeneo actually sends events" and realized they didn't
have the underlying concept. Quick answer for whoever picks this up: a
"traffic pattern" is just the *shape* of how requests arrive at the app —
how many, how fast, whether connections get reused or re-opened each time,
whether they arrive in bursts or evenly spaced. It matters because the
same "events per second" number can produce wildly different results
depending on the pattern behind it — that's exactly what happened this
session: ~37 events/sec collapsed the app when every event opened a fresh
connection, but ~140-150 events/sec was fine once connections were reused
and evenly paced, matching how Akeneo's real client actually behaves. Give
a fuller walkthrough Monday if the user wants more than this summary.

**Tooling note**: all scripts (`amqp_bench.js`, `realistic_rate_test.js`,
`realistic_rate_test_v2.js`, the various sweep shell scripts) live in the
session's scratchpad directory, not the repo — ephemeral, will not survive
to a future session unless copied somewhere durable.

## How to pick this back up

`app` (ingress) break-tests are done for both apps (see results above) —
next up is the `app--messenger` (worker) break-test, both apps, still
decoupled from ingress. Neither app's ingress tier produced a hard,
confirmed failure mode (both apps' errors were TLS/connection-layer, not
app-level) — Node degrades via queueing/latency with no clear ceiling
found up to c=400 sustained, Symfony's `pm.max_children=5` visibly
throttles it much harder at the same concurrency levels. This should get
folded into both Notion pages' "Break-test baseline" section once done.
Once the worker break-test results are in too, they'll clarify the *real*
bottleneck per app (which may differ between apps — don't assume the
same resource is the ceiling for both), which should directly inform
whether Option A/B scaling is even the right fix, or whether something
else (DB pool tuning, webhook batching, 429 signaling) matters more first.

**When running both apps' break-tests in parallel again**: give each
app's test its own isolated scratch directory/payload file — a shared
path collision corrupted the first Node run this session (see
methodology-bug note above) and looked like a false catastrophic failure.
