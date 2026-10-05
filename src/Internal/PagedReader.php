<?php

declare(strict_types=1);

namespace Pam\Nitro\Internal;

use Closure;
use Pam\Native\Modules\NativeModuleResult;

/**
 * Reads query results that may not fit one bridge result.
 *
 * The first attempt is ONE native query, as before. Only when the native side
 * reports that the encoded rows exceed the one-MiB wire limit does Nitro page:
 *
 * 1. probe the result columns (skipped when the caller knows them),
 * 2. read the byte size of every row in ONE small query,
 * 3. read consecutive LIMIT/OFFSET windows that fit the budget, splitting a
 *    window in two whenever escaping still pushes it over the limit.
 *
 * Rows keep the order of the original statement. Paged windows are separate
 * native reads, so writes issued while a paged read is in flight may be seen
 * by later windows only.
 *
 * @internal
 */
final class PagedReader
{
    /** JSON framing of one row: braces and the separating comma. */
    private const int ROW_FRAMING = 3;

    private function __construct()
    {
    }

    /**
     * @param list<string|int|float|bool|null> $arguments
     * @param list<string>|null $columns result column names, when known
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     * @param Closure(string): void $failure
     */
    public static function read(
        string $database,
        string $sql,
        array $arguments,
        ?array $columns,
        Closure $callback,
        Closure $failure,
    ): int {
        $encoded = Bridge::encode($arguments);

        return self::query(
            $database,
            $sql,
            $encoded,
            $callback,
            static function (string $message) use ($database, $sql, $encoded, $columns, $callback, $failure): void {
                if (!Bridge::isOversize($message)) {
                    $failure($message);

                    return;
                }
                self::page($database, rtrim(rtrim($sql), ';'), $encoded, $columns, $callback, $failure);
            },
        );
    }

    /**
     * @param list<string>|null $columns
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     * @param Closure(string): void $failure
     */
    private static function page(
        string $database,
        string $sql,
        string $encoded,
        ?array $columns,
        Closure $callback,
        Closure $failure,
    ): void {
        $measure = static function (array $columns) use ($database, $sql, $encoded, $callback, $failure): void {
            $size = [];
            foreach ($columns as $column) {
                $name = '"'.str_replace('"', '""', (string) $column).'"';
                $size[] = 'IFNULL(LENGTH(CAST('.$name.' AS BLOB)), 4) + '
                    .(strlen(Bridge::string((string) $column)) + 2);
            }
            self::query(
                $database,
                'SELECT '.($size === [] ? '0' : implode(' + ', $size))
                    .' AS "nitro_bytes" FROM ('.$sql.')',
                $encoded,
                static function (array $rows) use ($database, $sql, $encoded, $callback, $failure): void {
                    self::drain(
                        $database,
                        $sql,
                        $encoded,
                        self::windows(array_map(
                            static fn (array $row): int => (int) ($row['nitro_bytes'] ?? 0),
                            $rows,
                        )),
                        [],
                        $callback,
                        $failure,
                    );
                },
                $failure,
            );
        };
        if ($columns !== null) {
            $measure($columns);

            return;
        }
        self::query(
            $database,
            'SELECT * FROM ('.$sql.') LIMIT 1',
            $encoded,
            static function (array $rows) use ($measure, $callback): void {
                if ($rows === []) {
                    $callback([]);

                    return;
                }
                $measure(array_keys($rows[0]));
            },
            $failure,
        );
    }

    /**
     * Greedy consecutive windows under the budget and the native row limit.
     *
     * @param list<int> $sizes
     * @return list<array{int, int}> offset and row count
     */
    public static function windows(array $sizes): array
    {
        $windows = [];
        $offset = 0;
        $count = 0;
        $bytes = 2;
        foreach ($sizes as $index => $size) {
            $cost = max(0, $size) + self::ROW_FRAMING;
            if ($count > 0 && ($bytes + $cost > Bridge::BUDGET || $count >= Bridge::MAX_QUERY_ROWS)) {
                $windows[] = [$offset, $count];
                $offset = $index;
                $count = 0;
                $bytes = 2;
            }
            ++$count;
            $bytes += $cost;
        }
        if ($count > 0) {
            $windows[] = [$offset, $count];
        }

        return $windows;
    }

    /**
     * @param list<array{int, int}> $windows
     * @param list<array<string, string|int|float|bool|null>> $collected
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     * @param Closure(string): void $failure
     */
    private static function drain(
        string $database,
        string $sql,
        string $encoded,
        array $windows,
        array $collected,
        Closure $callback,
        Closure $failure,
    ): void {
        $window = array_shift($windows);
        if ($window === null) {
            $callback($collected);

            return;
        }
        [$offset, $count] = $window;
        self::query(
            $database,
            'SELECT * FROM ('.$sql.') LIMIT '.$count.' OFFSET '.$offset,
            $encoded,
            static function (array $rows) use ($database, $sql, $encoded, $windows, $collected, $callback, $failure): void {
                array_push($collected, ...$rows);
                self::drain($database, $sql, $encoded, $windows, $collected, $callback, $failure);
            },
            static function (string $message) use ($database, $sql, $encoded, $windows, $collected, $offset, $count, $callback, $failure): void {
                if ($count < 2 || !Bridge::isOversize($message)) {
                    $failure($count < 2 && Bridge::isOversize($message)
                        ? 'Nitro row exceeds the PAM Native bridge limit of 1 MiB: '.$message
                        : $message);

                    return;
                }
                $half = intdiv($count, 2);
                array_unshift($windows, [$offset, $half], [$offset + $half, $count - $half]);
                self::drain($database, $sql, $encoded, $windows, $collected, $callback, $failure);
            },
        );
    }

    /**
     * @param Closure(list<array<string, string|int|float|bool|null>>): void $callback
     * @param Closure(string): void $failure
     */
    private static function query(
        string $database,
        string $sql,
        string $encoded,
        Closure $callback,
        Closure $failure,
    ): int {
        return Bridge::send(
            $database,
            'query',
            $sql,
            $encoded,
            static function (NativeModuleResult $result) use ($callback, $failure): void {
                $rows = Bridge::rows($result);
                if (is_string($rows)) {
                    $failure($rows);

                    return;
                }
                $callback($rows);
            },
            $failure,
        );
    }
}
