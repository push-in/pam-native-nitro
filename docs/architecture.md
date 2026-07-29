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

Database I/O runs outside the UI renderer. A completed callback schedules only
the state change the application requested.

## Additive migrations

Preparation reads SQLite table metadata and adds missing model columns in
declaration order. Non-nullable fields carry their reflected PHP default into
the `ALTER TABLE`, so existing rows stay valid and hydrate without a cache
reset. Index creation runs only after reconciliation completes.

## Safety limits

- at most 10,000 argument sets per bulk write;
- at most 1,000 rows and 256 columns per bridged query;
- bound values only; identifiers come exclusively from reflected model schema;
- integer-backed enums for every coded domain value.
