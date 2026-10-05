<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests;

use Pam\Native\Modules\NativeModules;
use Pam\Nitro\Batch;
use Pam\Nitro\Internal\Bridge;
use Pam\Nitro\Nitro;
use Pam\Nitro\Schema\SchemaReconciler;
use Pam\Nitro\Tests\Fixtures\Message;
use Pam\Nitro\Tests\Fixtures\MessageType;
use Pam\Nitro\Tests\Fixtures\Post;
use Pam\Nitro\Tests\Support\SqliteTransport;
use PHPUnit\Framework\TestCase;

/**
 * Every payload crossing the fake bridge is checked against the one-MiB
 * PAM Native wire limit, exactly like the Android codec.
 */
final class BridgeLimitTest extends TestCase
{
    private SqliteTransport $native;

    /** @var list<string> */
    private array $unhandled = [];

    protected function setUp(): void
    {
        Nitro::boot('nitro-test.db');
        SchemaReconciler::forget();
        $this->native = new SqliteTransport();
        NativeModules::useTransport($this->native);
        $this->unhandled = [];
        Nitro::onFailure(function (string $message): void {
            $this->unhandled[] = $message;
        });
        Nitro::prepare([Message::class, Post::class], static function (): void {
        });
        $this->native->calls = [];
        $this->native->largest = 0;
    }

    protected function tearDown(): void
    {
        Nitro::onFailure(null);
        NativeModules::useTransport(null);
        SchemaReconciler::forget();
    }

    public function testReplaceManyLongHistoryIsSplitUnderTheLimitAndCommitsAtomically(): void
    {
        $this->seed('chat-1', 50, 'old');
        $this->seed('chat-2', 3, 'other');
        $this->native->calls = [];
        $records = self::history('chat-1', 4_000, 900);
        self::assertGreaterThan(3 * SqliteTransport::WIRE_LIMIT, self::encodedSize($records));

        $done = false;
        Nitro::batch(
            static fn (Batch $batch) => $batch->replaceMany(Message::class, $records, ['chat_id' => 'chat-1']),
            static function () use (&$done): void {
                $done = true;
            },
            static fn (string $message) => self::fail($message),
        );

        self::assertTrue($done);
        self::assertGreaterThan(4, count($this->native->calls));
        self::assertLessThanOrEqual(SqliteTransport::WIRE_LIMIT, $this->native->largest);
        foreach ($this->native->calls as $call) {
            self::assertLessThanOrEqual(Bridge::BUDGET, $call['bytes']);
        }
        self::assertSame(
            [['total' => 4_000, 'first' => 'chat-1-m0', 'last' => 'chat-1-m999']],
            $this->native->select(
                "SELECT COUNT(*) AS total, MIN(id) AS first, MAX(id) AS last FROM messages WHERE chat_id = 'chat-1'",
            ),
        );
        self::assertSame(
            [['total' => 0]],
            $this->native->select("SELECT COUNT(*) AS total FROM messages WHERE body LIKE 'old%'"),
        );
        self::assertSame(
            [['total' => 3]],
            $this->native->select("SELECT COUNT(*) AS total FROM messages WHERE chat_id = 'chat-2'"),
        );
        self::assertSame(
            [['body' => $records[1234]->body, 'created_at' => 1234, 'pending' => 0, 'type' => 1]],
            $this->native->select("SELECT body, created_at, pending, type FROM messages WHERE id = 'chat-1-m1234'"),
        );
        self::assertSame([['total' => 0]], $this->native->select('SELECT COUNT(*) AS total FROM nitro_staging_7'));
        self::assertSame([], $this->unhandled);
    }

    public function testFailedCommitLeavesTheReplacedScopeUntouched(): void
    {
        $this->seed('chat-1', 20, 'old');
        $this->native->fault = static fn (string $method, string $sql, string $arguments): ?string => str_contains($arguments, ' SELECT \"a0\"')
            ? 'disk I/O error'
            : null;

        $failure = null;
        Nitro::replaceMany(
            Message::class,
            self::history('chat-1', 2_000, 900),
            ['chat_id' => 'chat-1'],
            static fn () => self::fail('A failed replace must not report success.'),
            static function (string $message) use (&$failure): void {
                $failure = $message;
            },
        );

        self::assertSame('disk I/O error', $failure);
        self::assertSame(
            [['total' => 20]],
            $this->native->select("SELECT COUNT(*) AS total FROM messages WHERE chat_id = 'chat-1' AND body LIKE 'old%'"),
        );
        self::assertSame([['total' => 20]], $this->native->select('SELECT COUNT(*) AS total FROM messages'));
        self::assertSame([['total' => 0]], $this->native->select('SELECT COUNT(*) AS total FROM nitro_staging_7'));
        self::assertSame([], $this->unhandled);
    }

    public function testFailureWhileStagingStopsTheSequenceWithoutTouchingTheScope(): void
    {
        $this->seed('chat-1', 5, 'old');
        $calls = 0;
        $this->native->fault = static function () use (&$calls): ?string {
            return ++$calls === 2 ? 'database is locked' : null;
        };

        $failure = null;
        Nitro::batch(
            static fn (Batch $batch) => $batch->replaceMany(
                Message::class,
                self::history('chat-1', 3_000, 900),
                ['chat_id' => 'chat-1'],
            ),
            static fn () => self::fail('A failed batch must not report success.'),
            static function (string $message) use (&$failure): void {
                $failure = $message;
            },
        );

        self::assertSame('database is locked', $failure);
        // Staging call 1, failing staging call 2, then only the cleanup call.
        self::assertCount(3, $this->native->calls);
        self::assertSame([['total' => 5]], $this->native->select('SELECT COUNT(*) AS total FROM messages'));
        self::assertSame([['total' => 0]], $this->native->select('SELECT COUNT(*) AS total FROM nitro_staging_7'));
    }

    public function testStagingKeepsStatementOrderAcrossChunks(): void
    {
        $records = self::history('chat-1', 2_500, 900);
        Nitro::batch(static function (Batch $batch) use ($records): void {
            $batch->saveMany($records)
                ->delete($records[10])
                ->execute('UPDATE "messages" SET "preview" = ? WHERE "id" = ?', ['edited', 'chat-1-m20'])
                ->save(self::message('chat-1-m10', 'chat-1', 'restored', 10));
        }, null, static fn (string $message) => self::fail($message));

        self::assertGreaterThan(1, count($this->native->calls));
        self::assertSame([['total' => 2_500]], $this->native->select('SELECT COUNT(*) AS total FROM messages'));
        self::assertSame(
            [['body' => 'restored']],
            $this->native->select("SELECT body FROM messages WHERE id = 'chat-1-m10'"),
        );
        self::assertSame(
            [['preview' => 'edited']],
            $this->native->select("SELECT preview FROM messages WHERE id = 'chat-1-m20'"),
        );
    }

    public function testSaveManyAboveTheLimitIsSplitAtomically(): void
    {
        $records = self::history('chat-9', 3_000, 700);
        $done = false;
        Nitro::saveMany($records, static function () use (&$done): void {
            $done = true;
        }, static fn (string $message) => self::fail($message));

        self::assertTrue($done);
        self::assertNotContains('executeMany', $this->native->methods());
        self::assertLessThanOrEqual(Bridge::BUDGET, $this->native->largest);
        self::assertSame([['total' => 3_000]], $this->native->select('SELECT COUNT(*) AS total FROM messages'));
    }

    public function testLargeRawUpdatesAreSplitIntoOrderedTransactions(): void
    {
        $this->seed('chat-1', 600, 'old');
        $sets = [];
        for ($index = 0; $index < 600; ++$index) {
            $sets[] = [str_repeat('u', 2_000).$index, 'chat-1-m'.$index];
        }

        $done = false;
        Nitro::connection()->executeMany(
            'UPDATE "messages" SET "body" = ? WHERE "id" = ?',
            $sets,
            static function () use (&$done): void {
                $done = true;
            },
            static fn (string $message) => self::fail($message),
        );

        self::assertTrue($done);
        self::assertSame(['transaction', 'transaction'], $this->native->methods());
        self::assertSame(
            [['total' => 600]],
            $this->native->select("SELECT COUNT(*) AS total FROM messages WHERE body LIKE 'uuu%'"),
        );
    }

    public function testRowLargerThanTheBridgeFailsWithoutThrowing(): void
    {
        $failure = null;
        Nitro::save(
            self::message('huge', 'chat-1', str_repeat('x', SqliteTransport::WIRE_LIMIT), 1),
            static fn () => self::fail('An oversized row must not report success.'),
            static function (string $message) use (&$failure): void {
                $failure = $message;
            },
        );
        Nitro::saveMany(
            [self::message('a', 'chat-1', 'small', 1), self::message('huge', 'chat-1', str_repeat('x', SqliteTransport::WIRE_LIMIT), 2)],
        );

        self::assertIsString($failure);
        self::assertStringContainsString('1 MiB', $failure);
        self::assertCount(1, $this->unhandled);
        self::assertStringContainsString('1 MiB', $this->unhandled[0]);
        self::assertSame([['total' => 0]], $this->native->select('SELECT COUNT(*) AS total FROM messages'));
    }

    public function testQueryResultsAboveTheLimitArePaged(): void
    {
        // Slashes and quotes double when JSON-encoded, so size estimates must
        // be corrected by splitting windows that still exceed the limit.
        $body = str_repeat('/"çã', 300);
        $records = [];
        for ($index = 0; $index < 900; ++$index) {
            $records[] = self::message(sprintf('m%04d', $index), 'chat-1', $body.$index, $index);
        }
        Nitro::saveMany($records);
        $this->native->calls = [];
        $this->native->largest = 0;

        $models = null;
        Message::query()->where('chat_id', 'chat-1')->orderBy('created_at', true)->limit(1_000)->get(
            static function (array $result) use (&$models): void {
                $models = $result;
            },
            static fn (string $message) => self::fail($message),
        );

        self::assertIsArray($models);
        self::assertCount(900, $models);
        self::assertSame('m0899', self::at($models, 0)->id);
        self::assertSame('m0000', self::at($models, 899)->id);
        self::assertSame($body.'450', self::at($models, 449)->body);
        self::assertSame(MessageType::Text, self::at($models, 3)->type);
        self::assertGreaterThan(4, count($this->native->calls));
        self::assertLessThanOrEqual(SqliteTransport::WIRE_LIMIT, $this->native->largest);
    }

    public function testFetchPagesLargeMultiQueryResults(): void
    {
        $records = self::history('chat-1', 700, 3_000);
        Nitro::saveMany($records);
        $post = new Post();
        $post->id = 'p1';
        $post->body = 'post';
        Nitro::save($post);
        $this->native->calls = [];

        $results = null;
        Nitro::fetch([
            'timeline' => Message::query()->where('chat_id', 'chat-1')->orderBy('created_at')->limit(700),
            'post' => Post::query()->limit(1),
        ], static function (array $fetched) use (&$results): void {
            $results = $fetched;
        }, static fn (string $message) => self::fail($message));

        self::assertIsArray($results);
        self::assertCount(700, $results['timeline']);
        self::assertSame('chat-1-m0', self::at($results['timeline'], 0)->id);
        self::assertSame('chat-1-m699', self::at($results['timeline'], 699)->id);
        self::assertSame($records[321]->body, self::at($results['timeline'], 321)->body);
        self::assertCount(1, $results['post']);
        self::assertGreaterThan(2, count($this->native->calls));
    }

    public function testRawQueryWithUnknownColumnsIsPaged(): void
    {
        Nitro::saveMany(self::history('chat-1', 500, 4_000));
        $rows = null;
        Nitro::connection()->query(
            'SELECT id, body FROM messages WHERE chat_id = ? ORDER BY created_at;',
            ['chat-1'],
            static function (array $result) use (&$rows): void {
                $rows = $result;
            },
            static fn (string $message) => self::fail($message),
        );

        self::assertIsArray($rows);
        self::assertCount(500, $rows);
        self::assertSame('chat-1-m499', $rows[499]['id']);
    }

    public function testNativeFailuresWithoutCallbacksReachTheFailureHandler(): void
    {
        Nitro::connection()->query('SELECT * FROM missing_table', [], static fn () => self::fail('Must fail.'));
        Nitro::connection()->execute('DELETE FROM missing_table');
        Nitro::batch(static fn (Batch $batch) => $batch->execute('UPDATE missing_table SET a = 1'));

        self::assertCount(3, $this->unhandled);
        foreach ($this->unhandled as $message) {
            self::assertStringContainsString('missing_table', $message);
        }
    }

    public function testSingleQueryRowAboveTheLimitIsReportedAsFailure(): void
    {
        $this->native->pdo->exec(
            "INSERT INTO messages VALUES ('big', 'chat-1', '".str_repeat('/', 600_000)."', 1, 1, 0, 'p')",
        );
        $failure = null;
        Message::find('big', static fn () => self::fail('Must fail.'), static function (string $message) use (&$failure): void {
            $failure = $message;
        });

        self::assertIsString($failure);
        self::assertStringContainsString('too large', $failure);
    }

    /** @param array<mixed> $models */
    private static function at(array $models, int $index): Message
    {
        $model = $models[$index] ?? null;
        self::assertInstanceOf(Message::class, $model);

        return $model;
    }

    private function seed(string $chat, int $count, string $prefix): void
    {
        $records = [];
        for ($index = 0; $index < $count; ++$index) {
            $records[] = self::message($chat.'-m'.$index, $chat, $prefix.' '.$index, $index);
        }
        Nitro::saveMany($records);
        $this->native->calls = [];
    }

    /** @return list<Message> */
    private static function history(string $chat, int $count, int $bytes): array
    {
        $records = [];
        for ($index = 0; $index < $count; ++$index) {
            $records[] = self::message(
                $chat.'-m'.$index,
                $chat,
                json_encode([
                    'text' => str_repeat('Olá, mundo! ', intdiv($bytes, 13)),
                    'url' => 'https://example.com/'.$index,
                    'index' => $index,
                ], JSON_THROW_ON_ERROR),
                $index,
            );
        }

        return $records;
    }

    /** @param list<Message> $records */
    private static function encodedSize(array $records): int
    {
        return strlen(json_encode(array_map(
            static fn (Message $message): array => array_values($message->attributes()),
            $records,
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function message(string $id, string $chat, string $body, int $createdAt): Message
    {
        $message = new Message();
        $message->id = $id;
        $message->chatId = $chat;
        $message->body = $body;
        $message->type = MessageType::Text;
        $message->createdAt = $createdAt;
        $message->pending = false;

        return $message;
    }
}
