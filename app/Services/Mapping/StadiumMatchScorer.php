<?php

namespace App\Services\Mapping;

use App\DTOs\Xs2\StadiumMatchResult;
use App\Models\Xs2Venue;

class StadiumMatchScorer
{
    public function __construct(
        private readonly LegacyMasterDataSchema $schema,
        private readonly MappingTextNormalizer $text,
    ) {}

    public function score(Xs2Venue $venue, object $stadium, int $resolvedCountryId): StadiumMatchResult
    {
        $name = $this->schema->stadiumName($stadium) ?? '';
        $requested = (string) $venue->venue_name;
        $normalizedRequested = $this->text->normalizeStadium($requested);
        $normalizedCandidate = $this->text->normalizeStadium($name);
        $matched = ['same_city']; // Query scope proves this rather than trusting source text.

        if (strcasecmp(trim($requested), trim($name)) === 0 && $requested !== '') {
            $nameScore = 80.0;
            $method = 'exact_name';
            $matched[] = 'exact_name';
        } elseif ($normalizedRequested !== '' && $normalizedRequested === $normalizedCandidate) {
            $nameScore = 80.0;
            $method = 'normalized_name';
            $matched[] = 'normalized_name';
        } else {
            $requestedTokens = implode(' ', $this->text->stadiumTokens($requested));
            $candidateTokens = implode(' ', $this->text->stadiumTokens($name));
            similar_text($requestedTokens, $candidateTokens, $similarity);
            $nameScore = min(80.0, max(0.0, $similarity * .8));
            $method = 'fuzzy';
            if ($nameScore > 0) {
                $matched[] = 'similar_name';
            }
        }

        $score = $nameScore + 20.0;
        if ($this->schema->stadiumCountryId($stadium) === $resolvedCountryId) {
            $score += 10.0;
            $matched[] = 'same_country';
        }

        $coordinates = $this->schema->stadiumCoordinates($stadium);
        if ($venue->latitude !== null && $venue->longitude !== null
            && $coordinates['latitude'] !== null && $coordinates['longitude'] !== null) {
            $distance = $this->distanceKm((float) $venue->latitude, (float) $venue->longitude, $coordinates['latitude'], $coordinates['longitude']);
            if ($distance <= (float) config('xs2.mapping.coordinate_match_radius_km', 2)) {
                // Coordinates only reinforce a city/name comparison.
                $score += 10.0;
                $matched[] = 'coordinate_proximity';
            }
        }

        return new StadiumMatchResult(
            stadiumId: $this->schema->stadiumId($stadium),
            score: round(min(100, $score), 2),
            method: $method,
            matchedFields: $matched,
            candidate: ['name' => $name],
        );
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radius = 6371.0088;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
