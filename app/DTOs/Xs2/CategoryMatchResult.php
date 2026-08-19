<?php

namespace App\DTOs\Xs2;

use Illuminate\Contracts\Support\Arrayable;

class CategoryMatchResult implements Arrayable
{
    /** @param array<string, mixed> $candidate */
    public function __construct(
        public readonly ?int $stadiumDetailId,
        public readonly ?int $stadiumSeatId,
        public readonly float $score,
        public readonly string $method,
        public readonly array $matchedFields,
        public readonly array $candidate,
    ) {}

    public function toArray(): array
    {
        return [
            'stadium_detail_id' => $this->stadiumDetailId,
            'stadium_seat_id' => $this->stadiumSeatId,
            'score' => $this->score,
            'mapping_method' => $this->method,
            'matched_fields' => $this->matchedFields,
            ...$this->candidate,
        ];
    }
}
