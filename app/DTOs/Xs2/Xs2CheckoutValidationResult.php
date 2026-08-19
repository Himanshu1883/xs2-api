<?php

namespace App\DTOs\Xs2;

use Illuminate\Contracts\Support\Arrayable;

class Xs2CheckoutValidationResult implements Arrayable
{
    /** @param list<int> $allowedQuantities @param array<string,mixed>|null $rawTicket @param list<array{guest_index:int, reason_code:string, message:string}> $guestViolations */
    public function __construct(
        public readonly bool $valid,
        public readonly string $reasonCode,
        public readonly string $message,
        public readonly ?int $latestStock = null,
        public readonly ?int $latestPrice = null,
        public readonly ?string $latestCurrency = null,
        public readonly array $allowedQuantities = [],
        public readonly ?array $rawTicket = null,
        public readonly array $guestViolations = [],
    ) {}

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'latest_stock' => $this->latestStock,
            'latest_price' => $this->latestPrice,
            'latest_currency' => $this->latestCurrency,
            'allowed_quantities' => $this->allowedQuantities,
            'raw_ticket' => $this->rawTicket,
            'guest_violations' => $this->guestViolations,
        ];
    }
}
