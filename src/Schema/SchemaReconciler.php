<?php

declare(strict_types=1);

namespace Pam\Nitro\Schema;

use Closure;
use Pam\Nitro\Connection;
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
        $apply = static function (array $rows) use ($connection, $pending, $callback): void {
            self::apply($connection, $pending, $rows, $callback);
        };

        return $connection->attempt(
            self::staleTablesSql($pending, true),
            $apply,
            // The metadata table does not exist yet (fresh install or upgrade
            // from Nitro < 0.5): inspect every declared table instead.
            static function () use ($connection, $pending, $apply): void {
                $connection->query(self::staleTablesSql($pending, false), [], $apply);
            },
        );
    }

    /** @internal Testing hook that forgets per-process verification. */
    public static function forget(): void
    {
        self::$verified = [];
    }

    /**
     * @param list<array{ModelSchema, string}> $pending
     */
    public static function staleTablesSql(array $pending, bool $guarded): string
    {
        // A UNION ALL derived table keeps explicit column names on every SQLite
        // shipped since Android 8 (VALUES column aliases are not portable).
        $expected = implode(' UNION ALL ', array_map(
            static fn (array $entry): string => 'SELECT '.Sql::literal($entry[0]->table)
                .' AS "name", '.Sql::literal($entry[1]).' AS "fingerprint"',
            $pending,
        ));
        // Reading sqlite_master pins the statement to the current schema cookie,
        // so pooled read connections never report columns from a stale schema.
        $sql = 'SELECT e."name" AS "table", CASE WHEN EXISTS ('
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
        $connection->transaction($statements, $verify);
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
