# zealphp-mongodb

Async MongoDB driver for PHP — Rust extension + PHP OOP library.

## Versioning

**The version in `ext/Cargo.toml` is the single source of truth.** The Rust function `zealphp_mongodb_version()` reads it at compile time via `env!("CARGO_PKG_VERSION")`. Git tags must match exactly (`v{version}`).

When bumping the version:
1. Update `version` in `ext/Cargo.toml`
2. Commit
3. Tag as `v{version}` on that commit
4. Push commit and tag together

Never create a git tag that doesn't match `ext/Cargo.toml`. Never have multiple version numbers in different places.

## Project Layout

```
ext/              Rust extension (ext-php-rs, mongo-rust-driver, tokio)
  src/lib.rs      PHP function exports, find_all, cursor_to_array
  src/bson_convert.rs  BSON↔PHP conversion, ClassEntry caching
  src/cursor.rs   Cursor store, drain_to_vec
  src/coroutine.rs  Dual tokio runtime (sync + async)
  src/pool.rs     Connection pool management
  src/async_ops.rs  Async path (eventfd bridge)
  Cargo.toml      VERSION LIVES HERE
php/src/          PHP OOP layer (Collection, Database, Client, Cursor, BSON types)
tests/            PHPUnit tests (Unit/, Integration/)
benchmarks/       Performance comparison scripts
docs/             Case study, architecture docs
```

## Building

```bash
cd ext && cargo build --release
cp target/release/libzealphp_mongodb.so /usr/lib/php/20240924/zealphp_mongodb.so
```

The .so filename loaded by PHP is `zealphp_mongodb.so` (not `zealphp_mongodb_ext.so`).

## Coding Standards

- PHP follows Doctrine coding standard (`phpcs.xml.dist`)
- All functions must be imported via `use function ...` (no fallback global names)
- Space after NOT operator: `! $var` not `!$var`
- Run `vendor/bin/phpcs` before committing PHP changes

## Performance Rules

- Minimize PHP↔Rust FFI boundary crossings — batch work in Rust
- `ClassEntry::try_find()` is expensive — use thread_local cached lookups (`get_ce_*()` in bson_convert.rs)
- Cursor draining must happen in Rust (`find_all` or `cursor_to_array`), never per-doc from PHP
- Pre-allocate `ZendHashTable::with_capacity()` when size is known
- Benchmark with `benchmarks/compare.php` — target is C driver parity

## Testing

```bash
# Unit tests (no MongoDB needed)
vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/Unit/

# Integration tests (requires MongoDB + extension loaded)
vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/Integration/

# All tests
vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/
```

Note: Don't use `phpunit.xml.dist` directly — it can hang due to `beStrictAboutOutputDuringTests` + `zend.assertions` conflict.
