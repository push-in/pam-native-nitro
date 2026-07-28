<?php

declare(strict_types=1);

namespace Pam\Nitro\Schema;

use BackedEnum;
use InvalidArgumentException;
use Pam\Nitro\Attributes\Children;
use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;

final class ModelSchema
{
    /**
     * @param class-string<Model> $model
     * @param list<Column> $columns
     * @param array<string, Children> $relations
     */
    private function __construct(
        public readonly string $model,
        public readonly string $table,
        public readonly array $columns,
        public readonly Column $primary,
        public readonly array $relations,
    ) {
    }

    /** @var array<class-string<Model>, self> */
    private static array $cache = [];

    /** @param class-string<Model> $model */
    public static function for(string $model): self
    {
        return self::$cache[$model] ??= self::reflect($model);
    }

    /** @param class-string<Model> $model */
    private static function reflect(string $model): self
    {
        $reflection = new ReflectionClass($model);
        if (!$reflection->isSubclassOf(Model::class)) {
            throw new InvalidArgumentException("{$model} must extend ".Model::class.'.');
        }

        $table = $model::table();
        self::assertIdentifier($table);
        $columns = [];
        $relations = [];
        $primary = null;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $children = $property->getAttributes(Children::class)[0] ?? null;
            if ($children !== null) {
                $relations[$property->getName()] = $children->newInstance();
            }
            $field = $property->getAttributes(Field::class)[0] ?? null;
            $isPrimary = $property->getAttributes(PrimaryKey::class) !== [];
            if ($field === null && !$isPrimary) {
                continue;
            }
            /** @var Field $definition */
            $definition = $field?->newInstance() ?? new Field();
            $name = $definition->name ?? self::snake($property->getName());
            self::assertIdentifier($name);
            [$type, $enum] = self::columnType($property);
            $column = new Column(
                property: $property->getName(),
                name: $name,
                type: $type,
                primary: $isPrimary,
                indexed: $definition->indexed,
                nullable: $definition->nullable,
                enum: $enum,
                boolean: $property->getType() instanceof ReflectionNamedType
                    && $property->getType()->getName() === 'bool',
            );
            $columns[] = $column;
            if ($isPrimary) {
                if ($primary !== null) {
                    throw new InvalidArgumentException("{$model} has multiple primary keys.");
                }
                $primary = $column;
            }
        }

        if ($primary === null) {
            throw new InvalidArgumentException("{$model} requires one #[PrimaryKey] property.");
        }

        return new self($model, $table, $columns, $primary, $relations);
    }

    /** @return array{ColumnType, class-string<BackedEnum>|null} */
    private static function columnType(ReflectionProperty $property): array
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new InvalidArgumentException("{$property->getName()} requires a named scalar or backed enum type.");
        }
        $name = $type->getName();
        if (enum_exists($name) && is_subclass_of($name, BackedEnum::class)) {
            $backing = (new ReflectionEnum($name))->getBackingType()?->getName();
            if ($backing !== 'int') {
                throw new InvalidArgumentException(
                    "{$property->getName()} must use an integer-backed enum.",
                );
            }

            return [ColumnType::Integer, $name];
        }

        return [match ($name) {
            'int', 'bool' => ColumnType::Integer,
            'float' => ColumnType::Real,
            'string' => ColumnType::Text,
            default => throw new InvalidArgumentException(
                "{$property->getName()} uses unsupported type {$name}.",
            ),
        }, null];
    }

    private static function assertIdentifier(string $value): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Unsafe Nitro identifier {$value}.");
        }
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
