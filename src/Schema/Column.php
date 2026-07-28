<?php

declare(strict_types=1);

namespace Pam\Nitro\Schema;

final readonly class Column
{
    public function __construct(
        public string $property,
        public string $name,
        public ColumnType $type,
        public bool $primary,
        public bool $indexed,
        public bool $nullable,
        public ?string $enum,
        public bool $boolean,
    ) {
    }
}
