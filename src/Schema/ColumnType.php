<?php

declare(strict_types=1);

namespace Pam\Nitro\Schema;

enum ColumnType: int
{
    case Integer = 1;
    case Real = 2;
    case Text = 3;
    case Blob = 4;
}
