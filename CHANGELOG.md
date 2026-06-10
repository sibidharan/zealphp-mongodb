# Changelog

## [0.3.3] - 2026-06-10

### Fixed
- **Unbounded memory growth in `Bucket::openUploadStream()`** — the upload-stream wrapper kept a `resource id → file id` static map that was never evicted: one orphan entry per upload, forever, on long-running workers (the classic ext-mongodb-area growth pattern). The id now rides a per-stream token (the wrapper path) and is **evicted in `stream_close()`**, which PHP invokes on `fclose()` AND on resource GC — bounded even for abandoned streams. (A stream-context design doesn't work: the fopen context is not readable from outside via the stream handle.)
- Memory soak pinned: 3,000 iterations of session create/end (explicit + destructor-abandoned), change-stream open/close (destructor-only), and GridFS upload-stream/download/delete — **RSS flat at +0.0 MB after warmup**; Rust session/stream registries confirmed destructor-evicted.


## [0.3.2] - 2026-06-10

### Added — genuine C-driver parity for the three stubbed areas
- **Real multi-document transactions.** `Client::startSession()` now creates a server-backed `ClientSession` in the Rust driver (session registry, same pattern as cursors). New ext functions `zealphp_mongodb_session_start/_start_transaction/_commit_transaction/_abort_transaction/_end/_lsid/_cluster_time/_operation_time`. Every Collection op accepts `['session' => $session]` (threaded as the internal `__session` registry id) and rides the same server transaction — including reads, which see the transaction snapshot. Session-scoped `find()`/`aggregate()` collect eagerly (driver `SessionCursor` borrow rule) and return an `ArrayCursor`. Replica set required (server rule). Pinned by `tests/Integration/TransactionTest.php` (7 cases: commit-visible, abort-invisible, read-your-own-uncommitted-writes, snapshot isolation, real lsid, operationTime, ended-session guard).
- **Real change streams.** `Collection::watch()`, `Database::watch()`, `Client::watch()` open a server change stream (`zealphp_mongodb_watch` + `change_stream_next/_resume_token/_is_alive/_close`). `ChangeStream` is a real `Iterator` with mongo-php-library semantics — `next()` blocks up to `maxAwaitTimeMS`, `key()` counts delivered events, `getResumeToken()` returns the live token; pipelines and `fullDocument: updateLookup` supported. Replica set required. Pinned by `tests/Integration/ChangeStreamTest.php` (6 cases incl. pipeline filtering and db-scoped watch).
- **Real GridFS.** `Database::selectGridFSBucket()` returns a working `Bucket`: `uploadFromStream`, `openUploadStream` (write-through stream wrapper, uploads on `fclose()`, file id known before close), `downloadToStream[ByName]`, `openDownloadStream[ByName]` with revisions, `delete`, `deleteByName`, `rename`, `drop`, `find`/`findOne`, files/chunks collection accessors. Driver-side chunking. Pinned by `tests/Integration/GridFSTest.php` (6 cases incl. a 600 KiB multi-chunk round-trip and binary-safety).

### Changed
- `Collection::find()`/`aggregate()` return type widened to `Cursor|ArrayCursor` (ArrayCursor only when a session is passed).
- `Session` unit stubs replaced by integration coverage; constructing `Session`/`ChangeStream`/`Bucket` without the matching ext functions fails fast with a versioned message.

[Unreleased → 0.3.1 notes below]


## [0.3.1] - 2026-06-10

### Fixed
- **BSON polyfill link-compatibility with `mongodb/mongodb` 1.x** — the polyfill's `MongoDB\BSON\Serializable`/`Unserializable` interface methods declared typed returns; the 1.x library implements them untyped (`#[ReturnTypeWillChange]`), making every Model class a worker-killing `E_COMPILE_ERROR` on first autoload — i.e. on the **first query returning a real document** (root cause of ext-zealphp#36, the wgvpn "password_verify hang"). Interface methods are now untyped; both 1.x (untyped) and 2.x (typed) implementations link. Pinned by `PolyfillLinkCompatTest`.

### Changed
- **Session transactions and `Client::watch()` now FAIL LOUD** — `startTransaction`/`commitTransaction`/`abortTransaction` previously flipped a local state string while every operation ran **non-transactionally** (fake ACID); `watch()` returned an empty `ChangeStream` that never delivered events. All four now throw `Exception\RuntimeException('… not yet supported …')` like GridFS, until the real implementations land in v0.4.0. If your code calls these, it was silently broken before — now it tells you.


## [0.1.1] - 2026-05-19

### Added
- **Async streaming cursor with batch fetching** (`AsyncCursor` class) — streams documents from MongoDB cursors in coroutine mode with eager batch loading. Small result sets complete in a single async round-trip; large result sets stream in batches of 100. Dedicated Rust functions `zealphp_mongodb_find_cursor_async` and `zealphp_mongodb_aggregate_cursor_async`.
- **BSON type parity with official driver** — `wrapDoc()` now returns proper `BSONDocument` and `BSONArray` objects instead of plain PHP arrays, matching the `mongodb/mongodb` library's type contract.

### Changed
- **Direct BSON-to-PHP conversion** — Eliminated the JSON serialization round-trip in the async path. BSON `Document`s are now stored directly in a `BatchResult` store (`Vec<Document>`) and converted to native PHP arrays via `bson_convert::doc_to_php()` on the PHP thread.
- **waitEvent-based coroutine bridge** — Replaced `Channel` + `Event::add` with the simpler `Coroutine\System::waitEvent()` pattern in `AsyncBridge::exec()` and `Collection::awaitBatch()`, eliminating closure and Channel allocation overhead.

### Fixed
- Widened `mongodb/mongodb` version constraint to `^1.15 || ^2.0` for broader compatibility.
- CI improvements: Psalm stub files for `BSONDocument`/`BSONArray`, coding standards fixes.

## [0.1.0] - 2025-05-17

Initial release with synchronous and async MongoDB operations, connection pooling, CRUD operations, aggregation pipeline, index management, and OpenSwoole coroutine integration.
