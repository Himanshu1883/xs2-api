<?php

namespace App\Services\Mapping;

use App\DTOs\Xs2\CityResolutionResult;
use App\Models\Xs2Venue;

class CityResolver
{
    public function __construct(
        private readonly LegacyMasterDataSchema $schema,
        private readonly MappingTextNormalizer $text,
    ) {}

    public function resolve(Xs2Venue $venue, object|array|int $country): CityResolutionResult
    {
        $countryId = $this->schema->countryId($country);
        if (! $countryId) {
            return new CityResolutionResult(false, reason: 'A resolved local country is required before city matching.');
        }

        $cities = $this->schema->citiesForCountry($countryId);
        $requested = trim((string) $venue->city_name);
        if ($requested === '') {
            return new CityResolutionResult(false, reason: 'XS2 venue city is missing.');
        }

        foreach ($cities as $city) {
            $name = $this->schema->cityName($city);
            if ($name !== null && strcasecmp($name, $requested) === 0) {
                return $this->resolved($city, 'exact_name', 100, ['city_name' => $name]);
            }
        }

        $normalized = $this->text->normalizeCity($requested);
        foreach ($cities as $city) {
            $name = $this->schema->cityName($city);
            if ($name !== null && $this->text->normalizeCity($name) === $normalized) {
                return $this->resolved($city, 'normalized_name', 100, ['city_name' => $name]);
            }
        }

        $candidates = collect($cities)->map(function (object $city) use ($normalized): array {
            $name = $this->schema->cityName($city) ?? '';
            similar_text($normalized, $this->text->normalizeCity($name), $score);

            return [
                'city_id' => $this->schema->cityId($city),
                'name' => $name,
                'score' => round($score, 2),
            ];
        })->sortByDesc('score')->take(5)->values()->all();

        $best = $candidates[0] ?? null;
        if ($best && $best['score'] >= (float) config('xs2.mapping.city_auto_map_threshold', 95)) {
            $city = collect($cities)->first(fn (object $row): bool => $this->schema->cityId($row) === $best['city_id']);

            return $this->resolved($city, 'fuzzy', (float) $best['score'], ['similar_city_name' => $best['name']], $candidates);
        }

        // Auto-create flags are intentionally ignored. Master data is read-only.
        return new CityResolutionResult(false, candidateScores: $candidates, reason: 'No sufficiently confident local city matched within the resolved country.');
    }

    /** @param array<string,mixed> $matched @param list<array<string,mixed>> $candidates */
    private function resolved(object $city, string $method, float $score, array $matched, array $candidates = []): CityResolutionResult
    {
        return new CityResolutionResult(
            resolved: true,
            cityId: $this->schema->cityId($city),
            confidenceScore: $score,
            mappingMethod: $method,
            matchedFields: $matched,
            candidateScores: $candidates,
            city: $city,
        );
    }
}
