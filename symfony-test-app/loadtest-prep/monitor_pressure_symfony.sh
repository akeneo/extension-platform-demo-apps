#!/bin/bash
# Monitoring loop for the 3.6-equivalent test (worker backlog drain via real-UUID
# webhook pressure). Samples queue depth, CPU/memory, Postgres connections, and
# sync_attempt status/429-signature breakdown every 2 minutes for 30 minutes
# (15 samples) -- same cadence as the Node investigation's 3.6 test.
#
# IMPORTANT differences from the Node version:
#   - queue name is "messages_async", not "product-sync"
#   - sync_attempt.type for webhook-driven syncs is 'webhook', not 'productUpdated'
#     (confirmed live: existing rows show type IN ('daily-sync','daily-sync-simulate','sync','webhook'))
#   - Set CUTOFF below to the actual UTC timestamp just before you launch
#     webhook_pressure_test_symfony.js, so the sync_attempt query only counts
#     this test's rows, not the pre-existing ~108,247 completed 'webhook' rows
#     already in the table from prior sessions.
#
# Usage: bash monitor_pressure_symfony.sh [output_log_path]

CLI=akeneo-extension-platform
PROJ=gtipgifdob6ek
ENV=main
CUTOFF="2026-07-28T09:54:06Z"
OUT="${1:-./samples_pressure.log}"

for i in $(seq 1 15); do
  TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  echo "===== SAMPLE $i at $TS =====" >> "$OUT"

  echo "--- queue depth ---" >> "$OUT"
  $CLI ssh -p $PROJ -e $ENV --app app -- 'curl -s -u guest:guest http://queue.internal:15672/api/queues | jq -r ".[] | \"\(.name) messages=\(.messages) ready=\(.messages_ready) unacked=\(.messages_unacknowledged) consumers=\(.consumers)\""' >> "$OUT" 2>&1

  echo "--- cpu/memory ---" >> "$OUT"
  $CLI metrics:cpu -p $PROJ -e $ENV --latest -s '*' --no-header -c service,used,percent >> "$OUT" 2>&1
  $CLI metrics:memory -p $PROJ -e $ENV --latest -s '*' --no-header -c service,used,percent >> "$OUT" 2>&1

  echo "--- postgres connections ---" >> "$OUT"
  $CLI db:sql -p $PROJ -e $ENV "SELECT count(*) AS total, count(*) FILTER (WHERE state='active') AS active FROM pg_stat_activity;" >> "$OUT" 2>&1

  echo "--- sync_attempt status breakdown (this test only, type='webhook') ---" >> "$OUT"
  $CLI db:sql -p $PROJ -e $ENV "SELECT status, count(*) FROM sync_attempt WHERE type='webhook' AND queued_at > '$CUTOFF' GROUP BY status;" >> "$OUT" 2>&1

  echo "--- 429 signature check ---" >> "$OUT"
  $CLI db:sql -p $PROJ -e $ENV "SELECT count(*) FROM sync_attempt WHERE type='webhook' AND queued_at > '$CUTOFF' AND error ILIKE '%429%';" >> "$OUT" 2>&1

  echo "" >> "$OUT"
  if [ $i -lt 15 ]; then sleep 120; fi
done
echo "MONITORING DONE" >> "$OUT"
