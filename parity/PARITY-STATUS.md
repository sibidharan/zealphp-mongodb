# C-driver parity — status & honest accounting

Companion to [`METHODOLOGY.md`](METHODOLOGY.md). That document is *how* we close
parity issues; this one is *where we are* — a faithful scorecard of all 67 filed
parity issues (`#5`–`#71`), what was fixed and proven, and what genuinely
remains, with the precise technical reason for each.

The rule we held to: **a fix is not "done" unless a new `parity/` rig op
exercises the bug and the rig reports `VERDICT: FULL PARITY ✓`.** That bar means
we do *not* claim weak or unverifiable fixes. The honest consequence is that a
handful of issues are documented here as *not closable by the dual-runtime
value-comparison rig* rather than papered over.

---

## Headline

- **63 of 67** issues closed by PRs **#72–#115**, each with a rig op that
  reproduces the bug and now passes. The rig runs **48 op groups, all
  byte-identical** across the C (`ext-mongodb` + `mongodb/mongodb`) and Rust
  (`zealphp_mongodb`) stacks.
- **4 remain open**, grouped below by *why* the value-comparison rig can't close
  them. None is a silent gap — each has a documented reason and a sketch of what
  closing it would take.
- The original production defect — the `Cursor::toArray()` native-handle leak
  (~97 GB RSS / 10 h OOM) — is fixed in **#72** and guarded by
  `tests/Unit/CursorLeakTest.php`.

---

## Fixed & proven (58)

Closed across PRs #72–#110. By area (each backed by the named rig op or unit
test):

| Area | Issues | Representative rig ops |
|---|---|---|
| Cursor / memory | leak, #14 | `CursorLeakTest`, cursor re-iteration guard |
| CRUD write results | #8, #9, #10, #11, #12 | `write_results`, `insert_ids`, `bulk_ids`, `wc_ack` |
| BSON fidelity | #6, #44, #45, #53, #69, #70 | `bson_containers`, `int_types`, `empty_shapes`, `types`, `bson_sentinel_conflation` |
| Options forwarding | #7, #15, #16, #17, #40, #46, #47, #49 | `aggregate_opts`, `find_opts`, `index_spec`, `type_map`, `pipeline_update` |
| Typed exceptions | #19, #22, #27, #38, #41, #42, #43, #48, #50, #51, #52, #55 | `errors`, `index_conflict`, `map_reduce_parity`, client-side validation |
| Indexes | #18, #56, #57, #58, #59 | `index_flags`, `drop_index_info`, `create_indexes_opts` |
| Admin / client | #31, #32, #33, #34, #35, #36, #71 | `create_coll`, `concern_getters`, `db_info`, `client_manager`, `list_filter` |
| Transactions | #20, #21, #60, #61, #62 | `txn_state`, `txn_commit`, `txn_invalid_read_concern`, `txn_unsat_write_concern` |
| Change streams | #23, #24, #25, #26, #63, #64, #65 | `change_stream`, `cs_fields`, `cs_invalidate`, `cs_fulldoc_deleted` |
| GridFS | #29, #30, #66, #68 | `gridfs`, `gridfs_meta_omit` |
| Server selection | #39, #67 | `server_selection_timeout`, `read_pref_routing` |

---

## Remaining open (4) — and exactly why

These are grouped by the *category of obstacle*, because the obstacle is the
actionable part: it tells the next maintainer what infrastructure or design
change (not just code) closing the issue would require.

### A. Not on the parity surface (2)

These do not affect any `MongoDB\*` public API behaviour, so the rig — which
compares the *observable result of the same application code* — has nothing to
compare.

- **#5 — `zealphp_mongodb_exec_async()` 6th-arg arity.** This is the *raw native*
  function, not the public API. The library-level entry point
  (`AsyncBridge::exec`) already declares `array|null $updateOrPipeline = null`,
  so application code never sees a required-arg break. Making the *native* arg
  optional means `Option<&Zval>`, which the ext-php-rs 0.13 derive macro
  incorrectly marks pass-by-reference (documented at `ext/src/lib.rs:1354`).
  Closing it cleanly waits on an upstream ext-php-rs fix; there is no
  parity-surface impact in the meantime.

- **#37 — headline async is non-functional.** The driver author confirmed this
  is **not a parity regression**: `zealphp/mongodb` is synchronous-by-design
  under OpenSwoole coroutines (`block_on`), and the public CRUD API behaves
  identically to the C driver (which is what the rig proves). The official
  driver's async is a *different axis*, not a behaviour the same code exercises.

### B. Architecturally divergent from zeal's stream model (1)

The official behaviour assumes an internal model zeal does not share, so a
byte-identical result is not expressible without re-architecting that model.

- **#28 — `Bucket::getFileDocumentForStream()` missing.** zeal's download streams
  are plain rewound `php://temp` handles (not wrapper-backed), so there is no
  file-document association to return for a download stream; and upload streams
  buffer until `fclose()`, so `length`/`uploadDate` aren't known mid-stream. The
  official method returns a fully-populated files document. Faithful parity
  requires replacing zeal's buffer-until-close stream model with a chunk-as-you-go
  wrapper — a GridFS rewrite, not a method addition.

> **#23 — `fullDocument: updateLookup` on a deleted doc — CLOSED (PR #114).**
> Previously parked here as a "race." That was wrong: `updateLookup` resolves
> when the update event is *read*, not when it occurs — so deleting the target
> before iterating makes it fully deterministic. The `cs_fulldoc_deleted` op
> does exactly that and both drivers return an explicit `fullDocument: null`
> (zeal's raw change-event docs from #96 already carry the server's null;
> 47/47 ops identical). A reminder that "non-deterministic" deserves a probe
> before it's accepted as a reason.

### C. Not producible / not observable from the PHP value surface (1)

The dual-rig drives *both* drivers from PHP. If a value can't be produced from
PHP, or two correct behaviours yield the same observable value, the rig has no
divergence to catch.

- **#54 — legacy BSON `Symbol`/`Undefined`/`DBPointer` nulled on read.** These
  types **cannot be constructed from PHP** (neither `ext-mongodb` nor zeal exposes
  constructors; they only arise from legacy on-disk data). Investigated to a full
  sketch: the Rust `bson` crate *can* construct `Bson::Symbol`/`Undefined`/
  `DbPointer`, so a test-only ext seed could inject them — but only the **zeal**
  endpoint can run that seed (the C endpoint has no way to write these types), and
  the rig fires the two endpoints independently, so a "seed-then-read" op would
  **race** and could report *false* parity. Beyond the seed, closing it needs
  three new PHP BSON classes, Rust→PHP object instantiation on the read hot path,
  and matching Normalizer rules — for types deprecated since ~2010 with
  effectively zero real-world occurrence. A racy, hot-path change for dead types
  is exactly the disproportionate, hard-to-verify work the methodology says to
  *not* ship as a "fix." Documented, not faked.

> **#45 — Extended-JSON sentinel conflation — CLOSED (PR #115).** Listed here in
> the previous revision as a *design* limitation needing the encoding channel
> replaced — which is exactly what was done. The encoding channel is now the
> typed-OBJECT channel the official driver uses: `prepareBSON` passes real
> `MongoDB\BSON\*` objects to the ext (and normalizes the ZealPHP-namespace ones
> to their official equivalent), the ext encodes each by class and **never**
> reinterprets a plain array by shape, and the ext read path returns real BSON
> objects so `wrapDoc` no longer reconstructs `{$oid:…}`/`{$binary:…}` arrays. A
> literal user document keyed `$oid`/`$binary`/`$numberDecimal`/`$date` is now
> stored and read back verbatim. The `bson_sentinel_conflation` rig op proves it
> (literal sentinel-shaped sub-documents stay documents; a real ObjectId still
> round-trips) — 48/48 ops identical, with `types`/`int_types` unregressed.

> **#67 — per-operation readPreference routing — CLOSED (PR #113).** Listed here
> in the previous revision as a "focused follow-up"; that follow-up has now been
> carried out, exactly as scoped: the ext forwards `readPreference` into
> `FindOptions.selection_criteria`, the deferred-cursor find path is routed
> through `ErrorMapper`, `errconv`/`ErrorMapper` gained a `serverselection` kind
> → `ConnectionTimeoutException` (code 13053), and the `read_pref_routing` rig op
> drives it from a non-`directConnection` client. Both drivers now throw
> `ConnectionTimeoutException` code 13053 on an unsatisfiable secondary
> preference — 46/46 ops identical. (The deeper *routing-to-a-secondary* remains
> value-invariant by nature — a secondary returns the same replicated data — so
> the rig proves the observable facet, the unsatisfiable-preference error.)

---

## How to extend this scorecard

This scorecard is itself living proof of the loop: #60 and #61 started in
category C of an earlier revision ("deep transaction internals, not cleanly
rig-expressible") and were then closed by forwarding the concerns through the
ext and reconciling the commit-time write-concern exception class — exactly the
"here's what it would take" sketch, carried out (and #67 right after, the same
way; and #23 right after, once its "race" turned out to be a probe-able
determinism). When you close one of the four,
follow `METHODOLOGY.md`: add the op that
reproduces it, get `VERDICT: FULL PARITY ✓`, and move its row from "remaining" to
"fixed" here. When you decide one is genuinely out of scope, keep it in its
category with the reason — an honest "not closable this way, here's what it would
take" is worth more than a silent gap.
