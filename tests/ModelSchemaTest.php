<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests;

use Pam\Nitro\Schema\ColumnType;
use Pam\Nitro\Schema\ModelSchema;
use Pam\Nitro\Tests\Fixtures\Message;
use Pam\Nitro\Tests\Fixtures\MessageType;
use Pam\Nitro\Tests\Fixtures\NullableMessage;
use Pam\Nitro\Tests\Fixtures\Post;
use PHPUnit\Framework\TestCase;

final class ModelSchemaTest extends TestCase
{
    public function testReflectsAndCachesModelSchema(): void
    {
        $schema = ModelSchema::for(Message::class);

        self::assertSame($schema, ModelSchema::for(Message::class));
        self::assertSame('messages', $schema->table);
        self::assertSame('id', $schema->primary->name);
        self::assertSame(ColumnType::Integer, $schema->columns[3]->type);
        self::assertSame(MessageType::class, $schema->columns[3]->enum);
    }

    public function testHydratesBackedEnumsWithoutProxies(): void
    {
        $message = Message::hydrate([
            'id' => 'm1',
            'chat_id' => 'c1',
            'body' => 'PAM Native Nitro',
            'type' => 2,
            'created_at' => 123,
            'pending' => 1,
        ]);

        self::assertSame(MessageType::Image, $message->type);
        self::assertSame('c1', $message->chatId);
        self::assertSame(2, $message->attributes()['type']);
        self::assertTrue($message->pending);
        self::assertSame('Sem prévia', $message->preview);
        self::assertSame(
            'Sem prévia',
            ModelSchema::for(Message::class)->columns[6]->default,
        );
    }

    public function testInitializesChildrenRelationsOnceWithTheModel(): void
    {
        $post = Post::hydrate([
            'id' => 'c1',
            'body' => 'PAM Native Nitro',
        ]);

        self::assertArrayHasKey('messages', ModelSchema::for(Post::class)->relations);
        self::assertSame('c1', $post->id);
        self::assertSame(1, count(ModelSchema::for(Post::class)->relations));
    }

    public function testInfersNullableEnumFromThePhpPropertyType(): void
    {
        $schema = ModelSchema::for(NullableMessage::class);
        $column = $schema->columns[1];

        self::assertTrue($column->nullable);
        self::assertNull($column->default);
        self::assertSame(MessageType::class, $column->enum);
        self::assertNull(NullableMessage::hydrate([
            'id' => 'nullable-1',
            'delivery_state' => null,
        ])->deliveryState);
    }
}
