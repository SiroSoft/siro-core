// k6 load test script — SiroERP staging (real app, FPM + nginx)
// Run one load level at a time:
//   BASE_URL=http://127.0.0.1:8080 API_KEY=<key> VUS=10  k6 run scripts/loadtest-k6.js
//   BASE_URL=http://127.0.0.1:8080 API_KEY=<key> VUS=50  k6 run scripts/loadtest-k6.js
//   BASE_URL=http://127.0.0.1:8080 API_KEY=<key> VUS=200 k6 run scripts/loadtest-k6.js
// Install: k6 v2.4.0 (https://github.com/grafana/k6/releases, linux-amd64 tarball
//          extracted to /usr/local/bin/k6; the dl.k6.io apt repo only lists <= 2.4.0
//          via the Packages file as well).
//
// Endpoints (all GET, read-only):
//   GET /                       — root health-check, no DB, no auth (pure framework)
//   GET /health                 — public, SELECT 1 DB ping (boot + router + DB)
//   GET /api/v2/products?limit=5 — API-key auth + scope products.view + app
//                                 RateLimitMiddleware + ProductController index
//                                 (DB read). Requires API_KEY env (X-Api-Key header);
//                                 skipped with a warning when unset.
// The staging copy raises the v2 throttle cap (throttle:120,1 -> throttle:100000,1)
// in ITS OWN routes/api-v2.php so the measurement is framework throughput, not the
// 120/min app cap; the middleware still runs every request (row lookup + atomic
// increment), so its cost is included. See scripts/loadtest-report-1.5.0.md.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8080';
const API_KEY = __ENV.API_KEY || '';
const VUS = parseInt(__ENV.VUS || '10', 10);
const STEADY = __ENV.STEADY || '30s';

// Custom metrics
const latencyRoot = new Trend('root_latency_ms');
const latencyHealth = new Trend('health_latency_ms');
const latencyProducts = new Trend('products_latency_ms');
const errorRate = new Rate('api_errors');

export const options = {
  // k6's default trend stats are avg/min/med/max/p(90)/p(95) only;
  // handleSummary() reads p(50) and p(99), so declare them explicitly.
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(50)', 'p(90)', 'p(95)', 'p(99)'],
  stages: [
    { duration: '10s', target: VUS },    // Ramp-up
    { duration: STEADY, target: VUS },   // Steady load
    { duration: '10s', target: 0 },      // Ramp-down
  ],
  thresholds: {
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    api_errors: ['rate<0.01'],
  },
};

export function setup() {
  if (!API_KEY) {
    console.warn('WARNING: API_KEY env not set — /api/v2/products will be skipped.');
  }
}

export default function () {
  const headers = {
    'Content-Type': 'application/json',
    'User-Agent': 'Siro-k6/1.0',
  };

  // GET / (root, no DB)
  let res = http.get(`${BASE_URL}/`, { headers });
  check(res, { 'root status 200': (r) => r.status === 200 });
  latencyRoot.add(res.timings.duration);
  errorRate.add(res.status >= 400);

  // GET /health (DB ping)
  res = http.get(`${BASE_URL}/health`, { headers });
  check(res, { 'health status 200': (r) => r.status === 200 });
  latencyHealth.add(res.timings.duration);
  errorRate.add(res.status >= 400);

  // GET /api/v2/products (API-key auth + throttle + DB list)
  if (API_KEY) {
    res = http.get(`${BASE_URL}/api/v2/products?limit=5`, {
      headers: Object.assign({}, headers, { 'X-Api-Key': API_KEY }),
    });
    check(res, { 'products status 200': (r) => r.status === 200 });
    latencyProducts.add(res.timings.duration);
    errorRate.add(res.status >= 400);
  }

  sleep(0.1);
}

export function handleSummary(data) {
  const m = data.metrics;
  const line = (name, v) => {
    if (!v) return;
    const p50 = v['p(50)'] ?? v.med;
    const p95 = v['p(95)'];
    const p99 = v['p(99)'];
    console.log(
      `${name}: count=${v.count} avg=${v.avg.toFixed(2)}ms ` +
      `p50=${p50.toFixed(2)}ms p95=${p95.toFixed(2)}ms ` +
      `p99=${p99 !== undefined ? p99.toFixed(2) : 'n/a'}ms max=${v.max.toFixed(2)}ms`
    );
  };
  console.log('=== k6 Load Test Results ===');
  console.log(`VUs: ${VUS}, steady: ${STEADY}, base: ${BASE_URL}`);
  console.log(`Total requests: ${m.http_reqs.values.count}`);
  console.log(`Throughput: ${(m.http_reqs.values.rate || 0).toFixed(2)} req/s`);
  console.log(`Failed requests: ${(m.http_req_failed.values.rate * 100).toFixed(2)}%`);
  console.log(`Error rate (api_errors): ${(m.api_errors.values.rate * 100).toFixed(2)}%`);
  line('root    ', m.root_latency_ms.values);
  line('health  ', m.health_latency_ms.values);
  line('products', m.products_latency_ms.values);
  line('overall ', m.http_req_duration.values);
  return { stdout: JSON.stringify(data.metrics, null, 2) };
}
