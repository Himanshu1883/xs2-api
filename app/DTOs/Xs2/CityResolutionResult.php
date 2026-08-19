<?php

namespace App\DTOs\Xs2;

use Illuminate\Contracts\Support\Arrayable;

class CityResolutionResult implements Arrayable
{
    /** @param array<string, mixed> $matchedFields @param list<array<string, mixed>> $candidateScores */
    public function __construct(
        public readonly bool $resolved,
        public readonly ?int $cityId = null,
        public readonly ?float $confidenceScore = null,
        public readonly ?string $mappingMethod = null,
        public readonly array $matchedFields = [],
        public readonly array $candidateScores = [],
        public readonly ?string $reason = null,
        public readonly ?object $city = null,
    ) {}

    public function toArray(): array
    {
        return [
            'resolved' => $this->resolved,
            'city_id' => $this->cityId,
            'confidence_score' => $this->confidenceScore,
            'mapping_method' => $this->mappingMethod,
            'matched_fields' => $this->matchedFields,
            'candidate_scores' => $this->candidateScores,
            'reason' => $this->reason,
        ];
    }
}
