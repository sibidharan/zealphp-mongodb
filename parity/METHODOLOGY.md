# Achieving and keeping C-driver parity — the repeatable method

`zealphp/mongodb` is a drop-in replacement for the official `mongodb/mongodb` +
`ext-mongodb` C driver. "Parity" means: **the same application code produces a
byte-identical observable result on both drivers.** This document is the
repeatable method we use to find divergences, fix them, and prove they're fixed
— so anyone can pick up the next cluster of issues and close it with confidence.

It is written from the experience of closing the first ~20 parity issues. Follow
it and you don't have to re-derive the technique.

---

## The oracle: `parity/` rig

The rig (`parity/docker-compose.yml`) runs **the same service code**
(`parity/src/ParityApi.php`) on both stacks against one MongoDB replica set:

| Path | Stack | Endpoint |
|---|---|---|
| **C** (oracle) | Apache + mod_php + `ext-mongodb` + `mongodb/mongodb` | `:8089` |
| **Rust** (under test) | ZealPHP + OpenSwoole + `zealphp_mongodb.so` + `zealphp/mongodb` | `:8090` |

`parity/scripts/run-parity.php` fires each op at both, canonicalizes the result
with `parity/src/Normalizer.php`, and deep-diffs. **Exit 0 = every op
byte-identical.** One command:

```bash
docker compose -f parity/docker-compose.yml up --build --abort-on-container-exit parity
```

The rig is the source of truth. A fix isn't done until the rig says
`VERDICT: FULL PARITY ✓` with a new op that *exercises the bug*.

---

## The loop (do this for every issue)

1. **Reproduce as an op.** Add a method to `ParityApi` and register its name in
   `ParityApi::handle()`'s `match` and in `run-parity.php`'s `$ops` list. The op
   must return a value that **differs between the two drivers while the bug
   exists** — otherwise the rig can't see it (see "Make the bug observable").
2. **Run the rig.** Confirm it reports the divergence (`✗`). If it's already
   identical, your op isn't probing the bug — fix the probe first.
3. **Find the root cause** in the Rust ext (`ext/src/`) or the PHP library
   (`php/src/`). See "Where things live".
4. **Fix it.** Smallest change that addresses the cause, not the symptom.
5. **Re-run the rig.** It must now say `✓` for your op *and stay green for every
   other op* — regressions are failures.
6. **Run the static gates** (they're what CI runs):
   ```bash
   composer install --ignore-platform-req=ext-mongodb
   vendor/bin/phpunit --testsuite Unit
   vendor/bin/phpcs -q          # Doctrine + Slevomat; strict
   vendor/bin/rector --dry-run  # scans php/src AND tests/
   vendor/bin/psalm --show-info=false   # scans php/src only
   ```
   For Rust changes: `cd ext && cargo build --release` (needs `php` on PATH for
   the `ext-php-rs` build script).
7. **One PR per cluster**, with the rig verdict pasted in the body.

---

## Make the bug observable (the #1 trap)

The rig compares **read-back, normalized values**. A surprising number of bugs
are invisible to a naive probe:

- **int32 vs int64 (#44):** both read back as a PHP `int`, so storage width is
  invisible. Probe with a `$type:'int'` / `$type:'long'` query instead.
- **typed exceptions (#38):** you must `catch` and record `get_class($e)` +
  `$e->getCode()`, not let it throw.
- **`upsertedId` / `recordId` / `sizeOnDisk` / auto-`ObjectId`:** these values
  are **non-deterministic per deployment** — never compare them directly.
  Either use an explicit deterministic `_id`, or assert only *presence* /
  *type* (`isset(...)`, `instanceof`), not the value.
- **return container mismatch:** the official driver sometimes returns an
  `Iterator` where zeal returns an `array` (e.g. `listCollectionNames`).
  Normalize both in the probe (`is_array($v) ? $v : iterator_to_array($v)`)
  unless the container type itself is the bug you're fixing.
- **empty `{}` vs `[]`:** an empty BSON document and empty array both become a
  PHP `[]`; probe the *type* the driver wraps it as, not the contents.

If C and Rust would print the same thing even with the bug present, your probe
is wrong, not the driver.

---

## Where things live

```
ext/src/
  lib.rs          #[php_function] bindings + parse_*_options() (option forwarding)
  ops.rs          non-session CRUD/admin ops (the *_with_options variants apply options)
  ops_session.rs  the transaction/session variants of those ops
  bson_convert.rs  PHP <-> BSON value conversion (int width, types, empty docs)
  errconv.rs      mongodb::error::Error -> structured payload for typed exceptions
  coroutine.rs    run_sync() — the single error-encoding leverage point
  cursor.rs       native cursor registry (memory-safety critical, see below)
php/src/
  Collection.php   CRUD entry points + prepareBSON() + client-side validation
  Database.php     admin commands
  Exception/ErrorMapper.php  decodes the ext payload -> typed MongoDB\Driver\Exception\*
  compat/bson_polyfill.php   MongoDB\BSON\* and MongoDB\Driver\Exception\* polyfills
```

### Recurring fix shapes (most issues are one of these)

- **"Option silently dropped"** → the relevant `parse_*_options()` in `lib.rs`
  doesn't read the key. Add `if let Some(v) = arr.get("theOption") { ... }`.
  The op already threads the parsed options via `*_with_options`. (#7, #15,
  #16, #47, #49 ...)
- **"Wrong/`\Exception` instead of a typed MongoDB exception"** → `run_sync`
  flattens `mongodb::error::Error` to a string. `errconv.rs` encodes the real
  `code`/`codeName`/`writeErrors`; `ErrorMapper` decodes them. Wrap the PHP op
  in `self::guard(fn () => ...)`. (#38, #22, #41 ...)
- **"Result field missing/wrong"** → the `*_to_zval` builder in `lib.rs`
  doesn't emit the key (e.g. `upserted_id`), or the PHP result class doesn't
  read it. (#8, #9, #10)
- **"Type lost on read/write"** → `bson_convert.rs`. (#44, #53, #54)
- **"No client-side validation"** → pure PHP guard in `Collection`/`Database`/
  value object, throwing `MongoDB\Exception\InvalidArgumentException`. (#12,
  #14, #48, #50, #56, #66, #68)

### Exception-type parity foundation

`ZealPHP\MongoDB\Exception\*` extend the **official** `MongoDB\*` classes, and
`compat/bson_polyfill.php` defines the `MongoDB\Driver\Exception\*` bases (which
ext-mongodb would otherwise provide). So a thrown zeal exception satisfies
`catch (MongoDB\Driver\Exception\…)` AND `catch (ZealPHP\…)`. When you add a new
exception path, throw a class that **is-a** the official type.

---

## Don't leak memory (non-negotiable)

ZealPHP workers are long-lived OpenSwoole coroutine processes — they never
restart between requests, so **any per-request allocation that isn't freed
grows without bound** (this caused a ~97 GB / 10 h production OOM).

Rules learned the hard way:

- **Native handles in a global map must be removed on every exit path.** Cursors
  live in `cursor.rs`'s `CURSORS` map. They are removed by `cursor_close`,
  `drain_to_vec` (toArray), and — critically — `next_doc` on exhaustion. If you
  add a handle registry (sessions, change streams, async results), give it the
  same treatment: remove on completion/error, not just on an explicit close.
- **The PHP wrapper must guarantee the close**, because `__destruct` timing is
  deferred under coroutine GC. `Cursor::toArray()` calls `cursor_close` before
  nulling the id; mirror that for any handle-owning wrapper.
- **Bound any accumulating map.** `async_store.rs` evicts by TTL so abandoned
  results can't pile up. Unbounded `HashMap` growth keyed by request is a leak.
- When in doubt, add a "drain then assert the registry is empty" check.

---

## Definition of done

- A `parity/` op that *failed before and passes after* your change.
- `VERDICT: FULL PARITY ✓` across **all** ops (no regressions).
- `phpunit` (Unit), `phpcs`, `rector`, `psalm` green; Rust builds clean.
- No new unbounded allocation in a long-lived path.
- One focused PR, rig verdict in the description.

Repeat until the issue tracker is empty and the rig covers every behaviour an
app depends on.
