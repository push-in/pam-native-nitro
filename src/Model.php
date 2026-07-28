<?php

declare(strict_types=1);

namespace Pam\Nitro;

use BackedEnum;
use Pam\Nitro\Relations\ChildrenRelation;
use Pam\Nitro\Schema\ModelSchema;

abstract class Model
{
    final public function __construct()
    {
        foreach (ModelSchema::for(static::class)->relations as $property => $relation) {
            $this->{$property} = new ChildrenRelation(
                $this,
                $relation->model,
                $relation->foreignKey,
            );
        }
    }

    final public static function query(): Query
    {
        return Nitro::query(static::class);
    }

    final public static function find(string|int $id, \Closure $callback): int
    {
        return static::query()->where(
            ModelSchema::for(static::class)->primary->name,
            $id,
        )->first($callback);
    }

    final public function save(?\Closure $callback = null): int
    {
        return Nitro::save($this, $callback);
    }

    /** @return array<string, string|int|float|bool|null> */
    final public function attributes(): array
    {
        $values = [];
        foreach (ModelSchema::for(static::class)->columns as $column) {
            $value = $this->{$column->property};
            $values[$column->name] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $values;
    }

    /** @param array<string, string|int|float|bool|null> $row */
    final public static function hydrate(array $row): static
    {
        $model = new static();
        foreach (ModelSchema::for(static::class)->columns as $column) {
            $value = $row[$column->name] ?? null;
            if ($column->enum !== null && $value !== null) {
                $value = $column->enum::from(
                    is_int($value) ? $value : (int) $value,
                );
            } elseif ($column->boolean && $value !== null) {
                $value = (bool) $value;
            }
            $model->{$column->property} = $value;
        }

        return $model;
    }

    abstract public static function table(): string;
}
