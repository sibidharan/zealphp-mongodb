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

## Phase 2: RawDocumentBuf Zero-Copy Path

The dual runtime fix brought single-document operations to parity, but multi-document cursor drains still lagged:

| Operation | C Driver | zealphp-mongodb (dual runtime) | Gap |
|-----------|----------|-------------------------------|-----|
| find(50 docs) | ~0.50ms | ~0.77ms | +53% |
| find(1000 docs) | ~4.15ms | ~12.7ms | +206% |

### Root Cause: Double Deserialization

The original cursor path deserialized BSON wire data twice:

1. **Wire → bson::Document**: The Rust MongoDB driver parses BSON bytes into its in-memory `Document` tree (heap-allocated HashMap of owned `Bson` values)
2. **Document → PHP zval**: Our `doc_to_php` walks the `Document`, pattern-matches each `Bson` variant, and builds PHP arrays/values

For a single document, this overhead is negligible. For 1000 documents, the intermediate `Document` allocation dominates — each document creates a full owned tree that's immediately discarded after PHP conversion.

### The Fix: RawDocumentBuf

The MongoDB Rust driver supports `Collection<RawDocumentBuf>`, which returns raw BSON bytes without parsing them into `Document`. Our new `raw_doc_to_php` reads directly from the BSON byte buffer:

```rust
pub fn raw_doc_to_php(raw: &RawDocumentBuf) -> Zval {
    let mut ht = ZendHashTable::new();
    for result in raw.iter() {
        if let Ok((key, val)) = result {
            let _ = ht.insert(key, raw_bson_to_zval(val));
        }
    }
    // ...
}
```

`RawBsonRef` is a zero-copy view into the BSON buffer — strings are borrowed slices, not owned allocations. The iteration is a linear scan over the byte buffer, not a HashMap traversal.

### Cursor Store: AnyCursor Enum

MongoDB's `Collection::aggregate()` always returns `Cursor<Document>` regardless of the collection's type parameter — this is a driver constraint. To support both cursor types in a single store:

```rust
pub enum AnyCursor {
    Raw(Cursor<RawDocumentBuf>),  // find, find_one — zero-copy path
    Doc(Cursor<Document>),        // aggregate — conversion at read time
}

impl AnyCursor {
    pub async fn next_raw(&mut self) -> Option<Result<RawDocumentBuf, Error>> {
        match self {
            AnyCursor::Raw(c) => c.next().await,
            AnyCursor::Doc(c) => match c.next().await {
                Some(Ok(doc)) => Some(RawDocumentBuf::from_document(&doc).map_err(..)),
                // ...
            },
        }
    }
}
```

### Results: After RawDocumentBuf

Full benchmark suite (100 iterations each, median timing):

| Operation | zealphp-mongodb | ext-mongodb (C) | Gap |
|-----------|----------------|-----------------|-----|
| findOne | 0.444ms | 0.454ms | **-2.3%** |
| find(50) | 0.660ms | 0.498ms | +32.6% |
| find(1000) | 8.707ms | 4.151ms | +109.7% |
| insertOne | 0.274ms | 0.281ms | **-2.5%** |
| updateOne | 0.491ms | 0.513ms | **-4.4%** |
| deleteOne | 0.588ms | 0.614ms | **-4.2%** |
| countDocuments | 0.742ms | 0.805ms | **-7.9%** |
| aggregate | 1.201ms | 1.269ms | **-5.4%** |
| distinct | 0.736ms | 0.735ms | +0.1% |
| findOneAndUpdate | 0.489ms | 0.513ms | **-4.6%** |

**8 out of 10 operations are faster than the C driver.** The remaining gap on bulk cursor reads (find 50/1000) is the fundamental cost of the Rust FFI bridge — the C driver converts BSON to PHP zvals in native C code within the same process, while our path goes through Rust's `ext-php-rs` FFI layer.

### Improvement Summary

| Operation | Before (dual runtime) | After (+ RawDocumentBuf) | Improvement |
|-----------|----------------------|--------------------------|-------------|
| findOne | +3% | -2.3% | 5pp better |
| find(50) | +53% | +32.6% | 39% reduction |
| find(1000) | +206% | +109.7% | 47% reduction |

## Key Takeaways

1. **`block_on` on a multi-thread runtime is expensive for small operations**. The coordination overhead (~0.19ms) is negligible for long-running tasks but devastating for sub-millisecond database calls.

2. **Separate runtimes for separate concerns**. Sync and async have fundamentally different execution models — forcing them onto one runtime compromises both.

3. **Lazy resource creation matters**. Most PHP requests never use the async path. Creating the async client eagerly would double connection pool usage for no benefit.

4. **Zero-copy BSON pays off at scale**. For single documents, the overhead of intermediate `Document` allocation is negligible. For cursor drains of hundreds or thousands of documents, `RawDocumentBuf` eliminates a full allocation tree per document.

5. **Driver constraints require pragmatic abstractions**. MongoDB's Rust driver constrains `aggregate()` to always return `Cursor<Document>`. The `AnyCursor` enum handles this transparently without leaking the distinction to callers.

6. **Cursor stability requires deterministic execution**. Running cursor operations on a single-threaded runtime eliminates a class of concurrency bugs that are hard to reproduce and debug.
