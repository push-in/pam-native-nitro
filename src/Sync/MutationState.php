<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

enum MutationState: int
{
    case Pending = 1;
    case InFlight = 2;
    case RetryScheduled = 3;
    case Acknowledged = 4;
    case Failed = 5;
}
