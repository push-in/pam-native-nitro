# Models, queries, sync and schema evolution

Moved out of the README; nothing was removed.

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

## A real example: Zé Chat

Zé Chat keeps twelve models (chats, messages, drafts, participants, topics,
the media outbox, sync cursors, contacts…) in one database. A single owner
boots it and reconciles every schema with ONE `Nitro::prepare()`; operations
requested before the schema is ready are queued:

```php
use Pam\Nitro\{Model, Nitro};

final class NitroStore
{
    public const array MODELS = [ChatRecord::class, MessageRecord::class, ChatDraftRecord::class /* … */];

    private static bool $ready = false;
    private static bool $booting = false;
    /** @var list<Closure(): int> */
    private static array $pending = [];

    public static function ready(Closure $operation): int
    {
        if (self::$ready) {
            return $operation();
        }
        self::$pending[] = $operation;
        if (!self::$booting) {
            self::$booting = true;
            // The offline cache is best effort: failures are logged, the server stays the source of truth.
            Nitro::onFailure(static fn (string $message) => error_log('Nitro failure: '.$message));
            Nitro::boot('zechat-nitro.db');
            Nitro::prepare(self::MODELS, static function (): void {
                self::$ready = true;
                foreach (array_splice(self::$pending, 0) as $operation) {
                    $operation();
                }
            });
        }
        return 0;
    }
}

// Inbox refresh: the user's chat list is replaced atomically (no stale rows, no empty window).
NitroStore::ready(fn () => Nitro::replaceMany(ChatRecord::class, $records, ['user_id' => $userId]));

// Opening a conversation: four side tables in ONE native call.
NitroStore::ready(fn () => Nitro::fetch([
    'draft' => ChatDraftRecord::query()->where('user_id', $userId)->where('chat_id', $chatId)->limit(1),
    'topics' => ChatTopicRecord::query()->where('user_id', $userId)->where('chat_id', $chatId)->orderBy('position')->limit(200),
    'participants' => ChatParticipantRecord::query()->where('user_id', $userId)->where('chat_id', $chatId)->orderBy('position')->limit(1000),
], function (array $results): void {
    $this->draft = $results['draft'][0] ?? null;
    $this->topics = $results['topics'];
    $this->participants = $results['participants'];
}));
```

Models are plain typed classes (`#[PrimaryKey]`, `#[Field(indexed: true)]`,
defaults for every new column). Column names are the snake_case form of the
property (`chatId` → `chat_id`) unless `#[Field(name: …)]` overrides them.

