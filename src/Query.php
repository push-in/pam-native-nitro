<?php

declare(strict_types=1);

namespace Pam\Nitro;

use Closure;
use InvalidArgumentException;
use Pam\Nitro\Internal\Bridge;
use Pam\Nitro\Schema\ModelSchema;
use Throwable;

final class Query
{
    /** @var list<array{string, string|int|float|bool|null}> */
    private array $conditions = [];
    /** @var list<array{string, bool}> column and descending, in priority order */
    private array $orders = [];
    private int $limit = 100;

    /** @param class-string<Model> $model */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $model,
    ) {
    }

    public function where(string $column, string|int|float|bool|null $value): self
    {
        $this->assertColumn($column);
        $copy = clone $this;
        $copy->conditions[] = [$column, $value];

        return $copy;
    }

    /**
     * Adds sort keys after the ones already set: each call (or each column of
     * a list, all in the same direction) only breaks ties of the previous
     * keys, like SQL `ORDER BY a, b`. Use a unique, stable last key (for
     * example the primary key) when rows can share a value; SQLite's rowid
     * is not one, since upserts re-insert the row (`INSERT OR REPLACE`).
     *
     * @param string|array<string> $column one column or a non-empty list
     */
    public function orderBy(string|array $column, bool $descending = false): self
    {
        $columns = is_string($column) ? [$column] : $column;
        if ($columns === [] || !array_is_list($columns)) {
            throw new InvalidArgumentException('Nitro orderBy() needs a column or a list of columns.');
        }
        $copy = clone $this;
        foreach ($columns as $name) {
            $this->assertColumn($name);
            if (in_array($name, array_column($copy->orders, 0), true)) {
                throw new InvalidArgumentException("Nitro column {$name} is already ordered.");
            }
            $copy->orders[] = [$name, $descending];
        }

        return $copy;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, true);
    }

    public function limit(int $limit): self
    {
        $copy = clone $this;
        $copy->limit = max(1, min(1_000, $limit));

        return $copy;
    }

    /**
     * Results larger than one bridge payload are read in pages transparently.
     *
     * @param Closure(list<Model>): void $callback
     * @param Closure(string): void|null $failure receives native failures
     */
    public function get(Closure $callback, ?Closure $failure = null): int
    {
        [$sql, $arguments] = $this->compile();
        $failure = Bridge::failure($failure);

        return $this->connection->query(
            $sql,
            $arguments,
            function (array $rows) use ($callback, $failure): void {
                $model = $this->model;
                try {
                    $models = array_map($model::hydrate(...), $rows);
                } catch (Throwable $error) {
                    $failure('Nitro could not hydrate '.$model.': '.$error->getMessage());

                    return;
                }
                $callback($models);
            },
            $failure,
        );
    }

    /**
     * @param Closure(?Model): void $callback
     * @param Closure(string): void|null $failure receives native failures
     */
    public function first(Closure $callback, ?Closure $failure = null): int
    {
        return $this->limit(1)->get(
            static fn (array $models) => $callback($models[0] ?? null),
            $failure,
        );
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return $this->model;
    }

    public function maxRows(): int
    {
        return $this->limit;
    }

    /**
     * Compiled SELECT statement and its positional arguments.
     *
     * @return array{string, list<string|int|float|bool|null>}
     */
    public function toSql(): array
    {
        return $this->compile();
    }

    /** @return array{string, list<string|int|float|bool|null>} */
    private function compile(): array
    {
        $schema = ModelSchema::for($this->model);
        $sql = 'SELECT * FROM "'.$schema->table.'"';
        $arguments = [];
        if ($this->conditions !== []) {
            $clauses = [];
            foreach ($this->conditions as [$column, $value]) {
                $clauses[] = '"'.$column.'" = ?';
                $arguments[] = $value;
            }
            $sql .= ' WHERE '.implode(' AND ', $clauses);
        }
        if ($this->orders !== []) {
            $sql .= ' ORDER BY '.implode(', ', array_map(
                static fn (array $order): string => '"'.$order[0].'" '.($order[1] ? 'DESC' : 'ASC'),
                $this->orders,
            ));
        }
        $sql .= ' LIMIT '.$this->limit;

        return [$sql, $arguments];
    }

    private function assertColumn(string $column): void
    {
        $known = array_column(ModelSchema::for($this->model)->columns, 'name');
        if (!in_array($column, $known, true)) {
            throw new InvalidArgumentException("Unknown Nitro column {$column}.");
        }
    }
}
