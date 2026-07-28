<?php

declare(strict_types=1);

namespace Pam\Nitro\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    public function __construct(
        public ?string $name = null,
        public bool $indexed = false,
        public bool $nullable = false,
    ) {
    }
}
