# Changelog

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
