<?php

declare(strict_types=1);

namespace Pam\Nitro;

use InvalidArgumentException;
use Pam\Nitro\Internal\Sql;
use Pam\Nitro\Schema\ModelSchema;

/**
 * Collects heterogeneous writes that Nitro sends through ONE native call and
 * applies inside ONE SQLite transaction.
 *
 * Consecutive statements with identical SQL are coalesced into one prepared
 * statement executed once per argument set.
 */
final class Batch
{
    public const int MAX_STATEMENTS = 10_000;
    public const int MAX_ARGUMENT_SETS = 10_000;

    /** @var list<array{sql: string, argumentSets: list<list<string|int|float|bool|null>>}> */
    private array $statements = [];

    public function save(Model $model): self
    {
        return $this->add(
            Sql::upsert(ModelSchema::for($model::class)),
            [array_values($model->attributes())],
        );
    }

    /** @param list<Model> $models */
    public function saveMany(array $models): self
    {
        foreach ($models as $model) {
            $this->save($model);
        }

        return $this;
    }

    public function delete(Model $model): self
    {
        $schema = ModelSchema::for($model::class);
        $key = $model->{$schema->primary->property};
        if (!is_string($key) && !is_int($key)) {
            throw new InvalidArgumentException('Nitro delete requires a scalar primary key.');
        }

        return $this->add(Sql::deleteByPrimaryKey($schema), [[$key]]);
    }

    /**
     * @param class-string<Model> $model
     * @param array<string, string|int|float|bool|null> $scope
     */
    public function deleteWhere(string $model, array $scope): self
    {
        if ($scope === []) {
            throw new InvalidArgumentException('Nitro deleteWhere requires a non-empty scope.');
        }
        $schema = ModelSchema::for($model);
        [$clauses, $arguments] = Sql::scope($schema, $scope);

        return $this->add(
            'DELETE FROM "'.$schema->table.'" WHERE '.implode(' AND ', $clauses),
            [$arguments],
        );
    }

    /**
     * Deletes every row in the scope and upserts the replacement snapshot.
     *
     * @param class-string<Model> $model
     * @param list<Model> $models
     * @param array<string, string|int|float|bool|null> $scope
     */
    public function replaceMany(string $model, array $models, array $scope): self
    {
        $this->deleteWhere($model, $scope);
        foreach ($models as $item) {
            if ($item::class !== $model) {
                throw new InvalidArgumentException(
                    'Nitro replaceMany requires models of the declared class.',
                );
            }
            $this->save($item);
        }

        return $this;
    }

    /** @param list<string|int|float|bool|null> $arguments */
    public function execute(string $sql, array $arguments = []): self
    {
        return $this->add($sql, [$arguments]);
    }

    /** @param list<list<string|int|float|bool|null>> $argumentSets */
    public function executeMany(string $sql, array $argumentSets): self
    {
        if ($argumentSets === []) {
            throw new InvalidArgumentException('Nitro executeMany requires at least one argument set.');
        }

        return $this->add($sql, $argumentSets);
    }

    public function isEmpty(): bool
    {
        return $this->statements === [];
    }

    /**
     * Native transaction payload, splitting oversized argument lists.
     *
     * @return list<array{
     *   sql: string,
     *   arguments?: list<string|int|float|bool|null>,
     *   argumentSets?: list<list<string|int|float|bool|null>>
     * }>
     */
    public function statements(): array
    {
        $statements = [];
        foreach ($this->statements as $statement) {
            foreach (array_chunk($statement['argumentSets'], self::MAX_ARGUMENT_SETS) as $chunk) {
                $statements[] = count($chunk) === 1
                    ? ['sql' => $statement['sql'], 'arguments' => $chunk[0]]
                    : ['sql' => $statement['sql'], 'argumentSets' => $chunk];
            }
        }
        if (count($statements) > self::MAX_STATEMENTS) {
            throw new InvalidArgumentException('Nitro batches accept at most 10000 statements.');
        }

        return $statements;
    }

    /** @param list<list<string|int|float|bool|null>> $argumentSets */
    private function add(string $sql, array $argumentSets): self
    {
        if ($sql === '') {
            throw new InvalidArgumentException('Nitro batch SQL must not be empty.');
        }
        $last = array_key_last($this->statements);
        if ($last !== null && $this->statements[$last]['sql'] === $sql) {
            array_push($this->statements[$last]['argumentSets'], ...$argumentSets);

            return $this;
        }
        $this->statements[] = ['sql' => $sql, 'argumentSets' => $argumentSets];

        return $this;
    }
}
