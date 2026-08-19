<?php

namespace App\Services\Mapping;

use App\DTOs\Xs2\CountryResolutionResult;
use App\Models\Xs2Venue;

class CountryResolver
{
    public function __construct(
        private readonly LegacyMasterDataSchema $schema,
        private readonly MappingTextNormalizer $text,
    ) {}

    public function resolve(Xs2Venue $venue): CountryResolutionResult
    {
        if (! $this->schema->hasCountries()) {
            return new CountryResolutionResult(false, reason: 'The local countries table is unavailable.');
        }

        $rows = $this->schema->countryRows();
        $incomingCode = strtoupper(trim((string) $venue->country_code));

        // Only compare like-for-like ISO lengths. The live catalog's
        // `countryid` is not a controlled ISO field and is deliberately ignored.
        foreach ([2, 3] as $length) {
            if (strlen($incomingCode) !== $length) {
                continue;
            }

            foreach ($rows as $country) {
                foreach ($this->schema->countryCodeColumns() as $column) {
                    $candidate = strtoupper(trim((string) data_get($country, $column, '')));
                    if (strlen($candidate) === $length && $candidate === $incomingCode) {
                        return $this->resolved($country, 'iso_alpha_'.$length, ['country_code' => $candidate]);
                    }
                }
            }
        }

        $incomingName = $this->text->normalizeCountry((string) $venue->country_name);
        if ($incomingName !== '') {
            foreach ($rows as $country) {
                $name = $this->schema->countryName($country);
                if ($name !== null && $this->text->normalizeCountry($name) === $incomingName) {
                    return $this->resolved($country, 'exact_normalized_name', ['country_name' => $name]);
                }
            }
        }

        return new CountryResolutionResult(false, reason: 'No local country matched the XS2 country code or name.');
    }

    private function resolved(object $country, string $method, array $matched): CountryResolutionResult
    {
        return new CountryResolutionResult(
            resolved: true,
            countryId: $this->schema->countryId($country),
            confidenceScore: 100.0,
            mappingMethod: $method,
            matchedFields: $matched,
            country: $country,
        );
    }
}
