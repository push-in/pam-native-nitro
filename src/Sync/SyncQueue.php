<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

use BackedEnum;
use Closure;
use InvalidArgumentException;
use JsonException;
use Pam\Nitro\Nitro;

final class SyncQueue
{
    private const MAX_PAYLOAD_BYTES = 1_048_576;
    private const MAX_ERROR_BYTES = 4_096;

    private function __construct()
    {
    }

    public static function prepare(Closure $callback): int
    {
        return Nitro::prepare([OutboxMutation::class], $callback);
    }

    /**
     * Adds an idempotent local mutation to the durable outbox.
     *
     * @param BackedEnum $entityKind Integer-backed application enum identifying the entity.
     * @param array<string, mixed> $payload
     */
    public static function enqueue(
        BackedEnum $entityKind,
        string|int $entityId,
        MutationOperation $operation,
        array $payload,
        ?string $idempotencyKey = null,
        ?int $now = null,
        ?Closure $callback = null,
    ): int {
        self::assertEntityKind($entityKind);
        $identifier = trim((string) $entityId);
        if ($identifier === '' || strlen($identifier) > 512) {
            throw new InvalidArgumentException('entityId must contain between 1 and 512 bytes.');
        }
        $key = $idempotencyKey ?? bin2hex(random_bytes(16));
        if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $key) !== 1) {
            throw new InvalidArgumentException(
                'idempotencyKey must contain 16 to 128 safe identifier characters.',
            );
        }
        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Mutation payload must be valid JSON data.', 0, $error);
        }
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Mutation payload exceeds 1048576 bytes.');
        }

        $timestamp = $now ?? time();
        return Nitro::connection()->execute(
            'INSERT INTO "nitro_outbox_mutations" '
                .'("id", "entity_kind", "entity_id", "operation", "payload", "state", '
                .'"attempts", "available_at", "created_at", "updated_at", "last_error") '
                .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT("id") DO NOTHING',
            [
                $key,
                $entityKind->value,
                $identifier,
                $operation->value,
                $encoded,
                MutationState::Pending->value,
                0,
                $timestamp,
                $timestamp,
                $timestamp,
                null,
            ],
            $callback,
        );
    }

    /** @param Closure(list<OutboxMutation>): void $callback */
    public static function due(Closure $callback, int $limit = 100, ?int $now = null): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('Sync batch limit must be between 1 and 1000.');
        }
        $timestamp = $now ?? time();

        return Nitro::connection()->query(
            'SELECT * FROM "nitro_outbox_mutations" '
                .'WHERE "state" IN (?, ?) AND "available_at" <= ? '
                .'ORDER BY "created_at" ASC LIMIT '.$limit,
            [MutationState::Pending->value, MutationState::RetryScheduled->value, $timestamp],
            static fn (array $rows) => $callback(array_map(OutboxMutation::hydrate(...), $rows)),
        );
    }

    public static function markInFlight(
        string $idempotencyKey,
        int $attempts,
        ?int $now = null,
        ?Closure $callback = null,
    ): int {
        if ($attempts < 1) {
            throw new InvalidArgumentException('attempts must be greater than zero.');
        }

        return self::transition(
            $idempotencyKey,
            MutationState::InFlight,
            $attempts,
            $now ?? time(),
            null,
            $callback,
            [MutationState::Pending, MutationState::RetryScheduled],
        );
    }

    public static function acknowledge(
        string $idempotencyKey,
        ?int $now = null,
        ?Closure $callback = null,
    ): int {
        return Nitro::connection()->execute(
            'UPDATE "nitro_outbox_mutations" SET "state" = ?, "updated_at" = ?, '
                .'"last_error" = NULL WHERE "id" = ? AND "state" = ?',
            [
                MutationState::Acknowledged->value,
                $now ?? time(),
                self::key($idempotencyKey),
                MutationState::InFlight->value,
            ],
            $callback,
        );
    }

    public static function retry(
        string $idempotencyKey,
        int $attempts,
        string $error,
        ?RetryPolicy $policy = null,
        ?int $now = null,
        ?Closure $callback = null,
    ): int {
        $policy ??= new RetryPolicy();
        if ($attempts < 1) {
            throw new InvalidArgumentException('attempts must be greater than zero.');
        }
        $timestamp = $now ?? time();
        $state = $attempts >= $policy->maximumAttempts
            ? MutationState::Failed
            : MutationState::RetryScheduled;
        $availableAt = $state === MutationState::Failed
            ? $timestamp
            : $timestamp + $policy->delayForAttempt($attempts);

        return self::transition(
            $idempotencyKey,
            $state,
            $attempts,
            $availableAt,
            substr($error, 0, self::MAX_ERROR_BYTES),
            $callback,
            [MutationState::InFlight],
            $timestamp,
        );
    }

    /** @param list<MutationState> $from */
    private static function transition(
        string $idempotencyKey,
        MutationState $state,
        int $attempts,
        int $availableAt,
        ?string $lastError,
        ?Closure $callback,
        array $from,
        ?int $updatedAt = null,
    ): int {
        if ($from === []) {
            throw new InvalidArgumentException('A mutation transition requires source states.');
        }
        $placeholders = implode(', ', array_fill(0, count($from), '?'));

        $arguments = [
            $state->value,
            $attempts,
            $availableAt,
            $updatedAt ?? $availableAt,
            $lastError,
            self::key($idempotencyKey),
        ];
        foreach ($from as $source) {
            $arguments[] = $source->value;
        }

        return Nitro::connection()->execute(
            'UPDATE "nitro_outbox_mutations" SET "state" = ?, "attempts" = ?, '
                .'"available_at" = ?, "updated_at" = ?, "last_error" = ? '
                .'WHERE "id" = ? AND "state" IN ('.$placeholders.')',
            $arguments,
            $callback,
        );
    }

    private static function key(string $idempotencyKey): string
    {
        if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }

        return $idempotencyKey;
    }

    private static function assertEntityKind(BackedEnum $entityKind): void
    {
        $values = array_map(
            static fn (BackedEnum $case): int|string => $case->value,
            $entityKind::cases(),
        );
        if ($values !== range(1, count($values))) {
            throw new InvalidArgumentException(
                'entityKind cases must be sequential integer values starting at 1.',
            );
        }
    }
}
