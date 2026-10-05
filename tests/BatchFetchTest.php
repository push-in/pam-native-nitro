<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests;

use Pam\Native\Modules\NativeModules;
use Pam\Nitro\Batch;
use Pam\Nitro\Nitro;
use Pam\Nitro\Schema\SchemaReconciler;
use Pam\Nitro\Tests\Fixtures\Message;
use Pam\Nitro\Tests\Fixtures\MessageType;
use Pam\Nitro\Tests\Fixtures\Post;
use Pam\Nitro\Tests\Support\SqliteTransport;
use PHPUnit\Framework\TestCase;

final class BatchFetchTest extends TestCase
{
    private SqliteTransport $native;

    protected function setUp(): void
    {
        Nitro::boot('nitro-test.db');
        SchemaReconciler::forget();
        $this->native = new SqliteTransport();
        NativeModules::useTransport($this->native);
        Nitro::prepare([Message::class, Post::class], static function (): void {
        });
        $this->native->calls = [];
    }

    protected function tearDown(): void
    {
        NativeModules::useTransport(null);
        SchemaReconciler::forget();
    }

    public function testBatchSendsHeterogeneousWritesInOneTransaction(): void
    {
        $done = false;
        Nitro::batch(static function (Batch $batch): void {
            $batch->save(self::message('m1', 1))
                ->save(self::message('m2', 2))
                ->save(self::message('m3', 3))
                ->save(self::post('p1'))
                ->delete(self::message('m2', 2))
                ->deleteWhere(Message::class, ['created_at' => 3])
                ->execute('UPDATE "posts" SET "body" = ? WHERE "id" = ?', ['edited', 'p1']);
        }, static function () use (&$done): void {
            $done = true;
        });

        self::assertTrue($done);
        self::assertSame(['transaction'], $this->native->methods());
        self::assertSame(
            [['id' => 'm1']],
            $this->native->select('SELECT id FROM messages ORDER BY id'),
        );
        self::assertSame(
            [['id' => 'p1', 'body' => 'edited']],
            $this->native->select('SELECT id, body FROM posts'),
        );
    }

    public function testSavingAnExistingPrimaryKeyReplacesTheRow(): void
    {
        $first = self::message('m1', 1);
        $second = self::message('m1', 9);
        $second->body = 'edited';
        Nitro::save($first);
        Nitro::saveMany([$second]);

        self::assertSame(
            [['id' => 'm1', 'body' => 'edited', 'created_at' => 9]],
            $this->native->select('SELECT id, body, created_at FROM messages'),
        );
    }

    public function testBatchCoalescesConsecutiveIdenticalStatements(): void
    {
        $batch = (new Batch())
            ->save(self::message('m1', 1))
            ->save(self::message('m2', 2))
            ->delete(self::message('m1', 1));
        $statements = $batch->statements();

        self::assertCount(2, $statements);
        self::assertCount(2, $statements[0]['argumentSets'] ?? []);
        self::assertSame(['m1'], $statements[1]['arguments'] ?? null);
    }

    public function testBatchFailureRollsBackEveryStatement(): void
    {
        Nitro::batch(static function (Batch $batch): void {
            $batch->save(self::message('m1', 1))
                ->execute('INSERT INTO "missing_table" VALUES (1)');
        });

        self::assertSame([], $this->native->select('SELECT id FROM messages'));
    }

    public function testEmptyBatchSkipsTheBridge(): void
    {
        $done = false;
        $request = Nitro::batch(static function (): void {
        }, static function () use (&$done): void {
            $done = true;
        });

        self::assertSame(0, $request);
        self::assertTrue($done);
        self::assertSame([], $this->native->methods());
    }

    public function testFetchResolvesSeveralQueriesWithOneNativeResult(): void
    {
        Nitro::batch(static function (Batch $batch): void {
            foreach ([1, 2, 3] as $createdAt) {
                $batch->save(self::message('m'.$createdAt, $createdAt));
            }
            $batch->save(self::post('p1'))->save(self::post('p2'));
        });
        $this->native->calls = [];

        $results = null;
        Nitro::fetch([
            'timeline' => Message::query()->where('chat_id', 'c1')->latest()->limit(2),
            'post' => Post::query()->where('id', 'p2'),
            'empty' => Message::query()->where('chat_id', 'none'),
        ], static function (array $rows) use (&$results): void {
            $results = $rows;
        });

        self::assertSame(['query'], $this->native->methods());
        self::assertIsArray($results);
        self::assertSame(['timeline', 'post', 'empty'], array_keys($results));
        $timeline = $results['timeline'];
        self::assertContainsOnlyInstancesOf(Message::class, $timeline);
        /** @var list<Message> $timeline */
        self::assertSame(['m3', 'm2'], array_map(
            static fn (Message $message): string => $message->id,
            $timeline,
        ));
        $first = $timeline[0];
        self::assertSame(MessageType::Image, $first->type);
        self::assertTrue($first->pending);
        self::assertSame(3, $first->createdAt);
        self::assertInstanceOf(Post::class, $results['post'][0]);
        self::assertSame('p2', $results['post'][0]->id);
        self::assertSame([], $results['empty']);
    }

    public function testFetchRejectsMoreThanTheBridgeRowLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Nitro::fetch([
            Message::query()->limit(1_000),
            Post::query()->limit(1),
        ], static function (): void {
        });
    }

    private static function message(string $id, int $createdAt): Message
    {
        $message = new Message();
        $message->id = $id;
        $message->chatId = 'c1';
        $message->body = 'Body '.$id;
        $message->type = MessageType::Image;
        $message->createdAt = $createdAt;
        $message->pending = true;

        return $message;
    }

    private static function post(string $id): Post
    {
        $post = new Post();
        $post->id = $id;
        $post->body = 'Post '.$id;

        return $post;
    }
}
