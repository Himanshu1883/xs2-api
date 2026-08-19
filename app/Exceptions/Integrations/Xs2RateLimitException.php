<?php

namespace App\Exceptions\Integrations;

class Xs2RateLimitException extends Xs2RequestException
{
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct("Local XS2 rate limit reached. Retry after {$retryAfter} seconds.", 429);
    }
}
