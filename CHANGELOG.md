# Changelog

## 0.5.1 - 2026-10-05

- Never exceed the PAM Native one-MiB bridge limit. Every write is measured
  before it crosses the bridge: payloads that fit a 768 KiB budget stay one
  native call; larger `batch()`, `replaceMany()`, `saveMany()`, delta and raw
  transactions are split transparently. Long chat histories saved with
  `replaceMany()` no longer fail with "Native module value is too large".
- Oversized transactions stay atomic: rows of every `INSERT ... VALUES`
  statement are staged under a random token in `nitro_staging_<width>` across
  as many calls as needed, then ONE final transaction runs the original
  statements in order (staged inserts become `INSERT ... SELECT`) and clears the
  staging rows. A failure at any step leaves the target tables untouched, so a
  `replaceMany()` scope is never half-replaced. Only oversized non-insert
  statements (for example thousands of raw `UPDATE` argument sets) fall back to
  ordered sequential transactions.
- Reads page when a result does not fit one bridge payload: `Query::get()`,
  `first()`, `Model::find()`, `Nitro::fetch()` and `Connection::query()` measure
  row sizes in one small query and read ordered `LIMIT`/`OFFSET` windows under
  the budget, splitting a window again if escaping still overflows. Results
  that fit keep the single-call path.
- Native failures are delivered to new optional `$failure` callbacks
  (`Nitro::batch()`, `fetch()`, `save()`, `delete()`, `deleteWhere()`,
  `saveMany()`, `replaceMany()`, `prepare()`, `createTable()`, `Query::get()`,
  `first()`, `Model::find()`/`save()`/`delete()`, `Connection` methods,
  `SyncQueue` and `DeltaApplier`) and are never thrown from inside a module
  result callback. Calls without a callback report to `Nitro::onFailure()`, or
  to the PHP error log when no handler is set.
- A single row larger than the bridge limit is reported as a failure before
  any native call instead of crashing.

## 0.5.0 - 2026-10-05

- Boot schema reconciliation is fingerprint-gated: per-table fingerprints of
  columns, migration defaults and indexes live in `nitro_meta`. An unchanged
  schema costs one native query per `Nitro::prepare()` (zero when the process
  already verified it) instead of `CREATE TABLE` + `PRAGMA table_info` + one
  call per index/column per model (48 sequential calls for nine models).
- Changed schemas apply every `CREATE TABLE`, `ALTER TABLE ADD COLUMN`,
  `CREATE INDEX` and fingerprint write in one atomic native transaction.
- Fix fresh installs on pooled Android connections: `PRAGMA table_info` could
  report a stale, empty schema right after `CREATE TABLE` and trigger
  `duplicate column name` failures. Column inspection now reads through
  `sqlite_master`, pinned to the current schema cookie.
- Add `Nitro::batch()` and the fluent `Batch` builder: saves, deletes, scoped
  deletes, snapshot replacements and raw statements in one native transaction,
  coalescing consecutive identical statements into one prepared statement.
- Add `Nitro::fetch()`: several bounded model queries in one native call and
  one module result, keyed like the input and preserving each query's order.
- Model upserts, sync cursors and the mutation outbox use `INSERT OR REPLACE` /
  `INSERT OR IGNORE`, so writes work on SQLite 3.18 (Android 8-10) where
  `ON CONFLICT` clauses are unavailable.
- `Nitro::createTable()` uses the same single-transaction reconciliation.
- Upsert SQL is memoized per model and generated deterministically so native
  statement caches are reused.

## 0.4.1 - 2026-08-25

- Support PAM Native 1.x.

## 0.4.0 - 2026-08-23

- Support PAM Native 0.8 on PHP 8.5.

## 0.3.3 - 2026-08-03

- Added the durable offline mutation outbox with idempotency keys, bounded
  payloads, due-work pagination, acknowledgements, exponential retries and
  terminal failure state.
- Added sequential integer-backed mutation operation/state and conflict policy
  enums plus deterministic conflict resolution.
- Added atomic remote delta application with bounded tombstone chunks and
  scoped opaque cursor persistence.
- Require and certify PAM Native 0.6.2.

## 0.3.1 - 2026-07-29

- Infer nullable columns from nullable PHP property types, including enums.

## 0.3.0 - 2026-07-29

- Add primary-key model deletion and explicitly scoped bulk deletion.

## 0.2.0 - 2026-07-29

- Add `Nitro::replaceMany()` for atomic scoped collection snapshots.
- Delete stale scoped rows and upsert as many as 9,999 replacements through
  one bridge call, one native transaction, and a reused prepared statement.
- Require PAM Native 0.5.13 for heterogeneous native SQLite transactions.

## 0.1.0 - 2026-07-28

- Add attribute-driven models with typed fields, integer enums, primary keys,
  automatic indexes, hydration, and lazy children relations.
- Add immutable bounded queries and asynchronous model lookup.
- Add single-record upsert and `saveMany()` through PAM Native's prepared,
  transactional SQLite bulk-write path.
- Add Android/iOS WAL profile, schema creation, strict static analysis, and
  reproducible performance documentation.
- Validate the initial ZeChat offline migration on a physical Galaxy S23
  Ultra.
