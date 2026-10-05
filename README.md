<!-- pam:product-page:start -->
<div align="center">

# PAM Native Nitro

**An offline-first data engine for instant native applications.**

Query indexed local data, observe incremental changes, and keep large datasets responsive through a typed storage boundary.

[![Latest version](https://img.shields.io/packagist/v/pushinbr/pam-native-nitro?style=flat-square&label=stable)](https://packagist.org/packages/pushinbr/pam-native-nitro)
[![CI](https://img.shields.io/github/actions/workflow/status/push-in/pam-native-nitro/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/push-in/pam-native-nitro/actions)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white)
![Android](https://img.shields.io/badge/Android-API%2026%2B-3DDC84?style=flat-square&logo=android&logoColor=white)
![iOS](https://img.shields.io/badge/iOS-15%2B-000000?style=flat-square&logo=apple&logoColor=white)

**[Documentation](https://push-in.github.io/pam-docs/native/overview/) · [Quick start](#quick-start) · [What you can build](#what-you-can-build) · [PAM ecosystem](https://push-in.github.io/pam-docs/ecosystem/) · [Issues](https://github.com/push-in/pam-native-nitro/issues)**

</div>

---

## Why PAM Native Nitro

Query indexed local data, observe incremental changes, and keep large datasets responsive through a typed storage boundary. The public API is strictly typed for PHP 8.5; expensive or frame-sensitive work stays in Rust or the platform SDK instead of crossing the application boundary every frame.

| | |
| --- | --- |
| **Best for** | A focused capability you can add to any PAM Native application |
| **Native path** | Native indexed storage · Incremental observers |
| **Application model** | Composer package + generated native integration |
| **Design rule** | Independent module; no feed, vertical, or application template bundled |

## What you can build

- Offline catalogs and field applications
- Large local timelines and searchable datasets
- Reactive caches backed by durable native storage

## Quick start

Already have a PAM Native project? Add only this capability:

```bash
pam composer require pushinbr/pam-native-nitro
pam doctor --fix
```

New to PAM? Follow the **[five-minute PAM Native setup](https://push-in.github.io/pam-docs/native/overview/)** once, then return here. Your application stays a normal Composer project with a committed lockfile.
<!-- pam:product-page:end -->

**Offline-first data at native speed.**

PAM Native Nitro is the high-performance local data engine for PAM Native. It keeps
application startup independent from database size by querying lazily on a
native worker and materializing only the records a screen needs.

## Part of the PAM ecosystem

Nitro is an extension of [PAM Native](https://github.com/push-in/pam-native),
the native Android and iOS runtime that keeps PHP alive in the application
process and renders real platform controls without JavaScript or WebViews.

- [PAM Native core](https://github.com/push-in/pam-native) — runtime, renderer,
  navigation, native modules and tooling required by Nitro.
- [PAM Native documentation](https://push-in.github.io/pam-docs/native/overview/)
  — install and understand the native runtime first.
- [Nitro documentation](https://push-in.github.io/pam-docs/packages/native-nitro/) —
  models, schemas, queries, observability and offline-first patterns.
- [PAM platform](https://github.com/push-in/pam) — the persistent PHP server
  runtime and the wider ecosystem.

> Performance claims in this project are backed by reproducible benchmarks.
> The engineering target is to outperform JSON cache hydration by at least
> 10× in representative mobile workloads.

## Design

- Native SQLite on Android and iOS.
- WAL, `synchronous=NORMAL` and prepared-statement reuse in the PAM runtime.
- One native round-trip per boot to verify the schema fingerprint.
- `Nitro::batch()` and `Nitro::fetch()` for one-call writes and reads.
- Payloads never exceed the one-MiB PAM Native bridge: large writes are staged
  and committed atomically, large results are paged.
- Lazy models: no full-database hydration.
- Bounded, indexed, paginated queries.
- Integer-backed enums for coded domain values.
- Additive schema evolution without dropping cached rows.
- Atomic scoped snapshot replacement without stale rows or empty-cache windows.
- Durable idempotent mutation outbox with bounded exponential retry.
- Integer-backed mutation states, operations and conflict policies.
- No reflection in hot query paths; schemas are reflected once and cached.
- No JavaScript, JSI, ORM proxies, or runtime code generation.

WatermelonDB demonstrated the right mobile principle: keep queries native,
lazy, asynchronous, and observable. PAM Native Nitro applies that principle to PAM's
persistent PHP runtime with fewer transport layers.

Read the [architecture](docs/architecture.md) and
[benchmark protocol](docs/benchmarks.md) before evaluating performance claims.

PAM Native Nitro 0.5 supports PAM Native 0.8 through 1.x on PHP 8.5 and
Android 8 (API 26) or newer.

## Models

```php
use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\Children;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;
use Pam\Nitro\Relations\ChildrenRelation;

enum MessageType: int
{
    case Text = 1;
    case Image = 2;
}

final class Message extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $id;

    #[Field(indexed: true)]
    public string $chatId;

    #[Field]
    public string $body;

    #[Field]
    public MessageType $type;

    #[Field(indexed: true)]
    public int $createdAt;

    public static function table(): string
    {
        return 'messages';
    }
}
```

Relations are declared once and stay lazy:

```php
final class Chat extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $id;

    #[Children(Message::class, foreignKey: 'chat_id')]
    public ChildrenRelation $messages;

    public static function table(): string
    {
        return 'chats';
    }
}

$chat->messages->get(function (array $messages): void {
    // Only this chat's rows cross the native boundary.
});
```

## Use

```php
use Pam\Nitro\Batch;
use Pam\Nitro\Nitro;

Nitro::boot('zechat.db');
Nitro::prepare([Message::class], function () use ($chatId): void {
    Message::query()
        ->where('chat_id', $chatId)
        ->latest()
        ->limit(20)
        ->get(function (array $messages): void {
            $this->messages = array_reverse($messages);
        });
});

// Many heterogeneous writes: one native call, one transaction.
Nitro::batch(function (Batch $batch) use ($chat, $messages, $draft): void {
    $batch->save($chat)
        ->saveMany($messages)
        ->delete($draft)
        ->execute('UPDATE "chats" SET "unread" = 0 WHERE "id" = ?', [$chat->id]);
}, function (): void {
    // Committed atomically.
});

// Several screens' worth of data: one native call, one result.
Nitro::fetch([
    'chats' => Chat::query()->limit(50),
    'messages' => Message::query()->where('chat_id', $chatId)->latest()->limit(30),
], function (array $results): void {
    [$this->chats, $this->messages] = [$results['chats'], $results['messages']];
});

Nitro::saveMany($messages, function (): void {
    // Thousands of upserts, one bridge call, one prepared statement,
    // one native transaction.
});

Nitro::replaceMany(
    Message::class,
    $freshMessages,
    ['chat_id' => $chatId],
    function (): void {
        // The old chat snapshot and every new upsert commit atomically,
        // even when the snapshot is larger than one bridge payload.
    },
    function (string $error): void {
        // Native failures arrive here; nothing is thrown.
    },
);

// Failures of calls made without a $failure callback.
Nitro::onFailure(fn (string $error) => error_log($error));

$message->delete();

Nitro::deleteWhere(
    Message::class,
    ['chat_id' => $chatId, 'pending' => false],
);
```

## Durable offline mutations

Queue a local mutation before starting network work. The idempotency key is
stored as the outbox primary key, so replaying the same user action updates one
durable entry instead of creating duplicate server writes:

```php
use Pam\Nitro\Sync\MutationOperation;
use Pam\Nitro\Sync\SyncQueue;

enum SyncEntityKind: int
{
    case Message = 1;
}

SyncQueue::enqueue(
    SyncEntityKind::Message,
    $message->id,
    MutationOperation::Upsert,
    $message->attributes(),
    idempotencyKey: $clientMutationId,
);
```

Workers request only due pending/retry entries, mark an attempt in flight, and
either acknowledge it or schedule a bounded exponential retry. Acknowledged
and terminally failed rows stay in the log until application retention policy
removes them, preserving diagnostics and exactly-once intent across process
death.

Conflict resolution is explicit and deterministic through integer-backed
`ConflictPolicy` cases: server wins, client wins, last write wins (server wins
ties), or an application-provided manual resolver.

Incoming server pages use `DeltaApplier`: row upserts, tombstone deletions and
the new opaque cursor commit through one native SQLite transaction. A crash can
therefore never advance the cursor without its data, or apply data without the
matching cursor. Empty pages still advance the cursor and deletion identifiers
are parameterized in bounded chunks.

Model deletion always uses its primary key. `deleteWhere()` requires an
explicit non-empty scope, so an accidental table-wide delete is rejected.

## Schema evolution

`Nitro::prepare()` stores a fingerprint of every table's columns and indexes in
`nitro_meta`. A normal boot costs one native query; a process that already
verified the schema costs none. When a model changes, every new table, missing
column and index is applied in one native transaction, preserving all cached
rows. Prepare all models in one `Nitro::prepare()` call to pay that single
query once. Give a new non-nullable property a domain-safe default:

```php
#[Field]
public string $preview = '';
```

Older rows hydrate with that default immediately. Nullable fields migrate to
`NULL`; integer-backed enums use the first sequential case when no explicit
property default exists. Destructive renames and type changes remain explicit
application migrations.

## Status

PAM Native Nitro is under active development. The initial API is intentionally small
while the binary bridge, batch writes, observation, destructive migrations, relations, and
benchmarks are hardened.
