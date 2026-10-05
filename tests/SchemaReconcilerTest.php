<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests;

use Pam\Native\Modules\NativeModules;
use Pam\Nitro\Nitro;
use Pam\Nitro\Schema\ModelSchema;
use Pam\Nitro\Schema\SchemaReconciler;
use Pam\Nitro\Tests\Fixtures\Message;
use Pam\Nitro\Tests\Fixtures\Post;
use Pam\Nitro\Tests\Support\SqliteTransport;
use PHPUnit\Framework\TestCase;

final class SchemaReconcilerTest extends TestCase
{
    private SqliteTransport $native;

    protected function setUp(): void
    {
        Nitro::boot('nitro-test.db');
        SchemaReconciler::forget();
        $this->native = new SqliteTransport();
        NativeModules::useTransport($this->native);
    }

    protected function tearDown(): void
    {
        NativeModules::useTransport(null);
        SchemaReconciler::forget();
    }

    public function testFreshInstallAppliesTheWholeSchemaInOneTransaction(): void
    {
        $ready = $this->prepare([Message::class, Post::class]);

        self::assertTrue($ready);
        // Missing metadata table, full inspection, one DDL transaction.
        self::assertSame(['query', 'query', 'transaction'], $this->native->methods());
        self::assertSame(
            ['messages', 'nitro_messages_chat_id', 'nitro_messages_created_at', 'nitro_meta', 'posts'],
            array_column($this->native->select(
                "SELECT name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name",
            ), 'name'),
        );
        self::assertSame([
            ['key' => 'schema:messages', 'value' => SchemaReconciler::fingerprint(ModelSchema::for(Message::class))],
            ['key' => 'schema:posts', 'value' => SchemaReconciler::fingerprint(ModelSchema::for(Post::class))],
        ], $this->native->select('SELECT "key", "value" FROM nitro_meta ORDER BY "key"'));
    }

    public function testUnchangedSchemaCostsOneNativeCallPerBoot(): void
    {
        $this->prepare([Message::class, Post::class]);
        SchemaReconciler::forget(); // new process, same database file
        $this->native->calls = [];

        self::assertTrue($this->prepare([Message::class, Post::class]));
        self::assertSame(['query'], $this->native->methods());
    }

    public function testAlreadyVerifiedSchemaCostsNoNativeCall(): void
    {
        $this->prepare([Message::class]);
        $this->native->calls = [];

        self::assertTrue($this->prepare([Message::class]));
        self::assertTrue($this->prepare([]));
        self::assertSame([], $this->native->methods());
    }

    public function testIndependentModelGroupsDoNotInvalidateEachOther(): void
    {
        $this->prepare([Message::class]);
        $this->prepare([Post::class]);
        SchemaReconciler::forget();
        $this->native->calls = [];

        $this->prepare([Message::class]);
        $this->prepare([Post::class]);

        self::assertSame(['query', 'query'], $this->native->methods());
    }

    public function testChangedSchemaMigratesInOneTransaction(): void
    {
        $this->prepare([Message::class]);
        $this->native->pdo->exec('DROP TABLE messages');
        $this->native->pdo->exec(
            'CREATE TABLE messages ("id" TEXT PRIMARY KEY, "chat_id" TEXT NOT NULL, "body" TEXT NOT NULL)',
        );
        $this->native->pdo->exec('INSERT INTO messages VALUES (\'m1\', \'c1\', \'legacy\')');
        $this->native->pdo->exec('UPDATE nitro_meta SET "value" = \'stale\'');
        SchemaReconciler::forget();
        $this->native->calls = [];

        self::assertTrue($this->prepare([Message::class]));
        self::assertSame(['query', 'transaction'], $this->native->methods());
        self::assertSame(
            [['id' => 'm1', 'type' => 1, 'created_at' => 0, 'pending' => 0, 'preview' => 'Sem prévia']],
            $this->native->select('SELECT id, type, created_at, pending, preview FROM messages'),
        );
        self::assertCount(2, $this->native->select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'nitro_messages_%'",
        ));
    }

    public function testUpgradeFromUnfingerprintedDatabaseOnlyAddsMissingColumns(): void
    {
        $this->native->pdo->exec(
            'CREATE TABLE posts ("id" TEXT PRIMARY KEY NOT NULL)',
        );

        self::assertTrue($this->prepare([Post::class]));
        self::assertSame(['query', 'query', 'transaction'], $this->native->methods());
        self::assertSame(
            ['id', 'body'],
            array_column($this->native->select('PRAGMA table_info("posts")'), 'name'),
        );
    }

    public function testCreateTableUsesTheSameReconciliation(): void
    {
        $done = false;
        Nitro::createTable(Post::class, static function () use (&$done): void {
            $done = true;
        });

        self::assertTrue($done);
        self::assertSame(['query', 'query', 'transaction'], $this->native->methods());
    }

    public function testFingerprintTracksColumnsAndIndexes(): void
    {
        $message = SchemaReconciler::fingerprint(ModelSchema::for(Message::class));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $message);
        self::assertSame($message, SchemaReconciler::fingerprint(ModelSchema::for(Message::class)));
        self::assertNotSame($message, SchemaReconciler::fingerprint(ModelSchema::for(Post::class)));
    }

    /** @param list<class-string<\Pam\Nitro\Model>> $models */
    private function prepare(array $models): bool
    {
        $ready = false;
        Nitro::prepare($models, static function () use (&$ready): void {
            $ready = true;
        });

        return $ready;
    }
}
