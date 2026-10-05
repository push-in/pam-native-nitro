<?php

declare(strict_types=1);

namespace Pam\Nitro;

use Closure;
use LogicException;
use Pam\Nitro\Internal\Sql;
use Pam\Nitro\Schema\ModelSchema;
use Pam\Nitro\Schema\SchemaReconciler;

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

    /**
     * Creates or migrates one model table.
     *
     * @param class-string<Model> $model
     */
    public static function createTable(string $model, ?Closure $callback = null): int
    {
        return self::prepare([$model], $callback ?? static function (): void {
        });
    }

    /**
     * Reconciles model tables, columns and indexes before invoking the callback.
     *
     * Costs ONE native query when the stored schema fingerprints match and ZERO
     * when this process already verified them. Changed schemas are migrated in
     * one additional native transaction.
     *
     * @param list<class-string<Model>> $models
     */
    public static function prepare(array $models, Closure $callback): int
    {
        if ($models === []) {
            $callback();

            return 0;
        }

        return SchemaReconciler::reconcile(
            self::connection(),
            array_map(ModelSchema::for(...), $models),
            $callback,
        );
    }

    /**
     * Applies every write collected by $build through ONE native call and ONE
     * SQLite transaction. An empty batch invokes the callback immediately.
     *
     * @param Closure(Batch): mixed $build
     */
    public static function batch(Closure $build, ?Closure $callback = null): int
    {
        $batch = new Batch();
        $build($batch);
        if ($batch->isEmpty()) {
            $callback?->__invoke();

            return 0;
        }

        return self::connection()->transaction($batch->statements(), $callback);
    }

    /**
     * Runs several model queries through ONE native call and ONE result.
     *
     * Results keep the keys of $queries and the order of every query.
     *
     * @template TKey of array-key
     * @param array<TKey, Query> $queries
     * @param Closure(array<TKey, list<Model>>): void $callback
     */
    public static function fetch(array $queries, Closure $callback): int
    {
        if ($queries === []) {
            $callback([]);

            return 0;
        }
        $keys = array_keys($queries);
        $arms = [];
        $arguments = [];
        $schemas = [];
        $rows = 0;
        $width = 0;
        foreach (array_values($queries) as $offset => $query) {
            $schemas[$offset] = ModelSchema::for($query->model());
            $width = max($width, count($schemas[$offset]->columns));
            $rows += $query->maxRows();
        }
        if ($rows > 1_000) {
            throw new \InvalidArgumentException(
                'Nitro fetch may return at most 1000 rows; lower the query limits.',
            );
        }
        if ($width >= 256) {
            throw new \InvalidArgumentException('Nitro fetch supports at most 255 columns.');
        }
        foreach (array_values($queries) as $offset => $query) {
            [$sql, $queryArguments] = $query->toSql();
            $projection = [$offset.' AS "nitro_query"'];
            for ($index = 0; $index < $width; ++$index) {
                $column = $schemas[$offset]->columns[$index] ?? null;
                $projection[] = ($column === null ? 'NULL' : '"'.$column->name.'"')
                    .' AS "c'.$index.'"';
            }
            $arms[] = 'SELECT '.implode(', ', $projection).' FROM ('.$sql.')';
            array_push($arguments, ...$queryArguments);
        }

        return self::connection()->query(
            implode(' UNION ALL ', $arms),
            $arguments,
            static function (array $rows) use ($keys, $schemas, $callback): void {
                $results = array_fill_keys($keys, []);
                foreach ($rows as $row) {
                    $offset = (int) ($row['nitro_query'] ?? -1);
                    $schema = $schemas[$offset] ?? null;
                    if ($schema === null) {
                        continue;
                    }
                    $values = [];
                    foreach ($schema->columns as $index => $column) {
                        $values[$column->name] = $row['c'.$index] ?? null;
                    }
                    $model = $schema->model;
                    $results[$keys[$offset]][] = $model::hydrate($values);
                }
                $callback($results);
            },
        );
    }

    public static function save(Model $model, ?Closure $callback = null): int
    {
        $schema = ModelSchema::for($model::class);
        $values = $model->attributes();

        return self::connection()->execute(
            Sql::upsert($schema),
            array_values($values),
            $callback,
        );
    }

    public static function delete(Model $model, ?Closure $callback = null): int
    {
        $schema = ModelSchema::for($model::class);
        $primary = $schema->primary;

        return self::connection()->execute(
            Sql::deleteByPrimaryKey($schema),
            [$model->{$primary->property}],
            $callback,
        );
    }

    /**
     * @param class-string<Model> $model
     * @param array<string, string|int|float|bool|null> $scope
     */
    public static function deleteWhere(
        string $model,
        array $scope,
        ?Closure $callback = null,
    ): int {
        if ($scope === []) {
            throw new \InvalidArgumentException(
                'Nitro deleteWhere requires a non-empty scope.',
            );
        }
        $schema = ModelSchema::for($model);
        [$clauses, $arguments] = Sql::scope($schema, $scope);

        return self::connection()->execute(
            'DELETE FROM "'.$schema->table.'" WHERE '.implode(' AND ', $clauses),
            $arguments,
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
            Sql::upsert($schema),
            $argumentSets,
            $callback,
        );
    }

    /**
     * Atomically replaces every row inside a scoped collection snapshot.
     *
     * @param class-string<Model> $model
     * @param list<Model> $models
     * @param array<string, string|int|float|bool|null> $scope
     */
    public static function replaceMany(
        string $model,
        array $models,
        array $scope,
        ?Closure $callback = null,
    ): int {
        if ($scope === []) {
            throw new \InvalidArgumentException(
                'Nitro replaceMany requires a non-empty scope.',
            );
        }
        if (count($models) > 9_999) {
            throw new \InvalidArgumentException(
                'Nitro replaceMany accepts at most 9999 models.',
            );
        }
        $batch = (new Batch())->replaceMany($model, $models, $scope);

        return self::connection()->transaction($batch->statements(), $callback);
    }

    public static function connection(): Connection
    {
        return self::$connection ?? throw new LogicException(
            'Call Nitro::boot() before querying models.',
        );
    }
}
