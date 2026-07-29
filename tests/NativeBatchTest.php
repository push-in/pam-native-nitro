<?php

declare(strict_types=1);

namespace {
    use Pam\Nitro\Tests\NativeBatchTest;

    if (!function_exists('pam_native_call')) {
        function pam_native_call(
            int $requestId,
            string $module,
            string $method,
            string $payload,
        ): void {
            NativeBatchTest::$call = compact(
                'requestId',
                'module',
                'method',
                'payload',
            );
        }
    }
}

namespace Pam\Nitro\Tests {
    use Pam\Native\Internal\Wire;
    use Pam\Nitro\Nitro;
    use Pam\Nitro\Tests\Fixtures\Message;
    use Pam\Nitro\Tests\Fixtures\MessageType;
    use PHPUnit\Framework\TestCase;

    final class NativeBatchTest extends TestCase
    {
        /** @var array{requestId: int, module: string, method: string, payload: string}|null */
        public static ?array $call = null;

        public function testSaveManyCrossesTheBridgeOnce(): void
        {
            Nitro::boot('nitro-test.db');
            self::$call = null;

            $first = self::message('m1', 'Nitro', 1);
            $second = self::message('m2', 'Fast', 2);
            $requestId = Nitro::saveMany([$first, $second]);

            self::assertNotNull(self::$call);
            self::assertSame($requestId, self::$call['requestId']);
            self::assertSame('sqlite', self::$call['module']);
            self::assertSame('executeMany', self::$call['method']);

            $payload = Wire::decodeMap(self::$call['payload']);
            $rows = json_decode(
                (string) $payload['arguments'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame([
                ['m1', 'c1', 'Nitro', 1, 1, false, 'Sem prévia'],
                ['m2', 'c1', 'Fast', 1, 2, false, 'Sem prévia'],
            ], $rows);
            self::assertStringContainsString('ON CONFLICT', (string) $payload['sql']);
        }

        public function testSaveManyRejectsAnEmptyBatch(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            Nitro::saveMany([]);
        }

        public function testReplaceManyUsesOneAtomicNativeSnapshot(): void
        {
            Nitro::boot('nitro-test.db');
            self::$call = null;

            $requestId = Nitro::replaceMany(
                Message::class,
                [
                    self::message('m3', 'Fresh', 3),
                    self::message('m4', 'Snapshot', 4),
                ],
                ['chat_id' => 'c1'],
            );

            self::assertNotNull(self::$call);
            self::assertSame($requestId, self::$call['requestId']);
            self::assertSame('sqlite', self::$call['module']);
            self::assertSame('transaction', self::$call['method']);
            $payload = Wire::decodeMap(self::$call['payload']);
            $statements = json_decode(
                (string) $payload['arguments'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($statements);
            self::assertIsArray($statements[0] ?? null);
            self::assertIsArray($statements[1] ?? null);
            self::assertIsString($statements[0]['sql'] ?? null);
            self::assertIsArray($statements[0]['arguments'] ?? null);
            self::assertIsString($statements[1]['sql'] ?? null);
            self::assertIsArray($statements[1]['argumentSets'] ?? null);
            self::assertSame(
                'DELETE FROM "messages" WHERE "chat_id" = ?',
                $statements[0]['sql'],
            );
            self::assertSame(['c1'], $statements[0]['arguments']);
            self::assertStringContainsString(
                'ON CONFLICT',
                $statements[1]['sql'],
            );
            self::assertCount(2, $statements[1]['argumentSets']);
        }

        public function testReplaceManyRejectsAnUnscopedDelete(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            Nitro::replaceMany(Message::class, [], []);
        }

        private static function message(string $id, string $body, int $createdAt): Message
        {
            $message = new Message();
            $message->id = $id;
            $message->chatId = 'c1';
            $message->body = $body;
            $message->type = MessageType::Text;
            $message->createdAt = $createdAt;
            $message->pending = false;

            return $message;
        }
    }
}
