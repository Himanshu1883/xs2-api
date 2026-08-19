<?php

namespace App\Exceptions;

use RuntimeException;

class Xs2LocalRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds
    ) {
        parent::__construct(
            "Local XS2 rate limit reached. Retry after {$retryAfterSeconds} seconds."
        );
    }
}
