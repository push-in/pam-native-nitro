<?php

declare(strict_types=1);

namespace Pam\Nitro\Attributes;

use Attribute;
use Pam\Nitro\Model;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Children
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $model,
        public string $foreignKey,
    ) {
    }
}
