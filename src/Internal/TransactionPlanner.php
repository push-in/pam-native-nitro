<?php

declare(strict_types=1);

namespace Pam\Nitro\Internal;

use InvalidArgumentException;

/**
 * Turns one logical transaction into native calls that fit the bridge.
 *
 * A transaction whose encoded payload fits the budget is ONE native call, as
 * before. A larger transaction is staged so it still commits atomically:
 *
 * 1. Rows of every `INSERT ... VALUES (?, ...)` statement (every Nitro save,
 *    saveMany, replaceMany and delta upsert) are written, in as many calls as
 *    needed, to a private `nitro_staging_<width>` table under a random token.
 *    The target tables are not touched yet.
 * 2. ONE final native transaction runs the original statements in order,
 *    replacing each staged insert by `INSERT ... SELECT` from the staging rows,
 *    and deletes the staging rows.
 *
 * A failure before or during step 2 leaves every target table unchanged; the
 * staging rows are removed by a best-effort cleanup call and, at the latest,
 * by the next staged write. Only when the remaining non-insert statements of
 * step 2 are themselves larger than the budget (for example thousands of raw
 * UPDATE argument sets) is step 2 split into sequential transactions, which
 * are then applied in order but not atomically as a whole.
 *
 * @internal
 */
final class TransactionPlanner
{
    private const string INSERT = '/^(INSERT(?: OR (?:REPLACE|IGNORE|ABORT|FAIL|ROLLBACK))? INTO '
        .'"[A-Za-z_][A-Za-z0-9_]*" \("[A-Za-z_][A-Za-z0-9_]*"(?:, "[A-Za-z_][A-Za-z0-9_]*")*\)) '
        .'VALUES \((\?(?:, \?)*)\)$/D';

    /** Android binds at most 999 variables per statement. */
    private const int MAX_STAGED_WIDTH = 900;

    /** @var array<string, true> tokens of staged transactions still in flight */
    private static array $active = [];

    private function __construct()
    {
    }

    /**
     * @param list<array{
     *   sql: string,
     *   arguments?: list<string|int|float|bool|null>,
     *   argumentSets?: list<list<string|int|float|bool|null>>
     * }> $statements
     * @return array{calls: list<string>, token: ?string, cleanup: ?string}
     * @throws OversizedPayload
     */
    public static function plan(string $database, array $statements): array
    {
        if ($statements === []) {
            throw new InvalidArgumentException('SQLite transaction requires at least one statement.');
        }
        $base = Bridge::size($database, '', '');
        $normalized = [];
        $bytes = $base + 2;
        $fits = count($statements) <= Bridge::MAX_STATEMENTS;
        foreach ($statements as $statement) {
            $sql = $statement['sql'];
            if ($sql === '' || strlen($sql) > Bridge::LIMIT) {
                throw new InvalidArgumentException(
                    'Every SQLite transaction SQL statement must contain between 1 and 1048576 bytes.',
                );
            }
            $sets = $statement['argumentSets'] ?? [$statement['arguments'] ?? []];
            if ($sets === []) {
                throw new InvalidArgumentException(
                    'SQLite transaction argumentSets must contain at least one row.',
                );
            }
            $encoded = [];
            $widths = [];
            foreach ($sets as $set) {
                $encoded[] = Bridge::encode($set);
                $widths[count($set)] = true;
            }
            $normalized[] = [
                'sql' => $sql,
                'sets' => $encoded,
                'width' => count($widths) === 1 ? array_key_first($widths) : null,
                'many' => isset($statement['argumentSets']),
            ];
            $bytes += ChunkBuilder::STATEMENT_FRAMING + strlen(Bridge::string($sql))
                + array_sum(array_map(strlen(...), $encoded)) + count($encoded);
            $fits = $fits && count($encoded) <= Bridge::MAX_ARGUMENT_SETS;
        }
        if ($fits && $bytes <= Bridge::BUDGET) {
            return ['calls' => self::direct($base, $normalized), 'token' => null, 'cleanup' => null];
        }

        $staged = [];
        foreach ($normalized as $index => $statement) {
            $staged[$index] = self::stageable($statement['sql'], $statement['width']);
        }
        $widths = array_values(array_unique(array_filter(
            array_map(static fn (?array $insert): ?int => $insert[1] ?? null, $staged),
            static fn (?int $width): bool => $width !== null,
        )));
        if ($widths === []) {
            return ['calls' => self::direct($base, $normalized), 'token' => null, 'cleanup' => null];
        }

        $token = bin2hex(random_bytes(12));
        $tokenJson = Bridge::string($token);
        $stage = new ChunkBuilder($base);
        $commit = new ChunkBuilder($base);
        $cleanup = new ChunkBuilder($base);
        $active = array_keys(self::$active + [$token => true]);
        foreach ($widths as $width) {
            $stage->push(self::createSql($width), ['[]']);
            $stage->push(
                'DELETE FROM "'.self::table($width).'" WHERE "nitro_token" NOT IN ('
                    .implode(', ', array_fill(0, count($active), '?')).')',
                [Bridge::encode($active)],
            );
        }
        foreach (array_keys($normalized) as $index) {
            // Release every encoded statement as soon as it is packed.
            $statement = $normalized[$index];
            unset($normalized[$index]);
            $insert = $staged[$index];
            if ($insert === null) {
                $commit->push($statement['sql'], $statement['sets'], $statement['many']);

                continue;
            }
            [$prefix, $width] = $insert;
            $rows = [];
            foreach ($statement['sets'] as $sequence => $set) {
                $rows[] = '['.$tokenJson.','.$index.','.$sequence.','.substr($set, 1);
            }
            unset($statement);
            $stage->push(self::insertSql($width), $rows);
            unset($rows);
            $commit->push(
                $prefix.' SELECT '.self::columns($width).' FROM "'.self::table($width)
                    .'" WHERE "nitro_token" = ? AND "nitro_part" = ? ORDER BY "nitro_seq"',
                ['['.$tokenJson.','.$index.']'],
            );
        }
        foreach ($widths as $width) {
            $delete = 'DELETE FROM "'.self::table($width).'" WHERE "nitro_token" = ?';
            $commit->push($delete, ['['.$tokenJson.']']);
            $cleanup->push($delete, ['['.$tokenJson.']']);
        }
        self::$active[$token] = true;

        return [
            'calls' => [...$stage->chunks(), ...$commit->chunks()],
            'token' => $token,
            'cleanup' => $cleanup->chunks()[0],
        ];
    }

    /**
     * @param list<array{sql: string, sets: list<string>, width: ?int, many: bool}> $normalized
     * @return list<string>
     */
    private static function direct(int $base, array $normalized): array
    {
        $direct = new ChunkBuilder($base);
        foreach ($normalized as $statement) {
            $direct->push($statement['sql'], $statement['sets'], $statement['many']);
        }

        return $direct->chunks();
    }

    public static function release(?string $token): void
    {
        if ($token !== null) {
            unset(self::$active[$token]);
        }
    }

    /**
     * @param int|null $arguments argument count shared by every set, null when they differ
     * @return array{string, int}|null INSERT prefix and placeholder count
     */
    private static function stageable(string $sql, ?int $arguments): ?array
    {
        if (preg_match(self::INSERT, $sql, $match) !== 1) {
            return null;
        }
        $width = substr_count($match[2], '?');

        // Every argument list must bind exactly the declared placeholders.
        return $width === $arguments && $width <= self::MAX_STAGED_WIDTH
            ? [$match[1], $width]
            : null;
    }

    private static function table(int $width): string
    {
        return 'nitro_staging_'.$width;
    }

    private static function columns(int $width): string
    {
        $columns = [];
        for ($index = 0; $index < $width; ++$index) {
            $columns[] = '"a'.$index.'"';
        }

        return implode(', ', $columns);
    }

    private static function createSql(int $width): string
    {
        // Untyped staging columns keep every bound value as-is; the target
        // column affinity applies when the final INSERT ... SELECT copies it.
        return 'CREATE TABLE IF NOT EXISTS "'.self::table($width).'" ('
            .'"nitro_token" TEXT NOT NULL, "nitro_part" INTEGER NOT NULL, '
            .'"nitro_seq" INTEGER NOT NULL, '.self::columns($width).', '
            .'PRIMARY KEY ("nitro_token", "nitro_part", "nitro_seq"))';
    }

    private static function insertSql(int $width): string
    {
        return 'INSERT INTO "'.self::table($width).'" ("nitro_token", "nitro_part", "nitro_seq", '
            .self::columns($width).') VALUES ('
            .implode(', ', array_fill(0, $width + 3, '?')).')';
    }
}
