'use strict';

// Symfony equivalent of the Node investigation's 3.4 test (sustained rate trial).
// Same methodology: persistent keep-alive connection, evenly-spaced dispatch,
// placeholder non-existent UUIDs (isolates ingress only, matches 3.1's approach,
// zero real PIM API cost). Rate defaults to 100/sec to match Akeneo's actual max
// send rate and be directly comparable to the Node result.
//
// Usage: node realistic_rate_test_v2_symfony.js <rate> <durationSec> <outputPath>
// Example (30 min @ 100/sec, matching Node's 3.4):
//   node realistic_rate_test_v2_symfony.js 100 1800 result.json

const https = require('https');
const crypto = require('crypto');
const fs = require('fs');

const RATE = parseFloat(process.argv[2] || '100');
const DURATION_SEC = parseFloat(process.argv[3] || '1800');
const OUTPUT_PATH = process.argv[4] || './result.json';

const HOST = 'main-bvxea6i-gtipgifdob6ek.eu-5.platformsh.site';
const REQ_PATH = '/webhook/product-updated';
const SECRET = process.env.AKENEO_WEBHOOK_SECRET || 'my-super-secret-primary-key-123456';
const TIMEOUT_MS = 5000; // Akeneo counts a 5s response timeout as a failure

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
  const uuid = crypto.randomUUID(); // non-existent placeholder, matches 3.1/Node-3.4 isolation approach
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

console.log(`Starting Symfony sustained trial: rate=${RATE}/sec, duration=${DURATION_SEC}s, output=${OUTPUT_PATH}`);
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
