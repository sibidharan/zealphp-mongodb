# Case Study: Achieving C-Driver Parity with a Dual Tokio Runtime

## Background

zealphp-mongodb is a Rust PHP extension that bridges the official [mongo-rust-driver](https://github.com/mongodb/mongo-rust-driver) into PHP. It replaces the C-based `ext-mongodb` + `mongodb/mongodb` PHP library stack with a single Rust extension plus a thin PHP OOP layer that mirrors the official API.

The driver was deployed to production powering the [Selfmade Ninja Labs](https://labs.selfmade.ninja) platform — an educational environment running 87+ PHP sites under a single ZealPHP application server. In production, the driver exhibited two critical issues:

1. **Performance gap**: Synchronous MongoDB operations were 70-100% slower than the C driver
2. **Cursor corruption**: "Invalid cursor ID" errors under concurrent load

This document traces the investigation, root cause analysis, and the architectural fix that brought the Rust driver to within 3% of C driver performance.

## The Problem

### Performance Regression

Benchmarks on a real workload (the Labs dashboard, hitting MongoDB for every page render) showed:

| Operation | C Driver (ext-mongodb) | zealphp-mongodb v0.1.1 | Gap |
|-----------|----------------------|----------------------|-----|
| findOne | ~0.8ms | ~1.5ms | +87% |
| find + drain cursor | ~2.1ms | ~3.6ms | +71% |
| insertOne | ~0.9ms | ~1.6ms | +78% |

Users would notice this on every page load. At 87 sites and hundreds of concurrent requests, the cumulative effect was significant.

### Cursor Corruption

Under concurrent load, the server produced errors like:

```
Exception: Invalid cursor ID: 94
  at zealphp_mongodb_cursor_close(/home/labs/zealphp-mongodb/php/src/Cursor.php:75)
```

Cursor IDs are integers assigned by the Rust extension's internal HashMap. Under the original architecture, cursor operations on the sync path were being dispatched to a shared multi-thread runtime where timing issues could cause cursor state corruption.

## Root Cause: Multi-Thread Runtime for Synchronous Operations

The original architecture used a single `tokio` multi-thread runtime for everything:

```rust
static RUNTIME: LazyLock<Runtime> = LazyLock::new(|| {
    Runtime::new().unwrap() // multi_thread, default worker count
});
```

Every synchronous MongoDB call — `find_one`, `insert_one`, `aggregate` — went through `RUNTIME.block_on(future)`. This is the canonical way to call async code from sync context, but with a multi-thread runtime it introduces overhead:

1. **Thread coordination**: `block_on` on a multi-thread runtime must park the calling thread and coordinate with worker threads to poll the future
2. **Cross-thread wakeups**: The future runs on a worker thread; completing it requires waking the blocked calling thread
3. **Contention**: Multiple PHP workers calling `block_on` simultaneously contend for the runtime's shared task queue

Each `block_on` call added ~0.19ms of overhead. For a findOne that takes ~0.8ms on the wire, that's a 24% tax before the operation even reaches MongoDB.

### Why Not Just Use current_thread Everywhere?

A `current_thread` runtime is single-threaded — `block_on` runs the future directly on the calling thread with no coordination overhead. But it cannot `spawn()` tasks that outlive the `block_on` call, which is exactly what the async/coroutine path needs.

The async path (`zealphp_mongodb_exec_async`, cursor streaming) spawns a future and returns immediately — the future runs in the background while PHP continues execution. This requires a multi-thread runtime with persistent worker threads.

## The Solution: Dual Runtime Architecture

The fix separates sync and async concerns into two dedicated runtimes:

```rust
// Sync path: current_thread — zero coordination overhead on block_on
static SYNC_RUNTIME: LazyLock<Runtime> = LazyLock::new(|| {
    tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .unwrap()
});

// Async path: multi_thread with 1 worker — for spawned background tasks
static ASYNC_RUNTIME: LazyLock<Runtime> = LazyLock::new(|| {
    tokio::runtime::Builder::new_multi_thread()
        .worker_threads(1)
        .enable_all()
        .build()
        .unwrap()
});
```

### Sync Path (95% of calls)

All blocking operations — `find_one`, `insert_one`, `update_one`, `aggregate`, `count_documents`, etc. — use `SYNC_RUNTIME.block_on()`. The future runs inline on the calling thread. No worker threads, no cross-thread wakes, no contention.

```rust
pub fn runtime() -> &'static Runtime {
    &SYNC_RUNTIME
}

// In each PHP-exported function:
let result = runtime().block_on(async {
    collection.find_one(filter).await
});
```

### Async Path (5% of calls)

Background operations — `exec_async`, cursor streaming, batch fetching — use `ASYNC_RUNTIME.spawn()`. These futures outlive the PHP call and complete asynchronously, notifying PHP through the eventfd bridge.

```rust
pub fn async_runtime() -> &'static Runtime {
    &ASYNC_RUNTIME
}

// In async-spawning functions:
async_runtime().spawn(async move {
    let result = collection.find(filter).await;
    // ... notify PHP via eventfd
});
```

### Lazy Async Client

MongoDB clients hold connection pools and are tied to the runtime they're created on. The dual runtime requires separate clients:

```rust
pub struct PoolEntry {
    client: Client,             // Created on SYNC_RUNTIME (always)
    async_client: Option<Client>, // Created lazily on ASYNC_RUNTIME (on first async use)
    uri: String,                // Stored for lazy async client creation
}

pub fn get_async_client(pool_id: u64) -> Result<Client, String> {
    let mut pools = POOLS.lock().unwrap();
    let entry = pools.get_mut(&pool_id).ok_or("Invalid pool ID")?;
    if entry.async_client.is_none() {
        let client = async_runtime().block_on(Client::with_uri_str(&entry.uri))
            .map_err(|e| e.to_string())?;
        entry.async_client = Some(client);
    }
    Ok(entry.async_client.as_ref().unwrap().clone())
}
```

The sync client is created at `connect()` time (always needed). The async client is created only on first async use — most request cycles never touch it.

## Results

### Benchmark: After Dual Runtime

| Operation | C Driver | zealphp-mongodb (dual) | Gap |
|-----------|----------|----------------------|-----|
| findOne | ~0.8ms | ~0.82ms | **+3%** |
| find + drain cursor | ~2.1ms | ~1.98ms | **-6% (faster)** |
| insertOne | ~0.9ms | ~0.93ms | +3% |

The 70-100% gap collapsed to 3%. On find-with-cursor operations, the Rust driver is actually 6% faster than the C driver — the Rust MongoDB driver's cursor implementation is more efficient than the C driver's PHP-level iteration.

### countDocuments: An Expected Outlier

One operation remained slower: `countDocuments` showed a ~123% gap. This is not a driver inefficiency — it's a specification difference:

- The **Rust driver** implements `countDocuments` using an aggregation pipeline (`$match` + `$group` with `$sum`), per the [MongoDB specification](https://github.com/mongodb/specifications/blob/master/source/crud/crud.md#count-api-details) which deprecated the `count` command
- The **C driver benchmark** used the raw `count` command, which is faster but deprecated and can return inaccurate results on sharded clusters

The Rust driver's approach is correct per spec. The performance difference is the cost of accuracy.

### Cursor Stability

The cursor corruption issue was resolved as a side effect of the runtime separation. With sync operations running on `current_thread`, cursor creation and consumption happen deterministically on the same thread, eliminating the race condition.

A stress test of 40 concurrent cursor-heavy requests completed with 0 errors, confirming the fix.

## Production Deployment

### ext-mongodb Compatibility

The production environment had both `ext-mongodb` (C driver) and `zealphp-mongodb` (Rust driver) loaded. ext-mongodb 2.x changed the `bsonSerialize()` return type signature, causing a fatal error when the PHP MongoDB library (which expects ext-mongodb 1.x) tried to use `BSONDocument`:

```
Declaration of MongoDB\Model\BSONDocument::bsonSerialize() must be compatible
with MongoDB\BSON\Serializable::bsonSerialize(): array|stdClass|\MongoDB\BSON\Document
```

The fix: ZealPHP runs with a custom PHP config directory that includes all CLI extensions except `ext-mongodb`:

```bash
ZEALPHP_CONFDIR=/etc/php/8.4/zealphp/conf.d
mkdir -p "$ZEALPHP_CONFDIR"
for f in /etc/php/8.4/cli/conf.d/*.ini; do
    case "$(basename "$f")" in *mongodb*) continue;; esac
    cp "$f" "$ZEALPHP_CONFDIR/"
done
PHP_INI_SCAN_DIR="$ZEALPHP_CONFDIR" php -c "$ZEALPHP_INI" server.php start -d
```

Cron workers and CLI scripts still use the default PHP config with ext-mongodb available.

### Feature Parity Verification

After deploying the optimized driver, full parity testing confirmed identical behavior between ZealPHP (Rust driver) and Apache (C driver):

- **13 public pages**: All returned matching status codes and content
- **14 authenticated pages**: All rendered identically
- **9 API endpoints**: All returned matching JSON responses
- **15 ZealPHP workers**: 0 crashes, 0 fatal errors after deployment

## Architecture Diagram

```
PHP Request Thread
     │
     ├─── Sync operations (95%)
     │    └── SYNC_RUNTIME (current_thread)
     │         └── block_on(future)  ← runs inline, zero overhead
     │              └── sync_client → MongoDB
     │
     └─── Async operations (5%)
          └── ASYNC_RUNTIME (multi_thread, 1 worker)
               └── spawn(future)  ← returns immediately
                    ├── async_client → MongoDB
                    └── eventfd → PHP coroutine wakeup
```

## Key Takeaways

1. **`block_on` on a multi-thread runtime is expensive for small operations**. The coordination overhead (~0.19ms) is negligible for long-running tasks but devastating for sub-millisecond database calls.

2. **Separate runtimes for separate concerns**. Sync and async have fundamentally different execution models — forcing them onto one runtime compromises both.

3. **Lazy resource creation matters**. Most PHP requests never use the async path. Creating the async client eagerly would double connection pool usage for no benefit.

4. **Specification compliance has performance costs**. The `countDocuments` gap is a conscious tradeoff — correctness over speed.

5. **Cursor stability requires deterministic execution**. Running cursor operations on a single-threaded runtime eliminates a class of concurrency bugs that are hard to reproduce and debug.
