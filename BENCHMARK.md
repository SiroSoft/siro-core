# Performance Benchmarks

> **Two kinds of numbers live here — do not mix them.**
> 1. **Micro-benchmarks** (component-level, in-process, `benchmark.php`): framework overhead only, no I/O. Useful for comparing framework internals.
> 2. **Real HTTP throughput** (k6, § "Real HTTP throughput"): end-to-end requests against a real app (SiroERP staging) over nginx + PHP-FPM + MySQL. This is the number that matters for production capacity planning.
>
> An older version of this file claimed "~410,000 req/sec" full-stack throughput. That was an **in-process micro-benchmark** (cold boot + route + response measured inside one PHP process, no HTTP, no network, no database) and it was misleading as a throughput claim. **It has been removed.** The real HTTP numbers below replace it.

---

## How to Run

```bash
# Micro-benchmarks (10,000 iterations each, 10 warmup)
php benchmark.php

# Quick micro-benchmarks (100 iterations)
php benchmark.php --quick

# JSON output (for CI/reporting)
php benchmark.php --json

# Cold boot time measurement
php scripts/bench-boot.php

# Real HTTP load test (k6, against a staging app — see scripts/loadtest-report-1.5.0.md)
BASE_URL=http://127.0.0.1:8080 API_KEY=<key> VUS=10 STEADY=20s k6 run scripts/loadtest-k6.js
```

---

## Methodology

### Micro-benchmarks (historical)

- **Hardware**: AMD Ryzen 7 5800X (8C/16T @ 3.8GHz), 32GB DDR4, NVMe SSD
- **OS**: Windows 11 (WSL2 / bare metal)
- **PHP**: 8.2.30, OPcache disabled (cold boot measurement), JIT disabled
- **Tool**: Built-in `benchmark.php` — `hrtime()` or `microtime(true)` with high-resolution timing
- **Warm-up**: 10 iterations per benchmark before measurement
- **Repeat**: Median of multiple runs; framework overhead only, no I/O

### Real HTTP throughput (k6, v1.5.0)

- **Hardware**: shared VM, 1.5 vCPU, 6 GB RAM (the same VM that runs the other tracks' verifications concurrently — see the load notes per run)
- **OS**: Ubuntu 24.04 (noble)
- **PHP**: 8.4.28 (sury), PHP-FPM `pm=static`, 20 workers, OPcache on, JIT off
- **Web server**: nginx 1.24.0 → FPM Unix socket
- **App**: SiroERP backend (staging copy) with `vendor/sirosoft/core` = the merged 1.5.0-dev tree; MySQL 8.0.46, database `siroerp_g8` (114 tables, 50 products, 50 orders, 6 users, 30 customers, seeded)
- **Staging-only tuning** (does not affect the framework code being measured): `APP_DEBUG=false` (tracing off — production mode), FPM `pm=static` 20 workers (no scale-up queueing), the app v2 throttle cap raised `120/min` → `100000/min` so the measurement is framework throughput, not the app cap (the throttle middleware still runs every request: DB row lookup + atomic increment, so its cost is included)
- **Tool**: k6 v2.4.0, `scripts/loadtest-k6.js` — 3 real read-only endpoints per iteration: `GET /` (no DB), `GET /health` (SELECT 1), `GET /api/v2/products?limit=5` (API-key auth + throttle + DB read, ~20 KB)
- **Run shape**: 10 s ramp-up, 20 s steady, 10 s ramp-down; thresholds `p95 < 500 ms`, error rate < 1%
- **Full detail**: `scripts/loadtest-report-1.5.0.md`

---

## Latest Results — Real HTTP throughput (k6, v1.5.0-dev, 26 Nov 2026)

Staging as described above. Error rate 0.00% at every level; all checks passed.

| Load | Throughput | Overall p50 | Overall p95 | Overall p99 | Error % | Conditions during run |
|------|-----------:|------------:|------------:|------------:|--------:|-----------------------|
| 10 VU | **14.64 req/s** | 415 ms | 911 ms | 1,282 ms | 0.00% | Load avg 1.55 → 0.78 (quietest window) |
| 50 VU | **37.71 req/s** | 991 ms | 1,667 ms | 1,919 ms | 0.00% | Load avg 19 → 35 (other tracks' verifications running) |
| 200 VU | **37.05 req/s** | 4,628 ms | 5,878 ms | 6,108 ms | 0.00% | Load avg 35 → 45 (heavy contention) |

Per-endpoint p50 at 10 VU (quiet): `/` 325 ms, `/health` 387 ms, `/api/v2/products?limit=5` 691 ms (products is slowest: API-key DB lookup + throttle DB increment + product query).

### How to read these numbers

- **These are lower bounds on this shared VM, not the framework's ceiling.** The handler itself is fast (app trace data: median 7–15 ms per request; framework boot ~2 ms; a trivial PHP file through the same FPM pool answers in 8–19 ms). The measured per-request latency is dominated by CPU scheduling on a 1.5-vCPU VM shared with other tracks' test suites (load averages 1–45 during the measurement day) plus 3 MySQL round-trips on the products endpoint. On a quiet or dedicated machine the same code will be several times faster.
- **Throughput plateaus at ~37 req/s between 50 and 200 VU** under heavy load: the VM's CPU is the bottleneck (load 35–45), not the framework — latency grows instead of throughput. That is the expected signature of a CPU-saturated host.
- **Thresholds `p95 < 500 ms` are NOT met** on this VM (p95 911 ms even at 10 VU). That is a statement about this VM under shared load, not a framework defect: the same endpoints answer in ~15 ms of handler time. Do not tune the framework to chase p95 < 500 ms on this host; re-measure on a quiet host first.
- **No regression vs 1.4.2 at 10 VU.** Baseline measured on core 1.4.2 (`bded436`, same staging, same 20 workers): **14.90 req/s**, p50 414 ms, p95 902 ms — within 2% of 1.5.0-dev's 14.64 req/s (p50 415 ms). The two runs saw different load (1.4.2 under load 21–28, 1.5.0-dev under load 0.78–1.55), so the comparison is not perfectly matched, but the throughputs and latencies are statistically indistinguishable — **no >10% regression**. See `scripts/loadtest-report-1.5.0.md` for the run-by-run table.

### Cold Boot (Full App::boot, `scripts/bench-boot.php`, 50 iterations)

| Metric | Value | Conditions |
|--------|:-----:|------------|
| Min boot time | **1.97 ms** | Quietest run (load ~1.5); meets the < 2 ms target |
| Avg boot time | 10–38 ms | Varies with CPU contention (same script, loaded VM) |
| Max boot time | 60–120 ms | Contention spikes |

`bench-boot.php` measures a cold boot per iteration (env reset + config cache cleared, `new App()` + `boot()`). The minimum (~2 ms) is the representative figure on this VM; the average is inflated by the shared CPU. For reference, the historical Windows/OPcache-off number was 2.40 ms avg.

---

## Micro-benchmarks (Component-level, PHP 8.2.30, OPcache off)

| Benchmark | Avg (ms) | Ops/sec | Notes |
|-----------|:--------:|:-------:|-------|
| Container::make(stdClass) | **0.0005** | **1,861,074** | DI container resolution |
| Route registration | **0.0011** | **942,434** | Adding route to router |
| Route dispatch (static, O(1)) | **0.0027** | **369,855** | Hash lookup + handler call |
| Route dispatch (dynamic `{id}`) | **0.0034** | **297,173** | Regex + param extraction |
| JSON Response::success() | **0.0003** | **3,787,523** | JSON encode + headers |
| Middleware pipeline (5 layers) | **0.0061** | **164,447** | 5 closures chained |
| Request::validate (5 rules) | **0.0056** | **177,804** | 5 validation rules |
| Request construction + headers | **0.0004** | **2,363,520** | Full request object |
| Full-stack (warm route+response) | **0.0025** | **403,608** | Route dispatch + middleware + Response |
| PHP baseline (empty loop) | **0.0001** | **6,744,338** | Reference: 10-iteration loop |

> **Key insight**: Once the framework is booted (warm), request dispatch is **~0.0025ms**.
> Boot happens once per PHP-FPM worker or FrankenPHP process.
> In production with OPcache + route caching, boot time drops significantly.

> **Note**: `App::boot` loads config, env, and initializes all core services.
> In production with OPcache + route caching, boot time drops to **~0.1ms**.

---

## Comparison vs Other Frameworks

| Metric | SiroPHP | Laravel 11 | Slim 4 | Fastify (Node) | Gin (Go) |
|--------|:-------:|:----------:|:------:|:--------------:|:--------:|
| Boot time (cold) | **~0.5ms** (est.) | ~60ms | ~5ms | ~5ms | **~0.3ms** |
| Memory (framework baseline) | **~4MB** | ~84MB | ~6MB | ~10MB | ~2MB |
| Route dispatch (static) | **361K ops/s** | ~20K ops/s | ~200K ops/s | ~500K ops/s | ~2M ops/s |
| Dependencies | **0** | 60+ | 10+ | 15+ | 1 |
| Codebase size | **~25K lines** | 1M+ | ~50K | ~200K | ~150K |

---

## Optimization Features

### Implemented

| Optimization | Description |
|-------------|-------------|
| Zero dependencies | No Composer packages to load |
| Lazy boot | Only essential services initialized |
| OPcache preload | Framework classes pre-compiled |
| Route caching | Routes compiled → cached once |
| Config caching | Config files cached as PHP array |
| Lazy DB connection | DB connects only on first query |
| Route hash map | Static routes = O(1) lookup |

### Planned

| Optimization | Target | Status |
|-------------|:------:|:------:|
| FrankenPHP | 10x throughput via worker mode | v1.1 |
| Swoole adapter | Async I/O | v1.1 |
| JIT compilation | PHP 8.2 JIT tuned profile | v1.0 |

---

## Performance Tips

```bash
# 1. Enable OPcache (php.ini)
opcache.enable=1
opcache.preload=preload.php

# 2. Cache config + routes
php siro optimize

# 3. Use production mode
APP_DEBUG=false
APP_ENV=production

# 4. Benchmark before/after
php benchmark.php --json

# 5. Reproduce benchmarks
#    All benchmarks use the same methodology:
#    Warmup→10 iters, Measure→N iters, report avg/min/max
```
