<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests;

use Pam\Native\Internal\Runtime;
use Pam\Native\Internal\Wire;
use Pam\Native\ModuleResultStatus;
use Pam\Nitro\Nitro;
use Pam\Nitro\Sync\ConflictPolicy;
use Pam\Nitro\Sync\ConflictResolver;
use Pam\Nitro\Sync\DeltaApplier;
use Pam\Nitro\Sync\MutationOperation;
use Pam\Nitro\Sync\MutationState;
use Pam\Nitro\Sync\OutboxMutation;
use Pam\Nitro\Sync\RetryPolicy;
use Pam\Nitro\Sync\SyncQueue;
use Pam\Nitro\Tests\Fixtures\SyncEntityKind;
use Pam\Nitro\Tests\Fixtures\InvalidSyncEntityKind;
use Pam\Nitro\Tests\Fixtures\Message;
use PHPUnit\Framework\TestCase;

final class SyncQueueTest extends TestCase
{
    protected function setUp(): void
    {
        Nitro::boot('nitro-test.db');
        NativeBatchTest::$call = null;
    }

    public function testProtocolEnumsAreSequentialIntegers(): void
    {
        self::assertSame([1, 2], array_column(MutationOperation::cases(), 'value'));
        self::assertSame([1, 2, 3, 4, 5], array_column(MutationState::cases(), 'value'));
        self::assertSame([1, 2, 3, 4], array_column(ConflictPolicy::cases(), 'value'));
    }

    public function testEnqueuesAnIdempotentMutationAsOneUpsert(): void
    {
        $requestId = SyncQueue::enqueue(
            SyncEntityKind::Message,
            'message-42',
            MutationOperation::Upsert,
            ['body' => 'offline'],
            'mutation:message:0001',
            1_000,
        );

        self::assertNotNull(NativeBatchTest::$call);
        self::assertSame($requestId, NativeBatchTest::$call['requestId']);
        self::assertSame('execute', NativeBatchTest::$call['method']);
        $payload = Wire::decodeMap(NativeBatchTest::$call['payload']);
        self::assertStringContainsString('nitro_outbox_mutations', (string) $payload['sql']);
        self::assertStringContainsString('ON CONFLICT("id") DO NOTHING', (string) $payload['sql']);
        self::assertSame(
            [
                'mutation:message:0001',
                SyncEntityKind::Message->value,
                'message-42',
                MutationOperation::Upsert->value,
                '{"body":"offline"}',
                MutationState::Pending->value,
                0,
                1_000,
                1_000,
                1_000,
                null,
            ],
            self::arguments(),
        );
    }

    public function testQueriesOnlyDuePendingAndRetryMutations(): void
    {
        $received = null;
        $requestId = SyncQueue::due(
            static function (array $mutations) use (&$received): void {
                $received = $mutations;
            },
            limit: 25,
            now: 2_000,
        );

        self::assertNotNull(NativeBatchTest::$call);
        self::assertSame('query', NativeBatchTest::$call['method']);
        $payload = Wire::decodeMap(NativeBatchTest::$call['payload']);
        self::assertStringContainsString('"state" IN (?, ?)', (string) $payload['sql']);
        self::assertStringEndsWith('LIMIT 25', (string) $payload['sql']);
        self::assertSame(
            [MutationState::Pending->value, MutationState::RetryScheduled->value, 2_000],
            self::arguments(),
        );

        Runtime::dispatchModuleResult(
            $requestId,
            ModuleResultStatus::Success->value,
            Wire::map(['rows' => json_encode([self::row()], JSON_THROW_ON_ERROR)]),
        );
        self::assertIsArray($received);
        self::assertCount(1, $received);
        self::assertInstanceOf(OutboxMutation::class, $received[0]);
        self::assertSame(MutationState::Pending, $received[0]->state);
    }

    public function testSchedulesExponentialRetryAndEventuallyFails(): void
    {
        $policy = new RetryPolicy(maximumAttempts: 3, baseDelaySeconds: 2, maximumDelaySeconds: 30);

        SyncQueue::retry('mutation:message:0001', 2, 'network', $policy, 1_000);
        self::assertSame(
            [
                MutationState::RetryScheduled->value,
                2,
                1_004,
                1_000,
                'network',
                'mutation:message:0001',
                MutationState::InFlight->value,
            ],
            self::arguments(),
        );

        SyncQueue::retry('mutation:message:0001', 3, 'permanent', $policy, 2_000);
        self::assertSame(
            [
                MutationState::Failed->value,
                3,
                2_000,
                2_000,
                'permanent',
                'mutation:message:0001',
                MutationState::InFlight->value,
            ],
            self::arguments(),
        );
    }

    public function testAcknowledgementIsRetainedInTheMutationLog(): void
    {
        SyncQueue::acknowledge('mutation:message:0001', 3_000);

        self::assertNotNull(NativeBatchTest::$call);
        self::assertSame('execute', NativeBatchTest::$call['method']);
        $payload = Wire::decodeMap(NativeBatchTest::$call['payload']);
        self::assertStringContainsString('"last_error" = NULL', (string) $payload['sql']);
        self::assertSame(
            [
                MutationState::Acknowledged->value,
                3_000,
                'mutation:message:0001',
                MutationState::InFlight->value,
            ],
            self::arguments(),
        );
    }

    public function testRejectsNonSequentialEntityKindEnums(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SyncQueue::enqueue(
            InvalidSyncEntityKind::Third,
            'message-42',
            MutationOperation::Delete,
            [],
            'mutation:message:0003',
        );
    }

    public function testRejectsUnsafeMutationBoundaries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SyncQueue::enqueue(
            SyncEntityKind::Message,
            '',
            MutationOperation::Delete,
            [],
            'mutation:message:0002',
        );
    }

    public function testResolvesConflictsDeterministically(): void
    {
        $client = ['body' => 'client'];
        $server = ['body' => 'server'];

        self::assertSame(
            $server,
            ConflictResolver::resolve($client, $server, ConflictPolicy::ServerWins, 20, 10),
        );
        self::assertSame(
            $client,
            ConflictResolver::resolve($client, $server, ConflictPolicy::ClientWins, 10, 20),
        );
        self::assertSame(
            $client,
            ConflictResolver::resolve($client, $server, ConflictPolicy::LastWriteWins, 21, 20),
        );
        self::assertSame(
            $server,
            ConflictResolver::resolve($client, $server, ConflictPolicy::LastWriteWins, 20, 20),
            'Server wins equal timestamps so every client reaches the same result.',
        );
        self::assertSame(
            ['body' => 'client/server'],
            ConflictResolver::resolve(
                $client,
                $server,
                ConflictPolicy::Manual,
                20,
                20,
                static fn (array $left, array $right): array => [
                    'body' => $left['body'].'/'.$right['body'],
                ],
            ),
        );
    }

    public function testManualConflictRequiresAResolver(): void
    {
        $this->expectException(\LogicException::class);
        ConflictResolver::resolve([], [], ConflictPolicy::Manual, 1, 1);
    }

    public function testAppliesDeletesUpsertsAndCursorInOneTransaction(): void
    {
        $message = new Message();
        $message->id = 'message-new';
        $message->chatId = 'chat-1';
        $message->body = 'delta';
        $message->type = \Pam\Nitro\Tests\Fixtures\MessageType::Text;
        $message->createdAt = 9;
        $message->pending = false;

        DeltaApplier::apply(
            'messages:chat-1',
            Message::class,
            [$message],
            ['message-old'],
            'cursor-2',
            4_000,
        );

        self::assertNotNull(NativeBatchTest::$call);
        self::assertSame('transaction', NativeBatchTest::$call['method']);
        $payload = Wire::decodeMap(NativeBatchTest::$call['payload']);
        $statements = self::statements($payload['arguments']);
        self::assertCount(3, $statements);
        self::assertStringContainsString('DELETE FROM "messages"', $statements[0]['sql']);
        self::assertSame(['message-old'], $statements[0]['arguments']);
        self::assertStringContainsString('ON CONFLICT("id")', $statements[1]['sql']);
        self::assertCount(1, $statements[1]['argumentSets']);
        self::assertStringContainsString('nitro_sync_cursors', $statements[2]['sql']);
        self::assertSame(['messages:chat-1', 'cursor-2', 4_000], $statements[2]['arguments']);
    }

    public function testDeltaAlwaysAdvancesCursorEvenWithoutRowChanges(): void
    {
        DeltaApplier::apply('messages:chat-1', Message::class, [], [], 'cursor-3', 5_000);

        self::assertNotNull(NativeBatchTest::$call);
        $payload = Wire::decodeMap(NativeBatchTest::$call['payload']);
        $statements = self::statements($payload['arguments']);
        self::assertCount(1, $statements);
        self::assertSame(['messages:chat-1', 'cursor-3', 5_000], $statements[0]['arguments']);
    }

    /** @return list<string|int|float|bool|null> */
    private static function arguments(): array
    {
        self::assertNotNull(NativeBatchTest::$call);
        $payload = Wire::decodeMap(NativeBatchTest::$call['payload']);

        $decoded = json_decode(
            (string) $payload['arguments'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \LogicException('Native arguments must decode to a list.');
        }
        $arguments = [];
        foreach ($decoded as $value) {
            if (!is_string($value)
                && !is_int($value)
                && !is_float($value)
                && !is_bool($value)
                && $value !== null) {
                throw new \LogicException('Native arguments must contain JSON scalars.');
            }
            $arguments[] = $value;
        }

        return $arguments;
    }

    /**
     * @return list<array{
     *     sql: string,
     *     arguments: list<string|int|float|bool|null>,
     *     argumentSets: list<list<string|int|float|bool|null>>
     * }>
     */
    private static function statements(mixed $encoded): array
    {
        if (!is_string($encoded)) {
            throw new \LogicException('Native statements must be JSON.');
        }
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \LogicException('Native statements must decode to a list.');
        }

        $statements = [];
        foreach ($decoded as $statement) {
            if (!is_array($statement) || !isset($statement['sql']) || !is_string($statement['sql'])) {
                throw new \LogicException('Every native statement must contain SQL.');
            }
            $normalized = [
                'sql' => $statement['sql'],
                'arguments' => [],
                'argumentSets' => [],
            ];
            if (array_key_exists('arguments', $statement)) {
                $normalized['arguments'] = self::scalars($statement['arguments']);
            }
            if (array_key_exists('argumentSets', $statement)) {
                if (!is_array($statement['argumentSets']) || !array_is_list($statement['argumentSets'])) {
                    throw new \LogicException('Native argument sets must be a list.');
                }
                $normalized['argumentSets'] = array_map(self::scalars(...), $statement['argumentSets']);
            }
            $statements[] = $normalized;
        }

        return $statements;
    }

    /** @return list<string|int|float|bool|null> */
    private static function scalars(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new \LogicException('Native arguments must be a list.');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)
                && !is_int($value)
                && !is_float($value)
                && !is_bool($value)
                && $value !== null) {
                throw new \LogicException('Native arguments must contain JSON scalars.');
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    /** @return array<string, string|int|null> */
    private static function row(): array
    {
        return [
            'id' => 'mutation:message:0001',
            'entity_kind' => SyncEntityKind::Message->value,
            'entity_id' => 'message-42',
            'operation' => MutationOperation::Upsert->value,
            'payload' => '{"body":"offline"}',
            'state' => MutationState::Pending->value,
            'attempts' => 0,
            'available_at' => 1_000,
            'created_at' => 1_000,
            'updated_at' => 1_000,
            'last_error' => null,
        ];
    }
}
