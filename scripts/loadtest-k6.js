// k6 load test script
// Run: k6 run scripts/loadtest-k6.js
// Install: https://k6.io/docs/getting-started/installation/

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

// Custom metrics
const latency = new Trend('api_latency_ms');
const errorRate = new Rate('api_errors');

export const options = {
  stages: [
    { duration: '10s', target: 10 },   // Ramp-up
    { duration: '30s', target: 50 },   // Steady
    { duration: '10s', target: 0 },    // Ramp-down
  ],
  thresholds: {
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    api_errors: ['rate<0.01'],
  },
};

export default function () {
  const headers = {
    'Content-Type': 'application/json',
    'User-Agent': 'Siro-k6/1.0',
  };

  // GET /health
  let res = http.get(`${BASE_URL}/health`, { headers });
  check(res, { 'health status 200': (r) => r.status === 200 });
  latency.add(res.timings.duration);
  errorRate.add(res.status >= 400);
  sleep(1);

  // GET /api/users (if auth, add header)
  res = http.get(`${BASE_URL}/api/users`, {
    headers: Object.assign({}, headers, { Authorization: 'Bearer test' }),
  });
  check(res, { 'users endpoint ok': (r) => r.status < 500 });
  latency.add(res.timings.duration);
  errorRate.add(res.status >= 400);
  sleep(1);
}

export function handleSummary(data) {
  console.log(`=== k6 Load Test Results ===`);
  console.log(`Total requests: ${data.metrics.http_reqs.values.count}`);
  console.log(`P95 latency: ${data.metrics.http_req_duration.values['p(95)'].toFixed(2)}ms`);
  console.log(`Error rate: ${(data.metrics.api_errors.values.rate * 100).toFixed(2)}%`);
  return { 'stdout': JSON.stringify(data.metrics, null, 2) };
}
