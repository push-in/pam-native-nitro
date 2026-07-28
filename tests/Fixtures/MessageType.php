<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests\Fixtures;

enum MessageType: int
{
    case Text = 1;
    case Image = 2;
}
