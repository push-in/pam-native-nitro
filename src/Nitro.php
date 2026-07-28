<?php

declare(strict_types=1);

namespace Pam\Nitro;

use Closure;
use LogicException;
use Pam\Nitro\Schema\ColumnType;
use Pam\Nitro\Schema\ModelSchema;

final class Nitro
{
    private static ?Connection $connection = null;

    private function __construct()
    {
    }

    public static function boot(string $database = 'pam-native-nitro.db'): Connection
    {
        return self::$connection ??= new Connection($database);
    }

    /** @param class-string<Model> $model */
    public static function query(string $model): Query
    {
        return new Query(self::connection(), $model);
    }

    /** @param class-string<Model> $model */
    public static function createTable(string $model, ?Closure $callback = null): int
    {
        $schema = ModelSchema::for($model);
        $columns = array_map(
            static function ($column): string {
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
                }

                return $sql;
            },
            $schema->columns,
        );

        return self::connection()->execute(
            'CREATE TABLE IF NOT EXISTS "'.$schema->table.'" ('.implode(', ', $columns).')',
            callback: static function () use ($schema, $callback): void {
                $indexes = array_values(array_filter(
                    $schema->columns,
                    static fn ($column): bool => $column->indexed && !$column->primary,
                ));
                self::createIndexes($schema->table, $indexes, 0, $callback);
            },
        );
    }

    /**
     * Creates model tables and indexes sequentially before invoking the callback.
     *
     * @param list<class-string<Model>> $models
     */
    public static function prepare(array $models, Closure $callback): int
    {
        if ($models === []) {
            $callback();

            return 0;
        }

        return self::prepareAt($models, 0, $callback);
    }

    public static function save(Model $model, ?Closure $callback = null): int
    {
        $schema = ModelSchema::for($model::class);
        $values = $model->attributes();

        return self::connection()->execute(
            self::upsertSql($schema),
            array_values($values),
            $callback,
        );
    }

    /**
     * Persists homogeneous models through one bridge call and one native transaction.
     *
     * @param list<Model> $models
     */
    public static function saveMany(array $models, ?Closure $callback = null): int
    {
        if ($models === []) {
            throw new \InvalidArgumentException('Nitro saveMany requires at least one model.');
        }
        if (count($models) > 10_000) {
            throw new \InvalidArgumentException('Nitro saveMany accepts at most 10000 models.');
        }
        $class = $models[0]::class;
        $schema = ModelSchema::for($class);
        $argumentSets = [];
        foreach ($models as $model) {
            if ($model::class !== $class) {
                throw new \InvalidArgumentException(
                    'Nitro saveMany requires models of the same class.',
                );
            }
            $argumentSets[] = array_values($model->attributes());
        }

        return self::connection()->executeMany(
            self::upsertSql($schema),
            $argumentSets,
            $callback,
        );
    }

    private static function connection(): Connection
    {
        return self::$connection ?? throw new LogicException(
            'Call Nitro::boot() before querying models.',
        );
    }

    /**
     * @param list<class-string<Model>> $models
     */
    private static function prepareAt(array $models, int $offset, Closure $callback): int
    {
        return self::createTable(
            $models[$offset],
            static function () use ($models, $offset, $callback): void {
                $next = $offset + 1;
                if ($next >= count($models)) {
                    $callback();

                    return;
                }
                self::prepareAt($models, $next, $callback);
            },
        );
    }

    private static function upsertSql(ModelSchema $schema): string
    {
        $columns = array_column($schema->columns, 'name');
        $updates = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== $schema->primary->name,
        ));
        $conflict = $updates === []
            ? 'DO NOTHING'
            : 'DO UPDATE SET '.implode(', ', array_map(
                static fn (string $column): string => '"'.$column.'" = excluded."'.$column.'"',
                $updates,
            ));

        return 'INSERT INTO "'.$schema->table.'" ("'
            .implode('", "', $columns).'") VALUES ('
            .implode(', ', array_fill(0, count($columns), '?')).') '
            .'ON CONFLICT("'.$schema->primary->name.'") '.$conflict;
    }

    /** @param list<\Pam\Nitro\Schema\Column> $indexes */
    private static function createIndexes(
        string $table,
        array $indexes,
        int $offset,
        ?Closure $callback,
    ): void {
        $column = $indexes[$offset] ?? null;
        if ($column === null) {
            $callback?->__invoke();

            return;
        }
        $name = 'nitro_'.$table.'_'.$column->name;
        self::connection()->execute(
            'CREATE INDEX IF NOT EXISTS "'.$name.'" ON "'.$table.'" ("'.$column->name.'")',
            callback: static fn () => self::createIndexes(
                $table,
                $indexes,
                $offset + 1,
                $callback,
            ),
        );
    }
}
