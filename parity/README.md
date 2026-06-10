# Dual-runtime parity + memory rig

Proves the Rust driver (`zealphp_mongodb.so` + `zealphp/mongodb`) behaves
**identically** to the official C driver (`ext-mongodb` + `mongodb/mongodb`)
— and stays memory-bounded — by running the **same service code**
(`src/ParityApi.php`) on both stacks against the same MongoDB replica set:

| Path | Stack | Entry |
|------|-------|-------|
| **C** | Apache + mod_php + pecl `mongodb` + `mongodb/mongodb` 2.x | `public/api.php` |
| **Rust** | ZealPHP + OpenSwoole + `zealphp_mongodb.so` + `zealphp/mongodb` | `app.php` |

The driver is picked at runtime by which extension is loaded — no code
forks. Results are canonicalized by `src/Normalizer.php` and deep-diffed.

## Ops covered

`reset` (deterministic seed) · `crud` (insert/find/update/upsert/
findOneAnd*/replace/delete) · `query` (sort/limit/skip/projection/nested/
$or/regex/distinct/count) · `aggregate` ($match/$group/$sort/$unwind) ·
`types` (ObjectId, UTCDateTime, Decimal128, Binary, Regex, MinKey/MaxKey,
unicode, nested arrays) · `indexes` · `bulk` · `txn_commit` / `txn_abort`
(visibility inside vs after) · `change_stream` (pipeline-filtered events +
resume token) · `gridfs` (multi-chunk round-trip, revisions, metadata,
delete).

## Run

```bash
# parity: every op, both paths, byte-diffed
php scripts/run-parity.php http://127.0.0.1:8089/api.php http://127.0.0.1:8090/api

# memory soak: mixed workload, RSS sampled per path
scripts/soak.sh http://127.0.0.1:8089/api.php 'apache2'          3000
scripts/soak.sh http://127.0.0.1:8090/api     'parity/app[.]php' 3000
```

Requires a **replica set** (transactions + change streams):
`PARITY_MONGODB_URI=mongodb://host:27036/?replicaSet=rs0`.
