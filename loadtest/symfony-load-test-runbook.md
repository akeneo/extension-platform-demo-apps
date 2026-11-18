# Symfony load-test parity prep — run tomorrow morning

> Moved here from the Symfony app on 2026-11-18. The scripts now read their target
> from environment variables (see README.md). For this app: `export
> APP_HOST=main-bvxea6i-gtipgifdob6ek.eu-5.platformsh.site PROJ=gtipgifdob6ek` and
> `AKENEO_WEBHOOK_SECRET` from the app's environment variables.

## STATUS (2026-07-28): all three tests done, Notion updated, one rerun pending

All three tests below were run on 2026-07-28. Results are already written into
https://app.notion.com/p/39e12ed8c590813aacbee4acbf2a006a (sections 3.4-3.6,
4.4-4.6, and the Appendix). Headline findings:

- **Test 1 (3.4, sustained rate trial)**: severe collapse. 65.19% raw success
  rate at 100 events/sec (vs. Node's 100%), true effective rate estimated
  33-62% after correcting for the 5s-timeout caveat. Confirms `pm.max_children=5`
  is a hard ceiling independent of connection pattern — Akeneo's real,
  favorable traffic doesn't rescue this.
- **Test 2 (3.5, one-time 20k backlog)**: clean. ~1.66 products/sec drain,
  zero failures, no resource strain. Fully drained by ~11:00 (~3.3h after
  the 07:41 dispatch).
- **Test 3 (3.6, webhook pressure, 5/sec, real UUIDs)**: DONE, rerun completed
  2026-07-29 with verified tight timing (monitor's first sample landed 26s
  after launch, not hours later). Peak backlog 3,774 messages, webhook
  latency stayed flat (p50=46ms, p95=55ms) throughout — backpressure
  hypothesis refuted, matching Node. One bounded, non-leaking `app` RAM rise
  during injection (94.5→126.7 MiB, recovered after). All in Notion now —
  no further action needed on Test 3.

**Also found (unrelated, informational, now documented in Notion's Appendix)**:
`gtipgifdob6ek`'s RabbitMQ has an orphaned duplicate queue, `messages_async`
(vs. the real, actively-consumed `messages` queue) — harmless to real
processing, just wasted RAM on the `queue` service, growing with every
publish. Not urgent, noted for whoever wants to clean it up eventually.

## Side topic, noted for later — not investigated further (2026-07-29)

While checking on the natural post-test drain, found 3 `sync_attempt` rows
(type='webhook') that failed with `HTTP 503: no healthy upstream` from
Akeneo's own API — a genuine transient third-party outage, unrelated to our
test load (happened ~37 min after injection had already stopped). Not a
concern for this investigation's conclusions.

What's worth a closer look eventually: these 3 UUIDs are "sentinel" products
that get touched on a recurring ~11-minute cadence going back to July 15th —
some mechanism unrelated to our own testing (unidentified — not our
injection scripts, which cycle through all 571 products every ~2 minutes,
not just these 3). The failed attempts recovered only on that mechanism's
*next natural cycle* ~12.5 minutes later, not within the ~7 seconds
Messenger's configured retry_strategy (1s/2s/4s, max_retries=3) should have
taken if it fired. Code review confirmed the retry config is correctly wired
(handler rethrows, no exception-type exclusions) — so in principle retries
should happen — but there's no DB evidence of the fast retries actually
occurring. Open question: does Messenger's retry_strategy actually work for
this handler, or would a genuine one-off webhook event (no natural
recurrence to fall back on) have silently failed permanently under the same
transient 503? Not investigated further per the user's call — this is a
side topic, unrelated to the main PHP-FPM/backlog findings.

**Critical difference from last time**: immediately after launching both,
check `samples_pressure_2.log` within 1-2 minutes to confirm Sample 1's
timestamp is close to `$CUTOFF`, not hours later. If it's delayed again,
something structural is going on with how this environment schedules a
second concurrent background process — investigate before trusting the
data, don't just let it run and assume it's fine.

Once confirmed, update Notion's 4.6 section and the 3.6 Appendix note with
the real queue-depth-over-time curve and CPU/RAM-during-peak-backlog figures,
replacing the "rerun planned" callout with the actual data.

---



Prepared 2026-07-27 in a same-day session with no memory of prior context beyond
this file. Goal: bring the Symfony Notion doc up to parity with the Node one,
which now has three tests Symfony's doc doesn't: an Event-Platform-pattern
sustained rate trial (3.4), a one-time worker backlog drain (3.5), and a
worker backlog drain driven through the webhook with real products (3.6).
Also: add a caveat to Symfony's existing 4.1 section, noting its `ab` sweep
uses a connection pattern less favorable than Akeneo's real traffic.

Node's finished page (for structure/wording reference):
https://app.notion.com/p/3a412ed8c59081bd878cc7a1ed26a304

Symfony's page to update:
https://app.notion.com/p/39e12ed8c590813aacbee4acbf2a006a

## STOP — read this first: the worker looks stalled

> **Resolved:** the worker was not stalled. The exchange `messages` copies every
> message into two queues: `messages`, which the worker reads, and `messages_async`,
> which nothing reads. Symfony's worker polls instead of subscribing, so RabbitMQ shows
> 0 consumers on both. The backlog below was in `messages_async`. Confirmed again on
> 2026-11-18 with `messenger:stats`.

Found live on 2026-07-27 (~16:00 UTC), NOT yet investigated further per the
user's own choice at prep time (deliberately deferred to be handled first
thing tomorrow, not overnight):

- RabbitMQ queue `messages_async` has **109,971 messages**, **0 consumers**,
  `idle_since: 2026-07-27T02:19:55Z` — roughly 13.5+ hours of zero consumer
  activity as of when this was checked.
- `app--messenger` shows minimal CPU (~1.3%) and modest RAM (~56 MiB) — looks
  idle, not busy draining anything.
- Worker command (`.upsun/config.yaml`): `messenger:consume async
  --time-limit=300 --memory-limit=128M` — it's *supposed* to exit every 5
  minutes and have Platform.sh's worker supervisor restart it automatically.
  Zero consumer activity for 13.5+ hours means either it stopped being
  restarted, or it's crash-looping without recovering.
- The backlog volume is large enough (~110k) that it's likely leftover from
  the *prior* investigation's own testing (Option A/B sweeps, 10k-product
  daily-sync runs — matches `sync_attempt` counts: `webhook`=108,247
  completed, `daily-sync-simulate`=930 completed), not something new.

**Do this first, before running any new test below**: check
`akeneo-extension-platform ssh -p gtipgifdob6ek -e main --app app--messenger
-- tail -n 100 /var/log/app.log` (or equivalent) for crash/restart evidence,
and confirm whether the worker actually resumes consuming on its own. If
it's genuinely stuck, running new tests on top will just add to an already
un-drained pile and won't tell you anything clean about *these* tests'
behavior. Whether/how to restart or clear it is a call for whoever runs
this — not decided as part of this prep.

## What's already confirmed, so you don't have to re-derive it

- Project ID: `gtipgifdob6ek`, environment `main`
- Live URL: `https://main-bvxea6i-gtipgifdob6ek.eu-5.platformsh.site/`
- `AKENEO_WEBHOOK_SECRET`: read it from the app's environment variables (same
  value as the Node app) and export it before running the load-test scripts.
- Webhook route: `POST /webhook/product-updated`, payload shape
  `{"data": {"product": {"uuid": "..."}}}`, signature header
  `x-akeneo-signature-primary` (HMAC-SHA256)
- Currently at baseline resources: `app`=1, `app--messenger`=1 (confirmed live)
- Real product count: 571. Real UUID-pattern identifiers already pulled into
  `real_uuids.txt` in this folder (571 lines).
- `sync_attempt` table columns match Node's exactly (`id`, `started_at`,
  `finished_at`, `attempts`, `api_calls`, `count`, `queued_at`, `type`,
  `status`, `input`, `error`) — but **`type` for webhook-driven syncs is
  `'webhook'`, not `'productUpdated'`** like Node. The monitoring scripts
  already account for this.
- RabbitMQ queue name is `messages` (single shared queue for webhook +
  daily-sync + Action Extension messages). `messages_async` is an unread copy of it.
- `app:daily-sync-simulate --count=N` exists and works exactly like Node's
  version: dispatches batches of 50 and returns immediately, doesn't block
  for processing. No manual env-var export needed to invoke it — `DATABASE_URL`
  and `QUEUE_DSN` are already live env vars on the app instance (confirmed),
  unlike Node which needed the export dance. Just:
  ```
  akeneo-extension-platform ssh -p gtipgifdob6ek -e main --app app -- \
    'php bin/console app:daily-sync-simulate --count=20000'
  ```
- Historical baseline throughput (from the existing Notion doc, already
  documented): webhook path ~0.802 products/sec, daily-sync path ~1.55-1.6
  products/sec (varies by size tested).
- `pm.max_children=5` per `app` instance — a **hard** concurrent-request
  ceiling, unlike Node's soft/queueing behavior. This is architecturally
  important for the sustained-rate test below: rough estimated ceiling from
  baseline latency (~250-300ms/request ÷ 5 slots) is **~16-20 req/sec
  sustained**, well under Akeneo's 100/sec max. Expect the 3.4-equivalent
  test to show meaningfully worse results than Node's, even with the more
  favorable connection pattern — that's a real, useful finding if confirmed,
  not a test-design flaw.

## Test 1 — 3.4 equivalent: sustained rate trial

Matches Node's 3.4 exactly for direct comparability: 100 events/sec, 30
minutes, placeholder (non-existent) UUIDs — zero real PIM API cost, isolates
ingress only.

```bash
cd ~/Projects/extension-platform-apps/extension-platform-demo-apps/loadtest
node realistic_rate_test_v2.js 100 1800 result_3_4.json > run_3_4.log 2>&1 &
```

Run in background, check `run_3_4.log` every few minutes. Expect this to
reveal real degradation (possibly well below the 90% suspension threshold)
given the `pm.max_children=5` ceiling — that's the point of running it.

## Test 2 — 3.5 equivalent: one-time backlog via daily-sync-simulate

Same count as Node (20,000) for direct comparability. Given Symfony's slower
drain rate (~1.55-1.6/sec vs Node's ~2.7-3/sec), full drain takes roughly
3.5-3.6 hours — only actively monitor ~24 minutes, let the rest drain in the
background, same pattern as Node's 3.5.

**First**, capture a fresh baseline (queue depth, Postgres connections) —
note the stalled-worker backlog above means baseline is NOT zero; record
whatever it actually is right before dispatching, so you can compute the
delta caused by this test specifically:

```bash
akeneo-extension-platform ssh -p gtipgifdob6ek -e main --app app -- \
  'curl -s -u guest:guest http://queue.internal:15672/api/queues | jq -r ".[] | \"\(.name) messages=\(.messages) consumers=\(.consumers)\""'
```

Then dispatch and monitor:

```bash
akeneo-extension-platform ssh -p gtipgifdob6ek -e main --app app -- \
  'php bin/console app:daily-sync-simulate --count=20000'

bash monitor_backlog.sh samples_backlog.log &
```

## Test 3 — 3.6 equivalent: worker backlog drain via webhook (real UUIDs)

Rate defaults to 5/sec in the script (~6x the ~0.8/sec webhook drain rate,
same ratio as Node's 10/sec vs ~1.56/sec). 15 min injection + 15 min
recovery-watch, same structure as Node's 3.6.

**Before launching**, export `CUTOFF` set to
the current UTC timestamp (`date -u +%Y-%m-%dT%H:%M:%SZ`) — this filters the
`sync_attempt` queries to just this test's rows, since there are already
~108,247 completed `'webhook'`-type rows in the table from prior sessions.

```bash
cd ~/Projects/extension-platform-apps/extension-platform-demo-apps/loadtest
export CUTOFF=$(date -u +%Y-%m-%dT%H:%M:%SZ)

node webhook_pressure_test.js 5 900 real_uuids.txt pressure_result.json > pressure_run.log 2>&1 &
bash monitor_pressure.sh samples_pressure.log &
```

Watch for the same two things Node's 3.6 checked: does webhook p95 latency
correlate with backlog depth (tests the RabbitMQ-backpressure hypothesis,
already refuted on Node at ~7,400 messages — worth seeing if it holds here
too, especially given PHP-FPM's harder ceiling), and any `429`-signature
sync failures (Symfony's `AkeneoApiClient` — check whether it has the same
"no rate-limit awareness" gap as Node's before assuming; not yet checked
this session).

## After all three: update Notion

1. Add 3.4/4.4, 3.5/4.5, 3.6/4.6 sections to
   https://app.notion.com/p/39e12ed8c590813aacbee4acbf2a006a, mirroring the
   wording/structure already used on the Node page's equivalent sections —
   fetch the Node page first for exact phrasing patterns to reuse.
2. Add a caveat to the existing 3.1/4.1 sections: the `ab` sweep there uses a
   fresh-connection-per-request pattern, not Akeneo's real (persistent,
   evenly-paced) traffic — same clarification already added to Node's 3.1.
   Reference Node's Appendix "How this compares to 3.1's concurrency sweep"
   note for the wording pattern.
3. Note the stalled-worker discovery somewhere sensible (Appendix or a new
   callout) regardless of how it gets resolved — it's a real operational
   fact worth having on record, separate from whatever today's new tests show.
4. Once both docs are aligned, the user's second ask from the same session
   was a customer-facing "credits → capability" sub-page under the Node
   page — check whether that's already been built before starting it fresh.
