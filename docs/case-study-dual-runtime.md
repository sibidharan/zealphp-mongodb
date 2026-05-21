# Case Study: Rust MongoDB Driver for PHP — Trading Per-Query Overhead for Coroutine Parallelism

## Background

zealphp-mongodb is a Rust PHP extension that bridges the official [mongo-rust-driver](https://github.com/mongodb/mongo-rust-driver) into PHP. It replaces the C-based `ext-mongodb` + `mongodb/mongodb` PHP library stack with a single Rust extension plus a thin PHP OOP layer that mirrors the official API.

The driver powers the [Selfmade Ninja Labs](https://labs.selfmade.ninja) platform — an educational environment running 87+ PHP sites under a single ZealPHP (OpenSwoole-based) application server. The design goal was not to beat the C driver on individual query latency, but to enable **native coroutine-level parallelism** without relying on Swoole's C-level socket hooks to intercept `ext-mongodb`'s `libmongoc` calls — an approach that is fragile and doesn't cover all code paths.

## Architecture

### Dual Tokio Runtime

The extension maintains two separate tokio runtimes, each optimized for its use case:

```rust
// Sync path: current_thread — zero coordination overhead on block_on
static SYNC_RUNTIME: current_thread runtime

// Async path: multi_thread with 1 worker — for spawned background tasks
static ASYNC_RUNTIME: multi_thread runtime (1 worker)
```

**Sync path** (Apache, CLI, PHPUnit): All blocking operations use `SYNC_RUNTIME.block_on()`. The future runs inline on the calling thread — no worker threads, no cross-thread wakes.

**Async path** (ZealPHP coroutines): Operations spawn on `ASYNC_RUNTIME` and return immediately. The future completes in the background, notifying PHP through an eventfd bridge that wakes the suspended coroutine via `OpenSwoole\Coroutine\System::waitEvent()`.

The PHP `Collection` API is identical for both paths — `AsyncBridge` detects the execution context and routes automatically:

```
PHP Request
     │
     ├── Outside coroutine (Apache/CLI)
     │    └── SYNC_RUNTIME.block_on(future) → blocks until complete
     │
     └── Inside coroutine (ZealPHP)
          └── ASYNC_RUNTIME.spawn(future) → returns immediately
               ├── async_client → MongoDB
               └── eventfd → coroutine wakeup
```

### Lazy Async Client

MongoDB clients hold connection pools tied to the runtime they're created on. The sync client is created at `connect()` time. The async client is created lazily on first coroutine use — most CLI/worker processes never touch it.

### RawDocumentBuf Zero-Copy Path

The entire BSON-to-PHP conversion happens in Rust. The `raw_doc_to_php` function reads directly from `RawDocumentBuf` byte buffers — `RawBsonRef` provides zero-copy views where strings are borrowed slices, not owned allocations. This eliminated the original double-deserialization problem (Wire → `bson::Document` → PHP zval) by going Wire → `RawDocumentBuf` → PHP zval in a single pass.

### Optimizations Beyond Zero-Copy

Three additional optimizations close the gap to the C driver:

1. **Thread-local ClassEntry caching.** PHP class lookups (`ClassEntry::try_find("ZealPHP\\MongoDB\\Document")`) are called once per process lifetime instead of once per document. Cached pointers are stored in `thread_local!` cells.

2. **Single-call find_all path.** `Cursor::toArray()` on deferred queries calls `zealphp_mongodb_find_all()` — a single FFI round-trip that does find + collect + BSON→PHP conversion entirely in Rust. No cursor creation, no per-doc `cursor_next`, no PHP-side foreach loop.

3. **Pre-allocated ZendHashTables.** Result arrays use `ZendHashTable::with_capacity(n)` where `n` is known (doc count for result sets, 8 for document fields), eliminating rehash overhead.

The remaining per-field overhead is `ext-php-rs`'s safe Zval/ZendHashTable wrappers vs the C driver's direct `zend_hash_update`/`ZVAL_STRING` calls.

## Per-Operation Benchmarks

All benchmarks: 200 iterations each, median timing, PHP 8.4.5, MongoDB 6.0, same host.

### Sync Path (no OpenSwoole)

| Operation | zealphp-mongodb | ext-mongodb (C) | Gap |
|-----------|----------------|-----------------|-----|
| findOne | 0.442ms | 0.451ms | **-1.9%** |
| find(50) | 0.550ms | 0.494ms | +11.3% |
| find(1000) | 4.270ms | 3.764ms | +13.4% |
| insertOne | 0.297ms | 0.292ms | +1.6% |
| updateOne | 0.493ms | 0.519ms | **-5.0%** |
| deleteOne | 0.598ms | 0.610ms | **-2.1%** |
| countDocuments | 0.883ms | 0.901ms | **-2.1%** |
| aggregate | 1.415ms | 1.458ms | **-2.9%** |
| distinct | 0.795ms | 0.820ms | **-3.0%** |
| findOneAndUpdate | 0.511ms | 0.539ms | **-5.1%** |

**7 of 10 operations match or beat the C driver.** The Rust driver is faster on write operations (updateOne -5%, findOneAndUpdate -5.1%), server-side operations (countDocuments -2.1%, aggregate -2.9%, distinct -3%), and single-doc reads (findOne -1.9%). The remaining gap is concentrated in bulk cursor reads (find 50/1000), where `ext-php-rs`'s safe Zval wrappers add per-field overhead vs the C driver's direct `zend_hash_update` calls.

**Total overhead across all operations: +4.1%** — effectively parity.

## Where It Wins: Coroutine Parallelism

Under Apache's prefork MPM, each pre-forked process handles one request at a time, executing queries sequentially. The driver's sync path (`SYNC_RUNTIME.block_on()`) blocks the calling thread until each query completes — there is no way to overlap I/O within a single request. With the C driver (`ext-mongodb`), Swoole's `Hook::ENABLE_ALL` can intercept `libmongoc`'s socket calls to add coroutine awareness, but this relies on hooking internal socket operations — it doesn't cover all code paths (e.g., DNS resolution, TLS handshake internals) and can't enable intra-request parallelism without explicit coroutine spawning in userland.

The Rust driver's async path is native: tokio futures yield naturally during I/O without external hook interception. Under ZealPHP, multiple queries execute concurrently within a single process — different requests interleave, and independent queries within a single request can run in parallel.

### Intra-Request Parallelism

Real benchmark: 4 independent queries executed sequentially vs. in parallel coroutines.

| Pattern | Time | vs. Sequential |
|---------|------|----------------|
| 4 × findOne sequential | 2.330ms | baseline |
| 4 × findOne parallel (4 coroutines) | 0.689ms | **3.4x faster** |
| 4 × aggregate sequential | 4.489ms | baseline |
| 4 × aggregate parallel (4 coroutines) | 1.158ms | **3.9x faster** |

A single parallel findOne (0.689ms / 4 = 0.172ms effective per query) is faster than even the C driver's 0.427ms sequential findOne. **Parallelism more than compensates for the per-operation overhead.**

### Production Deployment: Response Times and Throughput

Both servers run inside the same Docker container, serving the same PHP codebase from the same volume. Apache uses `ext-mongodb` (C driver) with prefork MPM (MaxRequestWorkers=150). ZealPHP uses `zealphp-mongodb` (Rust driver) with OpenSwoole (12 workers). PHP ini files are split per SAPI — Apache loads `mongodb.so`, CLI/ZealPHP loads `zealphp_mongodb.so`. AMD Ryzen 9 7900X, PHP 8.4, OPcache enabled on both.

**Methodology:** Throughput benchmarks use `ab` from inside the container hitting `localhost` directly (bypassing Traefik reverse proxy, which rate-limits at 30 avg/3s and would cap results). All requests include `Host: labsdev.selfmade.ninja` to match OAuth config. Latency percentiles from `ab -n 200 -c 10`.

### Latency

| Route | ZealPHP p50 | Apache p50 | ZealPHP p95 | Apache p95 |
|-------|-------------|------------|-------------|------------|
| `/` (landing, 5 DB + 1 API query, cached) | 20ms | 78ms | 27ms | 109ms |
| `/features` (light page) | 5ms | 37ms | 11ms | 47ms |

The landing page uses Redis-cached stats (5 MongoDB counts + 1 GitLab API call, 300s TTL). Under ZealPHP, the 5 count queries run in parallel coroutines when the cache is cold; Apache executes them sequentially.

### Throughput

`ab` from inside container → `localhost`, `c=10`, `n=200`:

| Endpoint | ZealPHP (Rust) | Apache (C) | Ratio |
|----------|---------------|------------|-------|
| `/features` (light page) | **1699 req/s** | 254 req/s | **6.7x** |
| `/` (landing, cached) | **465 req/s** | 120 req/s | **3.9x** |

**Zero failed requests** across all throughput tests on both servers.

ZealPHP is faster on both endpoints. On the landing page, Redis caching eliminates the external API call (~190ms to GitLab) and parallel coroutines reduce cold-cache rebuild time. The 3.9x landing page advantage comes from both the application server architecture (coroutine reuse vs prefork overhead) and the driver's async path enabling parallel I/O. On lightweight pages, Apache's per-request overhead (process bootstrapping, module init, autoloader cold path) becomes the bottleneck, giving ZealPHP a 6.7x throughput advantage.

ZealPHP's advantage comes from:
1. No per-request process allocation — coroutines within pre-booted workers
2. Shared boot state (classes, config, connection pools loaded once at startup)
3. Near-zero per-request overhead (~20ms vs ~78ms for the landing page, ~5ms vs ~37ms for light pages)
4. Coroutine parallelism for independent I/O operations (stats queries, API calls)

### Known Limitations

- ZealPHP throughput degrades when concurrent connections approach the worker count (12). This is an OpenSwoole dispatch behavior under investigation — at `c >= worker_num`, requests queue rather than multiplex via coroutines.
- Landing page benchmarks reflect warm Redis cache (300s TTL). Cold-cache requests add ~15-20ms (parallel DB queries) or ~190ms (GitLab API miss). Apache cold-cache is ~200ms+ (sequential).

**Comparison scope:** These benchmarks compare ZealPHP against Apache prefork with `mod_php` — the deployment used by this project. The industry-standard high-performance PHP stack (nginx + php-fpm) would narrow the gap on throughput, though it still cannot provide intra-request query parallelism without Swoole coroutine hooks.

## Coroutine Safety

The driver was validated for correctness under concurrent coroutine execution:

| Test | Result |
|------|--------|
| All CRUD operations (async path) | 12/12 pass |
| 2 concurrent coroutines, independent collections | Pass |
| 10 concurrent insert+read coroutines | Pass |
| 20 concurrent read-update-read coroutines | Pass |
| 3 concurrent cursor iterations (10 docs each) | Pass |

No data corruption, no cursor leaks, no cross-coroutine state bleed.

## Test Suite

| Suite | Tests | Status |
|-------|-------|--------|
| Unit (BSON types, exceptions, concerns) | 66 | All pass |
| Integration (CRUD, cursors, aggregation) | 30 | All pass |
| Async CRUD (OpenSwoole coroutine context) | 12 | All pass |
| Concurrency (multi-coroutine stress) | 4 | All pass |

Total: **112 tests, 0 failures.**

## Parallelism Opportunities in Production

Three endpoints already use coroutine parallelism:

| Endpoint | Pattern | Speedup |
|----------|---------|---------|
| `DashboardAnalyticsComputer` | 5 independent queries via `go()` | ~4x |
| `/api/profile/profile` | 4 stats queries in parallel | ~3-4x |
| `/` landing page stats | 5 count queries via `go()` + Channel, Redis-cached (300s TTL) | ~3x (cold cache) |

Identified but not yet parallelized:

| Endpoint | Sequential Queries | Potential |
|----------|-------------------|-----------|
| `/api/dashboard/setup` | 4 independent (labs, devices, domains, events) | ~3-4x |
| `/api/event/get_metrics` | 6+ independent aggregations | ~2-3x |
| `/api/event/get_leaderboard` | 3 independent (totals, leaderboard, users) | ~2-3x |

## Architecture Diagram

```
Apache prefork (ext-mongodb, C driver)   ZealPHP (zealphp-mongodb, Rust driver)
──────────────────────────────────────   ──────────────────────────────────────
                                         
  Request → assign pre-forked process      Request → create coroutine
       │                                        │
       ├── query 1 (blocks) ─── 0.4ms          ├── query 1 (yields) ──┐
       ├── query 2 (blocks) ─── 0.4ms          ├── query 2 (yields) ──┤ concurrent
       ├── query 3 (blocks) ─── 0.4ms          ├── query 3 (yields) ──┤   ~0.6ms
       ├── query 4 (blocks) ─── 0.4ms          ├── query 4 (yields) ──┘   total
       │                                        │
       └── total: ~1.6ms sequential             └── total: ~0.6ms parallel
                                         
  20 concurrent: 20 pre-forked processes   20 concurrent: 20 coroutines
  Memory: 20 processes (COW-shared,        Memory: 1 process, ~50MB total
          unique RSS much less than
          20 × full process size)
```

## Key Takeaways

1. **Per-operation parity achieved.** After optimization (ClassEntry caching, single-call find_all, pre-allocated hash tables), 7 of 10 operations match or beat the C driver. Total overhead is +4.1% — effectively parity.

2. **Throughput scales with architecture, not driver speed.** ZealPHP handles 3.9x–6.7x more concurrent requests than Apache prefork on the same hardware. The advantage comes from eliminating per-request process overhead (20ms vs 78ms on the landing page, 5ms vs 37ms on `/features`) and coroutine parallelism for I/O-bound operations.

3. **Dual runtimes for dual execution models.** The `current_thread` sync runtime eliminates coordination overhead for blocking calls. The `multi_thread` async runtime enables fire-and-forget spawning for coroutine integration.

4. **Minimize FFI round-trips, not individual call overhead.** The biggest wins came from reducing PHP↔Rust boundary crossings: `find_all` does find + collect + convert in one call (was 1000+ calls for cursor drain). ClassEntry caching eliminated redundant string-based class lookups.

5. **Lazy resource creation matters.** The async MongoDB client is created only on first coroutine use. CLI scripts and workers never pay the cost.

6. **Cursor stability requires deterministic execution.** Running cursor operations on a single-threaded runtime eliminated a class of concurrency bugs that were hard to reproduce under the shared multi-thread runtime.
