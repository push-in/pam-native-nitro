<?php

declare(strict_types=1);

namespace Pam\Nitro;

use Closure;
use Pam\Native\Database\SQLite;

final readonly class Connection
{
    public function __construct(public string $database)
    {
    }

    /** @param list<string|int|float|bool|null> $arguments */
    public function execute(
        string $sql,
        array $arguments = [],
        ?Closure $callback = null,
    ): int {
        return SQLite::execute($this->database, $sql, $arguments, $callback);
    }

    /**
     * @param list<string|int|float|bool|null> $arguments
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     */
    public function query(string $sql, array $arguments, Closure $callback): int
    {
        return SQLite::query($this->database, $sql, $arguments, $callback);
    }

    /**
     * @param list<list<string|int|float|bool|null>> $argumentSets
     */
    public function executeMany(
        string $sql,
        array $argumentSets,
        ?Closure $callback = null,
    ): int {
        return SQLite::executeMany($this->database, $sql, $argumentSets, $callback);
    }

    /**
     * @param list<array{
     *   sql: string,
     *   arguments?: list<string|int|float|bool|null>,
     *   argumentSets?: list<list<string|int|float|bool|null>>
     * }> $statements
     */
    public function transaction(array $statements, ?Closure $callback = null): int
    {
        return SQLite::transaction($this->database, $statements, $callback);
    }
}
