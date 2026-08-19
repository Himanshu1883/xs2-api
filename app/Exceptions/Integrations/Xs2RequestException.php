<?php

namespace App\Exceptions\Integrations;

class Xs2RequestException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
