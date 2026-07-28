<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests;

use Pam\Nitro\Schema\ColumnType;
use Pam\Nitro\Schema\ModelSchema;
use Pam\Nitro\Tests\Fixtures\Message;
use Pam\Nitro\Tests\Fixtures\MessageType;
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
            'body' => 'PAM Nitro',
            'type' => 2,
            'created_at' => 123,
        ]);

        self::assertSame(MessageType::Image, $message->type);
        self::assertSame('c1', $message->chatId);
        self::assertSame(2, $message->attributes()['type']);
    }
}
