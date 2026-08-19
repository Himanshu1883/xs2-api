<?php

namespace App\DTOs\Xs2;

use Illuminate\Contracts\Support\Arrayable;

class StadiumMatchResult implements Arrayable
{
    /** @param array<string, mixed> $candidate */
    public function __construct(
        public readonly ?int $stadiumId,
        public readonly float $score,
        public readonly string $method,
        public readonly array $matchedFields,
        public readonly array $candidate,
    ) {}

    public function toArray(): array
    {
        return [
            'stadium_id' => $this->stadiumId,
            'score' => $this->score,
            'mapping_method' => $this->method,
            'matched_fields' => $this->matchedFields,
            ...$this->candidate,
        ];
    }
}
