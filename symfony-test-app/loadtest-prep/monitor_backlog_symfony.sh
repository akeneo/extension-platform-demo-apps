#!/bin/bash
# Monitoring loop for the 3.5-equivalent test (one-time backlog via app:daily-sync-simulate).
# Samples queue depth, CPU/memory, and Postgres connections every 3 minutes for 24 minutes
# (9 samples) -- same cadence as the Node investigation's 3.5 test.
#
# IMPORTANT: the "async" queue is named "messages_async" on Symfony (confirmed live),
# NOT "product-sync" like Node. Baseline queue depth is NOT zero on this app --
# there was a pre-existing, un-drained backlog of ~109,971 messages found idle since
# 2026-07-27T02:19:55Z (see RUNBOOK.md). Investigate/resolve that BEFORE running this,
# or numbers will conflate the old backlog with the new test's dispatch.
#
# Usage: bash monitor_backlog_symfony.sh [output_log_path]

CLI=akeneo-extension-platform
PROJ=gtipgifdob6ek
ENV=main
OUT="${1:-./samples_backlog.log}"

for i in $(seq 1 9); do
  TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  echo "===== SAMPLE $i at $TS =====" >> "$OUT"

  echo "--- queue depth ---" >> "$OUT"
  $CLI ssh -p $PROJ -e $ENV --app app -- 'curl -s -u guest:guest http://queue.internal:15672/api/queues | jq -r ".[] | \"\(.name) messages=\(.messages) ready=\(.messages_ready) unacked=\(.messages_unacknowledged) consumers=\(.consumers)\""' >> "$OUT" 2>&1

  echo "--- cpu/memory ---" >> "$OUT"
  $CLI metrics:cpu -p $PROJ -e $ENV --latest -s '*' --no-header -c service,used,percent >> "$OUT" 2>&1
  $CLI metrics:memory -p $PROJ -e $ENV --latest -s '*' --no-header -c service,used,percent >> "$OUT" 2>&1

  echo "--- postgres connections ---" >> "$OUT"
  $CLI db:sql -p $PROJ -e $ENV "SELECT count(*) AS total, count(*) FILTER (WHERE state='active') AS active FROM pg_stat_activity;" >> "$OUT" 2>&1

  echo "" >> "$OUT"
  if [ $i -lt 9 ]; then sleep 180; fi
done
echo "MONITORING DONE" >> "$OUT"
