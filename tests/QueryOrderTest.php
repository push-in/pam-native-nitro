<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests;

use InvalidArgumentException;
use Pam\Native\Modules\NativeModules;
use Pam\Nitro\Batch;
use Pam\Nitro\Nitro;
use Pam\Nitro\Schema\SchemaReconciler;
use Pam\Nitro\Tests\Fixtures\Message;
use Pam\Nitro\Tests\Fixtures\MessageType;
use Pam\Nitro\Tests\Support\SqliteTransport;
use PHPUnit\Framework\TestCase;

final class QueryOrderTest extends TestCase
{
    private SqliteTransport $native;

    protected function setUp(): void
    {
        Nitro::boot('nitro-test.db');
        SchemaReconciler::forget();
        $this->native = new SqliteTransport();
        NativeModules::useTransport($this->native);
        Nitro::prepare([Message::class], static function (): void {
        });
        $this->native->forgetCalls();
    }

    protected function tearDown(): void
    {
        NativeModules::useTransport(null);
        SchemaReconciler::forget();
    }

    public function testChainedOrderByAppendsKeysInPriorityOrder(): void
    {
        [$sql, $arguments] = Message::query()
            ->where('chat_id', 'c1')
            ->orderBy('created_at')
            ->orderBy('id', descending: true)
            ->limit(20)
            ->toSql();

        self::assertSame(
            'SELECT * FROM "messages" WHERE "chat_id" = ? ORDER BY "created_at" ASC, "id" DESC LIMIT 20',
            $sql,
        );
        self::assertSame(['c1'], $arguments);
    }

    public function testListOrderByUsesOneDirectionForEveryColumn(): void
    {
        self::assertSame(
            'SELECT * FROM "messages" ORDER BY "created_at" DESC, "id" DESC, "chat_id" ASC LIMIT 100',
            Message::query()->orderBy(['created_at', 'id'], descending: true)->orderBy('chat_id')->toSql()[0],
        );
        self::assertSame(
            'SELECT * FROM "messages" ORDER BY "created_at" DESC, "id" ASC LIMIT 100',
            Message::query()->latest()->orderBy('id')->toSql()[0],
        );
    }

    public function testSingleOrderByKeepsTheExistingSql(): void
    {
        self::assertSame(
            'SELECT * FROM "messages" ORDER BY "created_at" ASC LIMIT 100',
            Message::query()->orderBy('created_at')->toSql()[0],
        );
        self::assertSame('SELECT * FROM "messages" LIMIT 100', Message::query()->toSql()[0]);
    }

    public function testOrderByIsImmutable(): void
    {
        $base = Message::query()->orderBy('created_at');
        $tieBroken = $base->orderBy('id');

        self::assertSame('SELECT * FROM "messages" ORDER BY "created_at" ASC LIMIT 100', $base->toSql()[0]);
        self::assertSame(
            'SELECT * FROM "messages" ORDER BY "created_at" ASC, "id" ASC LIMIT 100',
            $tieBroken->toSql()[0],
        );
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function invalidOrders(): iterable
    {
        yield 'unknown column' => [static fn () => Message::query()->orderBy('missing')];
        yield 'unknown column in list' => [static fn () => Message::query()->orderBy(['created_at', 'missing'])];
        yield 'empty list' => [static fn () => Message::query()->orderBy([])];
        yield 'map instead of list' => [static fn () => Message::query()->orderBy(['created_at' => 'id'])];
        yield 'repeated column' => [static fn () => Message::query()->latest()->orderBy('created_at')];
        yield 'repeated column in list' => [static fn () => Message::query()->orderBy(['id', 'id'])];
    }

    /** @param \Closure(): mixed $order */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrders')]
    public function testInvalidOrdersThrow(\Closure $order): void
    {
        $this->expectException(InvalidArgumentException::class);
        $order();
    }

    /**
     * Outbox-style rows created in the same second keep a stable order after
     * one of them is rewritten: INSERT OR REPLACE gives it a new rowid, so the
     * tie must be broken by a model column, not by insertion order.
     */
    public function testSecondKeyKeepsTiesStableAcrossRewrites(): void
    {
        Nitro::batch(static function (Batch $batch): void {
            foreach (['a', 'b', 'c'] as $id) {
                $batch->save(self::message($id, 100));
            }
            $batch->save(self::message('z', 99));
        });
        $rowidBefore = $this->native->select("SELECT rowid AS r FROM messages WHERE id = 'a'")[0]['r'];

        $rewritten = self::message('a', 100);
        $rewritten->body = 'edited';
        Nitro::save($rewritten);

        self::assertNotSame(
            $rowidBefore,
            $this->native->select("SELECT rowid AS r FROM messages WHERE id = 'a'")[0]['r'],
            'INSERT OR REPLACE re-inserts the row (documented in Sql::upsert()).',
        );

        $ids = null;
        Message::query()
            ->where('chat_id', 'c1')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(static function (array $messages) use (&$ids): void {
                $ids = array_map(
                    static fn (object $message): string => $message instanceof Message ? $message->id : '',
                    $messages,
                );
            });

        self::assertSame(['z', 'a', 'b', 'c'], $ids);
    }

    private static function message(string $id, int $createdAt): Message
    {
        $message = new Message();
        $message->id = $id;
        $message->chatId = 'c1';
        $message->body = 'Body '.$id;
        $message->type = MessageType::Text;
        $message->createdAt = $createdAt;
        $message->pending = true;

        return $message;
    }
}
