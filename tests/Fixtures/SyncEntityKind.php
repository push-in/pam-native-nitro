<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests\Fixtures;

enum SyncEntityKind: int
{
    case Message = 1;
    case Conversation = 2;
}
