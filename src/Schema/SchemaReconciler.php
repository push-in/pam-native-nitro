<?php

declare(strict_types=1);

namespace Pam\Nitro\Schema;

use Closure;
use Pam\Nitro\Connection;
use Pam\Nitro\Internal\Bridge;
use Pam\Nitro\Internal\Sql;

/**
 * Fingerprint-gated schema reconciliation.
 *
 * Every declared table has a fingerprint of its DDL (columns, migration
 * defaults and indexes) stored in the `nitro_meta` table. Booting issues ONE
 * native query that returns only the tables whose fingerprint changed together
 * with their current columns. When nothing changed, the callback runs right
 * after that single round-trip. Otherwise every CREATE TABLE, ALTER TABLE ADD
 * COLUMN, CREATE INDEX and fingerprint update is applied in ONE native
 * transaction.
 *
 * @internal
 */
final class SchemaReconciler
{
    /** Bump whenever generated DDL changes so existing databases reconcile again. */
    public const int VERSION = 1;

    public const string META_TABLE = 'nitro_meta';

    /** @var array<string, string> database and table => verified fingerprint */
    private static array $verified = [];

    /**
     * SQLite before 3.16 (Android 7.x ships 3.9) has no table-valued
     * `pragma_table_info()`. Once a query reports it missing, the process
     * reads each table's DDL from sqlite_master instead.
     */
    private static bool $legacyColumns = false;

    private function __construct()
    {
    }

    public static function fingerprint(ModelSchema $schema): string
    {
        $parts = [(string) self::VERSION, Sql::createTable($schema)];
        foreach ($schema->columns as $column) {
            if (!$column->primary) {
                $parts[] = Sql::addColumn($schema, $column);
            }
        }

        return hash('xxh128', implode("\0", [...$parts, ...Sql::createIndexes($schema)]));
    }

    /**
     * @param list<ModelSchema> $schemas
     */
    public static function reconcile(
        Connection $connection,
        array $schemas,
        Closure $callback,
        ?Closure $failure = null,
    ): int {
        $pending = [];
        foreach ($schemas as $schema) {
            $fingerprint = self::fingerprint($schema);
            if ((self::$verified[$connection->database."\0".$schema->table] ?? null) !== $fingerprint) {
                $pending[$schema->table] = [$schema, $fingerprint];
            }
        }
        if ($pending === []) {
            $callback();

            return 0;
        }
        $pending = array_values($pending);
        $apply = static function (array $rows) use ($connection, $pending, $callback, $failure): void {
            self::apply($connection, $pending, $rows, $callback, $failure);
        };

        return self::inspect($connection, $pending, $apply, $failure);
    }

    /**
     * @param list<array{ModelSchema, string}> $pending
     * @param Closure(array<mixed>): void $apply
     */
    private static function inspect(
        Connection $connection,
        array $pending,
        Closure $apply,
        ?Closure $failure,
    ): int {
        $legacy = self::$legacyColumns;
        $retryLegacy = static function (string $message) use ($connection, $pending, $apply, $failure, $legacy): bool {
            if ($legacy || !self::missingPragmaFunction($message)) {
                return false;
            }
            self::$legacyColumns = true;
            self::inspect($connection, $pending, $apply, $failure);

            return true;
        };

        return $connection->attempt(
            self::staleTablesSql($pending, true, $legacy),
            $apply,
            // The metadata table does not exist yet (fresh install or upgrade
            // from Nitro < 0.5): inspect every declared table instead.
            static function (string $message = '') use ($connection, $pending, $apply, $failure, $legacy, $retryLegacy): void {
                if ($retryLegacy($message)) {
                    return;
                }
                $connection->query(
                    self::staleTablesSql($pending, false, $legacy),
                    [],
                    $apply,
                    static function (string $message) use ($failure, $retryLegacy): void {
                        if (!$retryLegacy($message)) {
                            Bridge::failure($failure)($message);
                        }
                    },
                );
            },
        );
    }

    private static function missingPragmaFunction(string $message): bool
    {
        return str_contains($message, 'pragma_table_info');
    }

    /** @internal Testing hook that forgets per-process verification. */
    public static function forget(): void
    {
        self::$verified = [];
        self::$legacyColumns = false;
    }

    /**
     * @param list<array{ModelSchema, string}> $pending
     */
    public static function staleTablesSql(array $pending, bool $guarded, bool $legacy = false): string
    {
        // A UNION ALL derived table keeps explicit column names on every SQLite
        // shipped since Android 7 (VALUES column aliases are not portable).
        $expected = implode(' UNION ALL ', array_map(
            static fn (array $entry): string => 'SELECT '.Sql::literal($entry[0]->table)
                .' AS "name", '.Sql::literal($entry[1]).' AS "fingerprint"',
            $pending,
        ));
        // Reading sqlite_master pins the statement to the current schema cookie,
        // so pooled read connections never report columns from a stale schema.
        $sql = $legacy
            // SQLite < 3.16: the stored CREATE TABLE text (ALTER TABLE ADD
            // COLUMN rewrites it), parsed by columnsFromDdl().
            ? 'SELECT e."name" AS "table", (SELECT s.sql FROM sqlite_master AS s '
                .'WHERE s.type = \'table\' AND s.name = e."name") AS "ddl" '
                .'FROM ('.$expected.') AS e'
            : 'SELECT e."name" AS "table", CASE WHEN EXISTS ('
                .'SELECT 1 FROM sqlite_master AS s WHERE s.type = \'table\' AND s.name = e."name"'
                .') THEN (SELECT group_concat(p.name, \',\') FROM pragma_table_info(e."name") AS p) END AS "columns" '
                .'FROM ('.$expected.') AS e';
        if ($guarded) {
            $sql .= ' WHERE NOT EXISTS (SELECT 1 FROM "'.self::META_TABLE.'" AS m '
                .'WHERE m."key" = \'schema:\' || e."name" AND m."value" = e."fingerprint")';
        }

        return $sql;
    }

    /**
     * @param list<array{ModelSchema, string}> $pending
     * @param array<mixed> $rows
     */
    private static function apply(
        Connection $connection,
        array $pending,
        array $rows,
        Closure $callback,
        ?Closure $failure,
    ): void {
        $stale = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $table = $row['table'] ?? null;
            if (!is_string($table)) {
                continue;
            }
            if (array_key_exists('ddl', $row)) {
                $ddl = $row['ddl'];
                $stale[$table] = is_string($ddl) && $ddl !== '' ? self::columnsFromDdl($ddl) : null;
                continue;
            }
            $columns = $row['columns'] ?? null;
            $stale[$table] = is_string($columns) && $columns !== ''
                ? array_fill_keys(explode(',', $columns), true)
                : null;
        }
        $statements = self::statements($pending, $stale);
        $verify = static function () use ($connection, $pending, $callback): void {
            foreach ($pending as [$schema, $fingerprint]) {
                self::$verified[$connection->database."\0".$schema->table] = $fingerprint;
            }
            $callback();
        };
        if ($statements === []) {
            $verify();

            return;
        }
        $connection->transaction($statements, $verify, $failure);
    }

    /**
     * Column names of a CREATE TABLE statement as stored in sqlite_master.
     *
     * @return array<string, true>
     */
    public static function columnsFromDdl(string $ddl): array
    {
        $open = strpos($ddl, '(');
        $close = strrpos($ddl, ')');
        if ($open === false || $close === false || $close < $open) {
            return [];
        }
        $body = substr($ddl, $open + 1, $close - $open - 1);
        $definitions = [];
        $depth = 0;
        $quote = null;
        $start = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];
            if ($quote !== null) {
                if ($character === $quote) {
                    if ($quote !== ']' && ($body[$i + 1] ?? '') === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($character === '"' || $character === "'" || $character === '`') {
                $quote = $character;
            } elseif ($character === '[') {
                $quote = ']';
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $definitions[] = substr($body, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $definitions[] = substr($body, $start);
        $columns = [];
        foreach ($definitions as $definition) {
            $definition = ltrim($definition);
            if ($definition === '' || preg_match('/^(?:CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN)\b/i', $definition) === 1) {
                continue;
            }
            $name = match (true) {
                preg_match('/^"((?:[^"]|"")*)"/', $definition, $match) === 1 => str_replace('""', '"', $match[1]),
                preg_match('/^`([^`]*)`/', $definition, $match) === 1 => $match[1],
                preg_match('/^\[([^\]]*)\]/', $definition, $match) === 1 => $match[1],
                preg_match("/^'((?:[^']|'')*)'/", $definition, $match) === 1 => str_replace("''", "'", $match[1]),
                preg_match('/^([^\s(]+)/', $definition, $match) === 1 => $match[1],
                default => null,
            };
            if ($name !== null) {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    /**
     * Builds the complete DDL batch for every stale table.
     *
     * @param list<array{ModelSchema, string}> $pending
     * @param array<string, array<string, true>|null> $stale table => existing columns (null when absent)
     * @return list<array{sql: string, arguments?: list<string>, argumentSets?: list<list<string>>}>
     */
    public static function statements(array $pending, array $stale): array
    {
        if ($stale === []) {
            return [];
        }
        $statements = [[
            'sql' => 'CREATE TABLE IF NOT EXISTS "'.self::META_TABLE.'" '
                .'("key" TEXT PRIMARY KEY NOT NULL, "value" TEXT NOT NULL)',
        ]];
        $fingerprints = [];
        foreach ($pending as [$schema, $fingerprint]) {
            if (!array_key_exists($schema->table, $stale)) {
                continue;
            }
            $existing = $stale[$schema->table];
            if ($existing === null) {
                $statements[] = ['sql' => Sql::createTable($schema)];
            } else {
                foreach ($schema->columns as $column) {
                    if (!$column->primary && !isset($existing[$column->name])) {
                        $statements[] = ['sql' => Sql::addColumn($schema, $column)];
                    }
                }
            }
            foreach (Sql::createIndexes($schema) as $index) {
                $statements[] = ['sql' => $index];
            }
            $fingerprints[] = ['schema:'.$schema->table, $fingerprint];
        }
        $statements[] = [
            'sql' => 'INSERT OR REPLACE INTO "'.self::META_TABLE.'" ("key", "value") VALUES (?, ?)',
            'argumentSets' => $fingerprints,
        ];

        return $statements;
    }
}
