# Case Study: Rust MongoDB Driver for PHP — Trading Per-Query Overhead for Coroutine Parallelism

## Background

zealphp-mongodb is a Rust PHP extension that bridges the official [mongo-rust-driver](https://github.com/mongodb/mongo-rust-driver) into PHP. It replaces the C-based `ext-mongodb` + `mongodb/mongodb` PHP library stack with a single Rust extension plus a thin PHP OOP layer that mirrors the official API.

The driver powers the [Selfmade Ninja Labs](https://labs.selfmade.ninja) platform — an educational environment running 87+ PHP sites under a single ZealPHP (OpenSwoole-based) application server. The design goal was not to beat the C driver on individual query latency, but to enable **coroutine-level parallelism** that the C driver architecture cannot provide.

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

The remaining overhead is in `ext-php-rs`'s Zval/ZendHashTable creation wrappers, which add abstraction layers over PHP's internal C API. The C driver calls `zend_hash_update`, `ZVAL_STRING`, etc. directly; our path goes through ext-php-rs's safe Rust wrappers (`ZendHashTable::insert`, `Zval::set_string`), which add bounds checking and reference counting overhead per field.

## Per-Operation Benchmarks

All benchmarks: 100 iterations each, median timing, PHP 8.4.5, MongoDB 6.0, same host.

### Sync Path (no OpenSwoole)

| Operation | zealphp-mongodb | ext-mongodb (C) | Gap |
|-----------|----------------|-----------------|-----|
| findOne | 0.620ms | 0.427ms | +45% |
| find(50) | 0.790ms | 0.442ms | +79% |
| find(1000) | 5.997ms | 3.283ms | +83% |
| insertOne | 0.479ms | 0.281ms | +70% |
| updateOne | 0.660ms | 0.462ms | +43% |
| deleteOne | 0.965ms | 0.597ms | +62% |
| countDocuments | 0.955ms | 0.722ms | +32% |
| aggregate | 1.525ms | 1.328ms | +15% |
| distinct | 0.959ms | 0.769ms | +25% |
| findOneAndUpdate | 0.732ms | 0.497ms | +47% |

**Per-operation, the Rust driver is 15-83% slower than the C driver.** This is the inherent cost of the Rust → PHP FFI bridge (`ext-php-rs`). The C driver converts BSON to PHP zvals in native C within the same process; our path crosses the Rust FFI boundary for every value.

### Async Path (inside OpenSwoole coroutine)

| Operation | zealphp-mongodb (async) | ext-mongodb (C) | Gap |
|-----------|------------------------|-----------------|-----|
| findOne | 0.595ms | 0.446ms | +33% |
| find(50) | 0.844ms | 0.499ms | +69% |
| find(1000) | 9.098ms | 3.676ms | +148% |
| insertOne | 0.346ms | 0.292ms | +19% |
| updateOne | 0.545ms | 0.467ms | +17% |
| countDocuments | 0.870ms | 0.750ms | +16% |
| aggregate | 1.432ms | 1.249ms | +15% |
| distinct | 0.835ms | 0.724ms | +15% |

The async path adds eventfd overhead for cursor-heavy operations (find 1000), but write operations (insertOne, updateOne) are only 15-19% slower — the eventfd round-trip is amortized over the network latency.

**The per-operation gap is real. But per-operation latency is not the metric that matters.**

## Where It Wins: Coroutine Parallelism

The C driver blocks the entire PHP process on every MongoDB call. Under Apache's prefork MPM, that means one connection = one process = one query at a time.

The Rust driver yields the coroutine during I/O. Under ZealPHP, multiple queries execute concurrently within a single process — different requests interleave, and independent queries within a single request can run in parallel.

### Intra-Request Parallelism

Real benchmark: 4 independent queries executed sequentially vs. in parallel coroutines.

| Pattern | Time | vs. Sequential |
|---------|------|----------------|
| 4 × findOne sequential | 2.330ms | baseline |
| 4 × findOne parallel (4 coroutines) | 0.689ms | **3.4x faster** |
| 4 × aggregate sequential | 4.489ms | baseline |
| 4 × aggregate parallel (4 coroutines) | 1.158ms | **3.9x faster** |

A single parallel findOne (0.689ms / 4 = 0.172ms effective per query) is faster than even the C driver's 0.427ms sequential findOne. **Parallelism more than compensates for the per-operation overhead.**

### Production Deployment: Page Response Times

Real production measurements (median of 10 requests each):

| Route | ZealPHP (Rust) | Apache (C) | Winner |
|-------|---------------|------------|--------|
| `/` (landing) | 213ms | 245ms | ZealPHP |
| `/features` | 16ms | 40ms | ZealPHP (2.5x) |
| `/about` | 424ms | 428ms | ~tie |
| `/community-guidelines` | 45ms | 35ms | Apache |
| `/leaderboard-global` | 53ms | 43ms | Apache |

API endpoints:

| Endpoint | ZealPHP (Rust) | Apache (C) | Winner |
|----------|---------------|------------|--------|
| `/api/portfolio/get_plans` | 204ms | 225ms | ZealPHP |
| `/api/portfolio/get_labslist` | 11ms | 30ms | ZealPHP (2.7x) |
| `/api/leaderboard/get_ninja_leaderboard` | 11ms | 30ms | ZealPHP (2.7x) |
| `/api/gstats/labs/public` | 10ms | 30ms | ZealPHP (3.0x) |

Despite the per-query overhead, ZealPHP matches or beats Apache on most routes because:
1. No process fork overhead per request
2. Shared boot state (classes, config, connection pools loaded once)
3. Coroutine-aware I/O yields during DB calls

### Throughput Under Concurrency

This is where the architecture difference is most dramatic. Apache spawns a process per request; ZealPHP multiplexes all requests on coroutines within a single process.

`ab -n 100 -c 20` (100 requests, 20 concurrent):

| Endpoint | ZealPHP | Apache | Ratio |
|----------|---------|--------|-------|
| `/api/portfolio/get_labslist` | **556 req/s** | 35 req/s | **16x** |
| `/api/leaderboard/get_ninja_leaderboard` | **724 req/s** | 244 req/s | **3.0x** |
| `/` (full page render) | **13.6 req/s** | 4.5 req/s | **3.0x** |
| `/features` (full page) | **405 req/s** | 144 req/s | **2.8x** |

**Zero failed requests** across all throughput tests on both servers.

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

Two endpoints already use coroutine parallelism:

| Endpoint | Pattern | Speedup |
|----------|---------|---------|
| `DashboardAnalyticsComputer` | 5 independent queries via `go()` | ~4x |
| `/api/profile/profile` | 4 stats queries in parallel | ~3-4x |

Identified but not yet parallelized:

| Endpoint | Sequential Queries | Potential |
|----------|-------------------|-----------|
| `/api/dashboard/setup` | 4 independent (labs, devices, domains, events) | ~3-4x |
| `/api/event/get_metrics` | 6+ independent aggregations | ~2-3x |
| `/api/event/get_leaderboard` | 3 independent (totals, leaderboard, users) | ~2-3x |

## Architecture Diagram

```
Apache (ext-mongodb C driver)            ZealPHP (zealphp-mongodb Rust driver)
─────────────────────────────            ─────────────────────────────────────
                                         
  Request → fork process                   Request → create coroutine
       │                                        │
       ├── query 1 (blocks) ─── 0.4ms          ├── query 1 (yields) ──┐
       ├── query 2 (blocks) ─── 0.4ms          ├── query 2 (yields) ──┤ concurrent
       ├── query 3 (blocks) ─── 0.4ms          ├── query 3 (yields) ──┤   ~0.6ms
       ├── query 4 (blocks) ─── 0.4ms          ├── query 4 (yields) ──┘   total
       │                                        │
       └── total: ~1.6ms + fork overhead        └── total: ~0.6ms, no fork
                                         
  20 concurrent: 20 processes              20 concurrent: 20 coroutines
  Memory: 20 × ~30MB = 600MB              Memory: 1 process, ~50MB total
```

## Key Takeaways

1. **Per-operation latency is not the whole story.** The Rust driver is 15-83% slower per query than the C driver, but the coroutine architecture enables parallelism that more than compensates — 4 parallel findOne calls complete in 0.69ms vs 1.7ms sequential on the C driver.

2. **Throughput scales with architecture, not driver speed.** ZealPHP handles 3-16x more concurrent requests than Apache on the same hardware. The bottleneck shifts from "how fast is one query" to "how many queries can overlap."

3. **Dual runtimes for dual execution models.** The `current_thread` sync runtime eliminates coordination overhead for blocking calls. The `multi_thread` async runtime enables fire-and-forget spawning for coroutine integration.

4. **BSON conversion is fully in Rust, but ext-php-rs wrappers add overhead.** `RawDocumentBuf` eliminated double deserialization — the entire BSON→PHP conversion now happens in one pass in Rust. The remaining gap on bulk cursor reads is `ext-php-rs`'s safe Zval/ZendHashTable wrappers vs the C driver's direct `zend_hash_update`/`ZVAL_STRING` calls. Closing this gap means either optimizing ext-php-rs or using unsafe direct PHP API calls for hot paths.

5. **Lazy resource creation matters.** The async MongoDB client is created only on first coroutine use. CLI scripts and workers never pay the cost.

6. **Cursor stability requires deterministic execution.** Running cursor operations on a single-threaded runtime eliminated a class of concurrency bugs that were hard to reproduce under the shared multi-thread runtime.
