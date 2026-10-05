# Architecture

PAM Native Nitro keeps UI work and persistence work on different execution paths.
PHP describes a bounded query; PAM Native executes it on its SQLite worker;
only the selected scalar rows return to PHP.

## Write path

`Nitro::saveMany()` is the preferred synchronization primitive:

1. model schemas are read from PHP attributes once per process;
2. models become compact positional argument arrays;
3. the complete batch crosses the native bridge once;
4. Android or iOS prepares the upsert statement once;
5. every argument set is bound and executed in one native transaction.

This removes per-record bridge calls, repeated SQL parsing, and repeated
transaction fsyncs from bulk cache hydration.

## Read path

Queries are immutable builders with mandatory bounds. They select rows in
SQLite using indexed predicates, ordering, and a maximum limit of 1,000 rows.
Nitro materializes only the returned records.

## SQLite profile

PAM Native opens application-private databases with:

- WAL journaling;
- `synchronous=NORMAL`;
- foreign keys enabled;
- a 5-second busy timeout;
- memory-backed temporary storage.

Database I/O runs outside the UI renderer: Android uses a dedicated
single-thread executor and iOS a serial dispatch queue, so statements execute
in submission order without blocking the main thread. Both platforms reuse
compiled statements: Android through `SQLiteDatabase`'s per-connection cache,
iOS (PAM Native 1.9.1+) through a 32-entry LRU of prepared `sqlite3_stmt`
handles per database, reset right after each call so cached reads never hold
a WAL snapshot.
Every query result, however many rows, arrives as one module result. A
completed callback schedules only the state change the application requested.

## Boot: fingerprint-gated schema reconciliation

Every model table has a fingerprint (xxh128 of its `CREATE TABLE`, additive
`ALTER TABLE` definitions, indexes and the reconciler version) stored in the
`nitro_meta` table under `schema:<table>`.

`Nitro::prepare()` issues ONE native query that returns only the tables whose
stored fingerprint differs, together with their current columns. The query
reads `sqlite_master`, which pins it to the current schema cookie so pooled
read connections never answer with stale column metadata.

| Situation | Native calls per `prepare()` |
| --- | --- |
| Already verified in this process | 0 |
| Unchanged schema (normal boot) | 1 |
| Changed tables | 2 (query + one DDL transaction) |
| Fresh install or upgrade from Nitro 0.4 | 3 (metadata probe, full inspection, one DDL transaction) |

Every `CREATE TABLE`, missing-column `ALTER TABLE ... DEFAULT`, `CREATE INDEX`
and fingerprint write is applied in one atomic native transaction, so a
failed migration leaves the previous schema and fingerprints intact.
Non-nullable fields carry their reflected PHP default into the `ALTER TABLE`,
so existing rows stay valid and hydrate without a cache reset.

Nitro 0.4 issued `CREATE TABLE`, `PRAGMA table_info` and one call per index
or missing column for each model: 48 sequential round-trips for a nine-model
application on every boot.

## Batched writes and reads

`Nitro::batch()` collects saves, deletes, scoped deletes, snapshot
replacements and raw statements into one native transaction call.
Consecutive statements with identical SQL are coalesced into one prepared
statement executed per argument set.

`Nitro::fetch()` compiles several bounded model queries into one
`UNION ALL` statement whose rows return as one module result; Nitro splits
them back per query and preserves each query's order. The combined row budget
is the bridge limit of 1,000 rows.

## Bridge size limit

PAM Native rejects any module request or result larger than one MiB. Nitro
measures every encoded payload before it crosses the bridge and packs work
under a 768 KiB budget:

- **Writes that fit** remain ONE native call and ONE transaction.
- **Larger transactions** (`batch()`, `replaceMany()`, `saveMany()`, deltas)
  are staged. Rows of every `INSERT ... VALUES (?, ...)` statement are written,
  in as many calls as needed, to `nitro_staging_<width>` under a random token;
  the target tables are not touched. ONE final transaction then runs the
  original statements in order, with each staged insert replaced by
  `INSERT ... SELECT ... ORDER BY "nitro_seq"`, and deletes the token's rows.
  Any failure leaves the target tables unchanged; staging rows are removed by a
  best-effort cleanup call and, at the latest, by the next staged write. Only
  oversized non-insert statements (thousands of raw `UPDATE` argument sets) are
  split into ordered sequential transactions without whole-batch atomicity.
- **Reads** first try ONE query. If the native side reports an oversized
  result, Nitro reads the byte size of every row in one small query, then reads
  consecutive `LIMIT`/`OFFSET` windows under the budget, halving any window
  that still overflows. Rows keep the statement's order; a write issued while a
  paged read is in flight may be visible to later windows only.
- A single row that cannot fit one payload is reported as a failure.

Native failures are delivered to the optional `$failure` callback of every API,
or to `Nitro::onFailure()`, and are never thrown from module result callbacks.

Upserts use `INSERT OR REPLACE` with every model column. That is equivalent to
a primary-key UPSERT for Nitro models and works on SQLite 3.18 (Android 8),
whereas `ON CONFLICT DO UPDATE` requires SQLite 3.24 (Android 11).

## Safety limits

- at most 10,000 argument sets per bulk write;
- at most 1,000 rows and 256 columns per query, paged under one MiB per result;
- every native request and result stays under the one-MiB bridge limit;
- bound values only; identifiers come exclusively from reflected model schema;
- integer-backed enums for every coded domain value.

## Offline mutation path

`SyncQueue` persists every mutation before transport begins. Its idempotency
key is the primary key, payloads are bounded to 1 MiB, entity kinds must be
application-defined integer-backed enums, and state/operation codes are
sequential integer-backed framework enums. Due work is ordered by creation
time and limited to 1,000 entries per pull.

Failed attempts use bounded exponential backoff. Reaching the configured
attempt ceiling records a terminal failure instead of retrying forever.
Acknowledgements remain in the mutation log for application-defined retention
and diagnostics. Conflict resolution is side-effect-free and deterministic;
last-write-wins resolves timestamp ties in favor of the server so multiple
clients converge.

## Delta application

`DeltaApplier` commits server upserts, tombstone IDs and an opaque scoped cursor
inside one native SQLite transaction. Deletes are parameterized in chunks of
500 and a page is bounded to 10,000 total changes. Cursor advancement is the
last statement, including for empty pages, so restart recovery never observes
a partially applied remote page.
