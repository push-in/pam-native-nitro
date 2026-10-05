<?php

declare(strict_types=1);

namespace Pam\Nitro\Internal;

use RuntimeException;

/**
 * One row is larger than PAM Native can carry in a single module call.
 *
 * @internal
 */
final class OversizedPayload extends RuntimeException
{
}
