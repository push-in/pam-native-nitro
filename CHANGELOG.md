# Changelog

## Unreleased

- Added the durable offline mutation outbox with idempotency keys, bounded
  payloads, due-work pagination, acknowledgements, exponential retries and
  terminal failure state.
- Added sequential integer-backed mutation operation/state and conflict policy
  enums plus deterministic conflict resolution.
- Added atomic remote delta application with bounded tombstone chunks and
  scoped opaque cursor persistence.

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
