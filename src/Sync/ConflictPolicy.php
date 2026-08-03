<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

enum ConflictPolicy: int
{
    case ServerWins = 1;
    case ClientWins = 2;
    case LastWriteWins = 3;
    case Manual = 4;
}
