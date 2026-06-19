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

- **58 of 67** issues closed by PRs **#72–#110**, each with a rig op that
  reproduces the bug and now passes. The rig runs **43 op groups, all
  byte-identical** across the C (`ext-mongodb` + `mongodb/mongodb`) and Rust
  (`zealphp_mongodb`) stacks.
- **9 remain open**, grouped below by *why* the value-comparison rig can't close
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
| BSON fidelity | #6, #44, #53, #69, #70 | `bson_containers`, `int_types`, `empty_shapes`, `types` |
| Options forwarding | #7, #15, #16, #17, #40, #46, #47, #49 | `aggregate_opts`, `find_opts`, `index_spec`, `type_map`, `pipeline_update` |
| Typed exceptions | #19, #22, #27, #38, #41, #42, #43, #48, #50, #51, #52, #55 | `errors`, `index_conflict`, `map_reduce_parity`, client-side validation |
| Indexes | #18, #56, #57, #58, #59 | `index_flags`, `drop_index_info`, `create_indexes_opts` |
| Admin / client | #31, #32, #33, #34, #35, #36, #71 | `create_coll`, `concern_getters`, `db_info`, `client_manager`, `list_filter` |
| Transactions | #20, #21, #62 | `txn_state`, `txn_commit`, `txn_abort` |
| Change streams | #24, #25, #26, #63, #64, #65 | `change_stream`, `cs_fields`, `cs_invalidate` |
| GridFS | #29, #30, #66, #68 | `gridfs`, `gridfs_meta_omit` |
| Server selection | #39 | `server_selection_timeout` |

---

## Remaining open (9) — and exactly why

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

### B. Architecturally divergent from zeal's stream model (2)

The official behaviour assumes an internal model zeal does not share, so a
byte-identical result is not expressible without re-architecting that model.

- **#28 — `Bucket::getFileDocumentForStream()` missing.** zeal's download streams
  are plain rewound `php://temp` handles (not wrapper-backed), so there is no
  file-document association to return for a download stream; and upload streams
  buffer until `fclose()`, so `length`/`uploadDate` aren't known mid-stream. The
  official method returns a fully-populated files document. Faithful parity
  requires replacing zeal's buffer-until-close stream model with a chunk-as-you-go
  wrapper — a GridFS rewrite, not a method addition.

- **#23 — `fullDocument: updateLookup` omits the key when the doc was deleted
  before lookup.** Reproducing this needs an update event whose target document
  is deleted *in the window* before the change stream resolves the lookup — a
  race that cannot be staged deterministically in a value-comparison rig (the
  two stacks would observe different interleavings).

### C. Deep transaction internals + cross-driver option-shape divergence (2)

Both need the Rust `start_transaction` to forward concern options (it currently
forwards only `maxCommitTimeMS`), **and** they hit an input-shape divergence the
shared rig op can't express cleanly: the official `Session::startTransaction`
takes a `MongoDB\Driver\ReadConcern`/`WriteConcern` *object*, while zeal's takes
an array — and zeal's own `ReadConcern` (post-#68) validates levels *client-side*,
so an invalid level would throw client-side on zeal but server-side on the C
driver (different class/code), a new divergence rather than a fix.

- **#60 — transaction-level writeConcern dropped** (unsatisfiable `w:5` commit
  succeeds). Also needs an unsatisfiable-`w` replica topology to be *observable*
  without hanging — the rig's replica set can't make `w:5` deterministically
  unsatisfiable-yet-fast-failing.
- **#61 — invalid transaction readConcern level silently accepted.** The most
  deterministic of the two server-side, but blocked by the same option-shape and
  client-vs-server validation-point divergence above.

Closing these is a real project: forward the concerns through the ext, decide
the client-vs-server validation boundary to match the C driver exactly, and
provision a multi-node replica set with a known voting configuration.

### D. Not producible / not observable from the PHP value surface (3)

The dual-rig drives *both* drivers from PHP. If a value can't be produced from
PHP, or two correct behaviours yield the same observable value, the rig has no
divergence to catch.

- **#54 — legacy BSON `Symbol`/`Undefined`/`DBPointer` nulled on read.** These
  types **cannot be constructed from PHP** (neither `ext-mongodb` nor zeal exposes
  constructors; they only arise from legacy on-disk data). The rig cannot
  *produce* them to compare. Closing it needs a fixture of pre-seeded legacy BSON
  injected below the PHP layer, plus read-path decoders on the Rust side.

- **#45 — Extended-JSON sentinel conflation.** A literal user sub-document whose
  single key is `$oid`/`$date`/`$numberDecimal`/… is indistinguishable from an
  Extended-JSON sentinel under zeal's string-channel encoding. This is a *design*
  limitation of that channel (the C driver uses a typed BSON channel with no such
  ambiguity); fixing it means replacing the encoding channel, not a value tweak.
  Extremely rare in real documents.

- **#67 — per-operation readPreference routing ignored.** On a replica set a
  secondary returns the *same data* as the primary, so routing is **not
  value-observable**; an unsatisfiable preference needs a specific tagged
  topology, and `directConnection` bypasses routing entirely. Verifying it needs
  a tagged multi-node set plus server-introspection (which member served the
  read) that the value rig doesn't capture.

---

## How to extend this scorecard

When you close one of the nine, follow `METHODOLOGY.md`: add the op that
reproduces it, get `VERDICT: FULL PARITY ✓`, and move its row from "remaining" to
"fixed" here. When you decide one is genuinely out of scope, keep it in its
category with the reason — an honest "not closable this way, here's what it would
take" is worth more than a silent gap.
