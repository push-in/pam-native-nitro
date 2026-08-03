<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

enum MutationOperation: int
{
    case Upsert = 1;
    case Delete = 2;
}
