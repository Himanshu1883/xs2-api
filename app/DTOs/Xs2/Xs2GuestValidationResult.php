<?php

namespace App\DTOs\Xs2;

use Illuminate\Contracts\Support\Arrayable;

class Xs2GuestValidationResult implements Arrayable
{
    /** @param list<array{guest_index:int, reason_code:string, message:string}> $violations */
    public function __construct(
        public readonly bool $valid,
        public readonly string $reasonCode,
        public readonly string $message,
        public readonly array $violations = [],
    ) {}

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'violations' => $this->violations,
        ];
    }
}
