<?php

namespace App\DTOs\Xs2;

use Illuminate\Contracts\Support\Arrayable;

class CountryResolutionResult implements Arrayable
{
    /** @param array<string, mixed> $matchedFields */
    public function __construct(
        public readonly bool $resolved,
        public readonly ?int $countryId = null,
        public readonly ?float $confidenceScore = null,
        public readonly ?string $mappingMethod = null,
        public readonly array $matchedFields = [],
        public readonly ?string $reason = null,
        public readonly ?object $country = null,
    ) {}

    public function toArray(): array
    {
        return [
            'resolved' => $this->resolved,
            'country_id' => $this->countryId,
            'confidence_score' => $this->confidenceScore,
            'mapping_method' => $this->mappingMethod,
            'matched_fields' => $this->matchedFields,
            'reason' => $this->reason,
        ];
    }
}
