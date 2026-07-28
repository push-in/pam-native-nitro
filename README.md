# PAM Nitro

**Reactive, offline-first data at native speed.**

PAM Nitro is the high-performance local data engine for PAM Native. It keeps
application startup independent from database size by querying lazily on a
native worker and materializing only the records a screen needs.

> Performance claims in this project are backed by reproducible benchmarks.
> The engineering target is to outperform JSON cache hydration by at least
> 10× in representative mobile workloads.

## Design

- Native SQLite on Android and iOS.
- WAL and prepared-statement optimizations in the PAM runtime.
- Lazy models: no full-database hydration.
- Bounded, indexed, paginated queries.
- Integer-backed enums for coded domain values.
- No reflection in hot query paths; schemas are reflected once and cached.
- No JavaScript, JSI, ORM proxies, or runtime code generation.

WatermelonDB demonstrated the right mobile principle: keep queries native,
lazy, asynchronous, and observable. PAM Nitro applies that principle to PAM's
persistent PHP runtime with fewer transport layers.

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
use Pam\Nitro\Nitro;

Nitro::boot('zechat.db');
Nitro::createTable(Message::class);

Message::query()
    ->where('chat_id', $chatId)
    ->latest()
    ->limit(20)
    ->get(function (array $messages): void {
        $this->messages = array_reverse($messages);
    });
```

## Status

PAM Nitro is under active development. The initial API is intentionally small
while the binary bridge, batch writes, observation, migrations, relations, and
benchmarks are hardened.
