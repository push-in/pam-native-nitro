<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

use InvalidArgumentException;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maximumAttempts = 8,
        public int $baseDelaySeconds = 2,
        public int $maximumDelaySeconds = 300,
    ) {
        if ($maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('maximumAttempts must be between 1 and 100.');
        }
        if ($baseDelaySeconds < 1 || $baseDelaySeconds > 86_400) {
            throw new InvalidArgumentException('baseDelaySeconds must be between 1 and 86400.');
        }
        if ($maximumDelaySeconds < $baseDelaySeconds || $maximumDelaySeconds > 604_800) {
            throw new InvalidArgumentException(
                'maximumDelaySeconds must be between baseDelaySeconds and 604800.',
            );
        }
    }

    public function delayForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('attempt must be greater than zero.');
        }

        $exponent = min($attempt - 1, 30);

        return min($this->maximumDelaySeconds, $this->baseDelaySeconds * (2 ** $exponent));
    }
}
