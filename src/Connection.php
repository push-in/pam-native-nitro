<?php

declare(strict_types=1);

namespace Pam\Nitro;

use Closure;
use Pam\Native\Database\SQLite;
use Pam\Native\ModuleResultStatus;
use Pam\Native\Modules\NativeModuleResult;
use Pam\Native\Modules\NativeModules;

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

    /**
     * Runs one read statement and reports native failures to $failure instead
     * of raising them, so callers can recover inside the same boot sequence.
     *
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     * @param Closure(string): void $failure
     */
    public function attempt(string $sql, Closure $callback, Closure $failure): int
    {
        return NativeModules::call(
            'sqlite',
            'query',
            ['database' => $this->database, 'sql' => $sql, 'arguments' => '[]'],
            static function (NativeModuleResult $result) use ($callback, $failure): void {
                if ($result->status === ModuleResultStatus::Failure) {
                    $failure($result->payload);

                    return;
                }
                $rows = json_decode(
                    (string) ($result->values()['rows'] ?? '[]'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $list = [];
                foreach (is_array($rows) ? $rows : [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $values = [];
                    foreach ($row as $column => $value) {
                        if (is_string($column) && (is_scalar($value) || $value === null)) {
                            $values[$column] = $value;
                        }
                    }
                    $list[] = $values;
                }
                $callback($list);
            },
        );
    }
}
