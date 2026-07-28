<?php

declare(strict_types=1);

namespace Pam\Nitro;

use Closure;
use InvalidArgumentException;
use Pam\Nitro\Schema\ModelSchema;

final class Query
{
    /** @var list<array{string, string|int|float|bool|null}> */
    private array $conditions = [];
    private ?string $orderColumn = null;
    private bool $descending = false;
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

    public function orderBy(string $column, bool $descending = false): self
    {
        $this->assertColumn($column);
        $copy = clone $this;
        $copy->orderColumn = $column;
        $copy->descending = $descending;

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

    /** @param Closure(list<Model>): void $callback */
    public function get(Closure $callback): int
    {
        [$sql, $arguments] = $this->compile();

        return $this->connection->query(
            $sql,
            $arguments,
            function (array $rows) use ($callback): void {
                $model = $this->model;
                $callback(array_values(array_map($model::hydrate(...), $rows)));
            },
        );
    }

    /** @param Closure(?Model): void $callback */
    public function first(Closure $callback): int
    {
        return $this->limit(1)->get(
            static fn (array $models) => $callback($models[0] ?? null),
        );
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
        if ($this->orderColumn !== null) {
            $sql .= ' ORDER BY "'.$this->orderColumn.'" '.($this->descending ? 'DESC' : 'ASC');
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
