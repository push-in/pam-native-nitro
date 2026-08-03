<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

use Closure;
use InvalidArgumentException;
use LogicException;

final class ConflictResolver
{
    private function __construct()
    {
    }

    /**
     * @template T of array<string, mixed>
     * @param T $client
     * @param T $server
     * @param (Closure(T, T): T)|null $manual
     * @return T
     */
    public static function resolve(
        array $client,
        array $server,
        ConflictPolicy $policy,
        int $clientUpdatedAt,
        int $serverUpdatedAt,
        ?Closure $manual = null,
    ): array {
        if ($clientUpdatedAt < 0 || $serverUpdatedAt < 0) {
            throw new InvalidArgumentException('Conflict timestamps cannot be negative.');
        }

        return match ($policy) {
            ConflictPolicy::ServerWins => $server,
            ConflictPolicy::ClientWins => $client,
            ConflictPolicy::LastWriteWins => $clientUpdatedAt > $serverUpdatedAt
                ? $client
                : $server,
            ConflictPolicy::Manual => $manual !== null
                ? $manual($client, $server)
                : throw new LogicException('Manual conflict policy requires a resolver.'),
        };
    }
}
