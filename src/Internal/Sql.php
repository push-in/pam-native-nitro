<?php

declare(strict_types=1);

namespace Pam\Nitro\Internal;

use InvalidArgumentException;
use Pam\Nitro\Schema\Column;
use Pam\Nitro\Schema\ColumnType;
use Pam\Nitro\Schema\ModelSchema;

/**
 * Deterministic SQL generation shared by every Nitro write path.
 *
 * Identical statements always produce byte-identical SQL so the native
 * prepared-statement caches can reuse compiled programs.
 *
 * @internal
 */
final class Sql
{
    /** @var array<string, string> */
    private static array $upserts = [];

    private function __construct()
    {
    }

    public static function upsert(ModelSchema $schema): string
    {
        return self::$upserts[$schema->model] ??= self::compileUpsert($schema);
    }

    public static function deleteByPrimaryKey(ModelSchema $schema): string
    {
        return 'DELETE FROM "'.$schema->table.'" WHERE "'.$schema->primary->name.'" = ?';
    }

    /**
     * @param array<string, string|int|float|bool|null> $scope
     * @return array{list<string>, list<string|int|float|bool|null>}
     */
    public static function scope(ModelSchema $schema, array $scope): array
    {
        $knownColumns = array_column($schema->columns, 'name');
        $clauses = [];
        $arguments = [];
        foreach ($scope as $column => $value) {
            if (!in_array($column, $knownColumns, true)) {
                throw new InvalidArgumentException(
                    "Unknown Nitro scope column {$column}.",
                );
            }
            $clauses[] = '"'.$column.'" = ?';
            $arguments[] = $value;
        }

        return [$clauses, $arguments];
    }

    public static function createTable(ModelSchema $schema): string
    {
        return 'CREATE TABLE IF NOT EXISTS "'.$schema->table.'" ('
            .implode(', ', array_map(
                static fn (Column $column): string => self::columnDefinition($column),
                $schema->columns,
            )).')';
    }

    public static function addColumn(ModelSchema $schema, Column $column): string
    {
        return 'ALTER TABLE "'.$schema->table.'" ADD COLUMN '
            .self::columnDefinition($column, true);
    }

    /** @return list<string> */
    public static function createIndexes(ModelSchema $schema): array
    {
        $indexes = [];
        foreach ($schema->columns as $column) {
            if (!$column->indexed || $column->primary) {
                continue;
            }
            $indexes[] = 'CREATE INDEX IF NOT EXISTS "nitro_'.$schema->table.'_'
                .$column->name.'" ON "'.$schema->table.'" ("'.$column->name.'")';
        }

        return $indexes;
    }

    public static function columnDefinition(Column $column, bool $alter = false): string
    {
        $sql = '"'.$column->name.'" '.match ($column->type) {
            ColumnType::Integer => 'INTEGER',
            ColumnType::Real => 'REAL',
            ColumnType::Text => 'TEXT',
            ColumnType::Blob => 'BLOB',
        };
        if ($column->primary) {
            $sql .= ' PRIMARY KEY';
        }
        if (!$column->nullable) {
            $sql .= ' NOT NULL';
            if ($alter) {
                $sql .= ' DEFAULT '.self::literal($column->default);
            }
        }

        return $sql;
    }

    public static function literal(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => "'".str_replace("'", "''", $value)."'",
        };
    }

    /**
     * Every Nitro write provides all model columns, so REPLACE is equivalent to
     * an UPSERT on the primary key and, unlike ON CONFLICT DO UPDATE (SQLite
     * 3.24, Android 11), runs on every Android version PAM Native supports.
     */
    private static function compileUpsert(ModelSchema $schema): string
    {
        $columns = array_column($schema->columns, 'name');

        return 'INSERT OR REPLACE INTO "'.$schema->table.'" ("'
            .implode('", "', $columns).'") VALUES ('
            .implode(', ', array_fill(0, count($columns), '?')).')';
    }
}
