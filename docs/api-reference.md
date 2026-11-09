# API reference

Moved out of the README; nothing was removed.

Namespaces: `Pam\Nitro`, `Pam\Nitro\Attributes`, `Pam\Nitro\Relations`,
`Pam\Nitro\Sync`, `Pam\Nitro\Schema`. Every native call returns the module
request id (`int`). Every `$failure` callback receives the native error
message; calls without one report to `Nitro::onFailure()` (or the PHP error
log).

## `Nitro`

| Method | Description |
| --- | --- |
| `boot(string $database = 'pam-native-nitro.db'): Connection` | Opens (once) the database used by every model. |
| `prepare(array $models, Closure(): void $callback, ?Closure(string) $failure = null)` | Creates/evolves every table and index additively; one fingerprint query per process. |
| `createTable(string $model, ?Closure $callback = null, ?Closure $failure = null)` | Creates one model's table. |
| `query(string $model): Query` | Same as `Model::query()`. |
| `fetch(array $queries, Closure(array<key, list<Model>>) $callback, ?Closure $failure = null)` | Several queries, one native call. |
| `batch(Closure(Batch) $build, ?Closure $callback = null, ?Closure $failure = null)` | Heterogeneous writes in one transaction. |
| `save(Model)`, `delete(Model)`, `saveMany(array $models)` (1–10000), `deleteWhere(string $model, array $scope)` | Writes; `deleteWhere()` needs a non-empty scope. |
| `replaceMany(string $model, array $models, array $scope, ?Closure $callback = null, ?Closure $failure = null)` | Atomically replaces every row of `$scope` with up to 9999 `$models`. |
| `onFailure(?Closure(string) $handler)` | Handler for failures without a `$failure` callback. |
| `connection(): Connection` | The booted connection (`LogicException` before `boot()`). |

## `Model` (abstract)

`abstract static table(): string`; `query(): Query`;
`find(string|int $id, Closure(?static) $callback, ?Closure $failure = null)`;
`save()`, `delete()`; `attributes(): array` (column => value);
`hydrate(array $row): static`. The constructor is final and takes no
arguments: set properties after `new`.

Attributes: `#[PrimaryKey]` (exactly one), `#[Field(?string $name = null, bool $indexed = false, bool $nullable = false)]`,
`#[Children(string $model, string $foreignKey)]` on a `ChildrenRelation`
property (`get(Closure(list<Model>))`). Supported property types: `string`,
`int`, `float`, `bool`, their nullable forms and int-backed enums (stored as
integers). `ColumnType`: `Integer = 1`, `Real`, `Text`, `Blob`.

## `Query` (immutable)

`where(string $column, string|int|float|bool|null $value)`,
`orderBy(string $column, bool $descending = false)`,
`latest(string $column = 'created_at')`, `limit(int $limit)` (clamped to
1–1000), `get(Closure(list<Model>) $callback, ?Closure $failure = null)`,
`first(Closure(?Model) $callback, ?Closure $failure = null)`, `model()`,
`maxRows()`, `toSql()`. Unknown columns throw.

## `Batch`

`save()`, `saveMany()`, `delete()`, `deleteWhere(string $model, array $scope)`,
`replaceMany(string $model, array $models, array $scope)`,
`execute(string $sql, array $arguments = [])`,
`executeMany(string $sql, array $argumentSets)`, `isEmpty()`, `statements()`;
at most 10000 statements.

## `Connection`

`execute(string $sql, array $arguments = [], ?Closure(): void $callback = null, ?Closure(string) $failure = null)`,
`query(string $sql, array $arguments, Closure(list<array>) $callback, ?Closure $failure = null, ?array $columns = null)`,
`executeMany(string $sql, array $argumentSets, …)`,
`transaction(array $statements, …)`, `attempt(string $sql, Closure $callback, Closure $failure)`.
Raw SQL is 1 byte–1 MiB per statement.

## Sync

| API | Description |
| --- | --- |
| `SyncQueue::prepare(Closure $callback, ?Closure $failure = null)` | Creates the outbox table. |
| `SyncQueue::enqueue(BackedEnum $entityKind, string\|int $entityId, MutationOperation $operation, array $payload, ?string $idempotencyKey = null, ?int $now = null, ?Closure $callback = null, ?Closure $failure = null)` | Durable mutation; the idempotency key is the primary key. |
| `SyncQueue::due(Closure(list<OutboxMutation>) $callback, int $limit = 100, ?int $now = null, ?Closure $failure = null)` | Pending and retry-scheduled entries that are due. |
| `SyncQueue::markInFlight(string $key, int $attempts, …)`, `acknowledge(string $key, …)`, `retry(string $key, int $attempts, string $error, ?RetryPolicy $policy = null, …)` | State transitions. |
| `DeltaApplier::prepare(array $models, …)`, `apply(string $scope, string $model, array $upserts, array $deletedIds, string $cursor, …)`, `cursor(string $scope, Closure(?string) $callback, …)` | Server pages (≤ 10000 changes) and their cursor in one transaction. |
| `ConflictResolver::resolve(array $client, array $server, ConflictPolicy $policy, int $clientUpdatedAt, int $serverUpdatedAt, ?Closure $manual = null): array` | Deterministic merge. |
| `RetryPolicy(int $maximumAttempts = 8, int $baseDelaySeconds = 2, int $maximumDelaySeconds = 300)` | `delayForAttempt(int)`; attempts 1–100. |

Enums: `MutationState` (`Pending = 1`, `InFlight`, `RetryScheduled`,
`Acknowledged`, `Failed = 5`), `MutationOperation` (`Upsert = 1`, `Delete = 2`),
`ConflictPolicy` (`ServerWins = 1`, `ClientWins`, `LastWriteWins`,
`Manual = 4`). Records: `OutboxMutation`, `SyncCursor`.

## Errors

`InvalidArgumentException` for invalid models (no or several primary keys,
unsupported property types, unsafe identifiers), unknown query columns, empty
or oversized batches, `saveMany()` outside 1–10000 models, `deleteWhere()`
without a scope, invalid database names or SQL sizes, invalid sync arguments
and retry policies. `LogicException` when querying before `Nitro::boot()` and
for `ConflictPolicy::Manual` without a resolver. SQLite and bridge failures are
never thrown from native callbacks: they go to `$failure` or
`Nitro::onFailure()`. A single row larger than the bridge budget fails before
any native call.

