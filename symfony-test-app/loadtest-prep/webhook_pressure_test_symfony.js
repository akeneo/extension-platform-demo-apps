'use strict';

// Symfony equivalent of the Node investigation's 3.6 test (worker backlog drain
// via webhook, continuous overload). Sends real product UUIDs (not placeholders)
// so the worker does genuine per-product work, at a rate durably above the
// worker's known drain rate (~0.802 products/sec baseline, from 4.2). Records
// webhook response latency continuously, to see whether a growing backlog
// slows the webhook down.
//
// Default rate 5/sec is ~6x the ~0.8/sec drain rate, matching the ratio used
// in the Node 3.6 test (10/sec vs ~1.56/sec). Adjust if the baseline drain
// rate is confirmed different at run time.
//
// Usage: node webhook_pressure_test_symfony.js <rate> <durationSec> <uuidFile> <outputPath>
// Example (15 min @ 5/sec, matching Node's 3.6 ratio):
//   node webhook_pressure_test_symfony.js 5 900 real_uuids.txt pressure_result.json

const https = require('https');
const crypto = require('crypto');
const fs = require('fs');

const RATE = parseFloat(process.argv[2] || '5');
const DURATION_SEC = parseFloat(process.argv[3] || '900');
const UUID_FILE = process.argv[4] || './real_uuids.txt';
const OUTPUT_PATH = process.argv[5] || './pressure_result.json';

const HOST = 'main-bvxea6i-gtipgifdob6ek.eu-5.platformsh.site';
const REQ_PATH = '/webhook/product-updated';
const SECRET = process.env.AKENEO_WEBHOOK_SECRET || 'my-super-secret-primary-key-123456';
const TIMEOUT_MS = 5000;

const uuids = fs.readFileSync(UUID_FILE, 'utf8')
  .split('\n')
  .map(l => l.trim())
  .filter(l => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(l));

if (!uuids.length) {
  console.error('No valid UUIDs loaded from', UUID_FILE);
  process.exit(1);
}

const agent = new https.Agent({ keepAlive: true, maxFreeSockets: 2 });
const intervalMs = 1000 / RATE;

let sent = 0;
let succeeded = 0;
let failed = 0;
let timedOut = 0;
const statusCounts = {};
const latencies = [];
const startedAt = Date.now();
let stopped = false;
let timer = null;
let uuidIdx = 0;

function snapshot() {
  const elapsedSec = (Date.now() - startedAt) / 1000;
  const sorted = [...latencies].sort((a, b) => a - b);
  const pct = (p) => sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))] : null;
  return {
    rate: RATE,
    targetDurationSec: DURATION_SEC,
    elapsedSec: Math.round(elapsedSec),
    sent, succeeded, failed, timedOut,
    successRatePct: sent ? +(100 * succeeded / sent).toFixed(2) : null,
    statusCounts,
    latencyMs: { p50: pct(0.5), p95: pct(0.95), p99: pct(0.99), max: sorted.length ? sorted[sorted.length - 1] : null },
    complete: stopped,
    updatedAt: new Date().toISOString(),
  };
}

function writeSnapshot() {
  fs.writeFileSync(OUTPUT_PATH, JSON.stringify(snapshot(), null, 2));
}

function sendOne() {
  const uuid = uuids[uuidIdx % uuids.length];
  uuidIdx++;
  const body = JSON.stringify({
    action: 'product.updated',
    event_id: crypto.randomUUID(),
    data: { product: { uuid } },
  });
  const signature = crypto.createHmac('sha256', SECRET).update(body).digest('hex');
  const reqStart = Date.now();
  let settled = false;

  const req = https.request({
    hostname: HOST,
    path: REQ_PATH,
    method: 'POST',
    agent,
    timeout: TIMEOUT_MS,
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
      'x-akeneo-signature-primary': signature,
    },
  }, (res) => {
    if (settled) return;
    settled = true;
    latencies.push(Date.now() - reqStart);
    statusCounts[res.statusCode] = (statusCounts[res.statusCode] || 0) + 1;
    if (res.statusCode >= 200 && res.statusCode < 300) succeeded++;
    else failed++;
    res.resume();
  });

  req.on('timeout', () => {
    if (settled) return;
    settled = true;
    timedOut++;
    failed++;
    statusCounts['timeout'] = (statusCounts['timeout'] || 0) + 1;
    req.destroy();
  });

  req.on('error', () => {
    if (settled) return;
    settled = true;
    failed++;
    statusCounts['error'] = (statusCounts['error'] || 0) + 1;
  });

  req.write(body);
  req.end();
  sent++;
}

function finish() {
  if (stopped) return;
  stopped = true;
  clearInterval(timer);
  writeSnapshot();
  const s = snapshot();
  console.log(`DONE sent=${s.sent} succeeded=${s.succeeded} failed=${s.failed} timedOut=${s.timedOut} successRate=${s.successRatePct}%`);
  process.exit(0);
}

console.log(`Starting Symfony webhook pressure test: rate=${RATE}/sec, duration=${DURATION_SEC}s, real UUIDs=${uuids.length}, output=${OUTPUT_PATH}`);
timer = setInterval(sendOne, intervalMs);

const progressTimer = setInterval(() => {
  writeSnapshot();
  const s = snapshot();
  console.log(`[${s.elapsedSec}s] sent=${s.sent} succeeded=${s.succeeded} failed=${s.failed} timedOut=${s.timedOut} successRate=${s.successRatePct}% p50=${s.latencyMs.p50} p95=${s.latencyMs.p95} p99=${s.latencyMs.p99}`);
}, 30000);

setTimeout(() => {
  clearInterval(progressTimer);
  setTimeout(finish, TIMEOUT_MS + 2000);
}, DURATION_SEC * 1000);

process.on('SIGINT', () => {
  clearInterval(progressTimer);
  finish();
});
