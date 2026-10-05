<?php

declare(strict_types=1);

namespace Pam\Nitro;

use Closure;
use InvalidArgumentException;
use Pam\Nitro\Internal\Bridge;
use Pam\Nitro\Internal\OversizedPayload;
use Pam\Nitro\Internal\PagedReader;
use Pam\Nitro\Internal\TransactionPlanner;

/**
 * Native SQLite access that never exceeds the PAM Native bridge limit.
 *
 * Every write is measured before it crosses the bridge and transparently
 * split (see {@see TransactionPlanner}); every read that does not fit one
 * result is paged (see {@see PagedReader}). Native failures are delivered to
 * the optional $failure callback, or to {@see Nitro::onFailure()} when none
 * is given, and are never thrown from inside a module result callback.
 */
final readonly class Connection
{
    public function __construct(public string $database)
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $database) !== 1) {
            throw new InvalidArgumentException('SQLite database name is invalid.');
        }
    }

    /**
     * @param list<string|int|float|bool|null> $arguments
     * @param Closure(): void|null $callback
     * @param Closure(string): void|null $failure
     */
    public function execute(
        string $sql,
        array $arguments = [],
        ?Closure $callback = null,
        ?Closure $failure = null,
    ): int {
        self::assertSql($sql);

        return Bridge::send(
            $this->database,
            'execute',
            $sql,
            Bridge::encode($arguments),
            static function () use ($callback): void {
                $callback?->__invoke();
            },
            Bridge::failure($failure),
        );
    }

    /**
     * Runs one read statement. Results larger than one bridge payload are
     * read in pages and delivered to $callback as ONE ordered list.
     *
     * @param list<string|int|float|bool|null> $arguments
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     * @param Closure(string): void|null $failure
     * @param list<string>|null $columns result column names, when known, to skip a probe while paging
     */
    public function query(
        string $sql,
        array $arguments,
        Closure $callback,
        ?Closure $failure = null,
        ?array $columns = null,
    ): int {
        self::assertSql($sql);

        return PagedReader::read(
            $this->database,
            $sql,
            $arguments,
            $columns,
            $callback,
            Bridge::failure($failure),
        );
    }

    /**
     * Executes one prepared statement for every argument set atomically.
     *
     * @param list<list<string|int|float|bool|null>> $argumentSets
     * @param Closure(): void|null $callback
     * @param Closure(string): void|null $failure
     */
    public function executeMany(
        string $sql,
        array $argumentSets,
        ?Closure $callback = null,
        ?Closure $failure = null,
    ): int {
        self::assertSql($sql);
        if ($argumentSets === []) {
            throw new InvalidArgumentException(
                'SQLite executeMany requires at least one argument set.',
            );
        }
        $encoded = Bridge::encode($argumentSets);
        if (count($argumentSets) <= Bridge::MAX_ARGUMENT_SETS
            && Bridge::size($this->database, $sql, $encoded) <= Bridge::BUDGET) {
            return Bridge::send(
                $this->database,
                'executeMany',
                $sql,
                $encoded,
                static function () use ($callback): void {
                    $callback?->__invoke();
                },
                Bridge::failure($failure),
            );
        }

        return $this->transaction(
            [['sql' => $sql, 'argumentSets' => $argumentSets]],
            $callback,
            $failure,
        );
    }

    /**
     * Applies statements in ONE native transaction when they fit one bridge
     * payload, and through an atomic staged commit otherwise.
     *
     * @param list<array{
     *   sql: string,
     *   arguments?: list<string|int|float|bool|null>,
     *   argumentSets?: list<list<string|int|float|bool|null>>
     * }> $statements
     * @param Closure(): void|null $callback
     * @param Closure(string): void|null $failure
     */
    public function transaction(
        array $statements,
        ?Closure $callback = null,
        ?Closure $failure = null,
    ): int {
        $failure = Bridge::failure($failure);
        try {
            $plan = TransactionPlanner::plan($this->database, $statements);
        } catch (OversizedPayload $error) {
            $failure($error->getMessage());

            return 0;
        }
        $database = $this->database;
        $token = $plan['token'];
        $cleanup = $plan['cleanup'];

        return Bridge::sequence(
            $database,
            array_map(
                static fn (string $arguments): array => ['transaction', '', $arguments],
                $plan['calls'],
            ),
            static function () use ($token, $callback): void {
                TransactionPlanner::release($token);
                $callback?->__invoke();
            },
            static function (string $message) use ($database, $token, $cleanup, $failure): void {
                TransactionPlanner::release($token);
                if ($cleanup !== null) {
                    Bridge::send(
                        $database,
                        'transaction',
                        '',
                        $cleanup,
                        static function (): void {
                        },
                        static function (): void {
                        },
                    );
                }
                $failure($message);
            },
        );
    }

    /**
     * Runs one read statement and reports native failures to $failure instead
     * of raising them, so callers can recover inside the same boot sequence.
     *
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     * @param Closure(string): void $failure
     */
    public function attempt(string $sql, Closure $callback, Closure $failure): int
    {
        return $this->query($sql, [], $callback, $failure);
    }

    private static function assertSql(string $sql): void
    {
        if ($sql === '' || strlen($sql) > Bridge::LIMIT) {
            throw new InvalidArgumentException('SQLite SQL must contain between 1 and 1048576 bytes.');
        }
    }
}
