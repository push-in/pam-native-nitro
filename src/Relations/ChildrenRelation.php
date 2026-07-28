<?php

declare(strict_types=1);

namespace Pam\Nitro\Relations;

use Closure;
use Pam\Nitro\Model;
use Pam\Nitro\Schema\ModelSchema;

final readonly class ChildrenRelation
{
    /** @param class-string<Model> $related */
    public function __construct(
        private Model $parent,
        private string $related,
        private string $foreignKey,
    ) {
    }

    /** @param Closure(list<Model>): void $callback */
    public function get(Closure $callback): int
    {
        $schema = ModelSchema::for($this->parent::class);
        $primary = $schema->primary->property;

        return $this->related::query()
            ->where($this->foreignKey, $this->parent->{$primary})
            ->get($callback);
    }
}
