<?php

namespace App\Services\Mapping;

use App\Jobs\ResolvePendingXs2Listings;
use App\Models\MatchInfo;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StadiumMappingService
{
    /** @var array<string, string> */
    private const ISO_ALPHA3_TO_ALPHA2 = [
        'GBR' => 'GB',
    ];

    public function __construct(
        private readonly CountryResolver $countries,
        private readonly CityResolver $cities,
        private readonly LegacyMasterDataSchema $schema,
        private readonly StadiumMatchScorer $scorer,
        private readonly MappingTextNormalizer $text,
    ) {}

    public function isConfirmed(?Xs2StadiumMapping $mapping): bool
    {
        return $mapping instanceof Xs2StadiumMapping
            && $mapping->status === 'mapped'
            && $mapping->stadium_id !== null;
    }

    public function confirm(Xs2StadiumMapping $mapping, int $stadiumId): Xs2StadiumMapping
    {
        $stadium = $this->requireStadium($stadiumId);
        $this->assertStadiumMatchesResolvedCity($mapping, $stadium);

        return $this->persistConfirmedMapping($mapping, $stadiumId, 'manual');
    }

    /**
     * Administrator-selected stadium mapping. Manual selection already implies
     * the venue match is intentional, so city guardrails use alias-aware
     * matching and the resolved city/country are aligned to the stadium.
     */
    public function confirmManual(Xs2StadiumMapping $mapping, int $stadiumId): Xs2StadiumMapping
    {
        $stadium = $this->requireStadium($stadiumId);

        return $this->persistConfirmedMapping($mapping, $stadiumId, 'manual', $stadium);
    }

    private function requireStadium(int $stadiumId): object
    {
        $stadium = $this->schema->stadiumById($stadiumId);
        if (! $stadium) {
            throw ValidationException::withMessages(['stadium_id' => ['Select an existing local stadium.']]);
        }

        return $stadium;
    }

    private function assertStadiumMatchesResolvedCity(Xs2StadiumMapping $mapping, object $stadium): void
    {
        if (! $mapping->resolved_city_id) {
            return;
        }

        $stadiumCityId = $this->schema->stadiumCityId($stadium);
        if ($stadiumCityId === null) {
            return;
        }

        if ($this->citiesMatchByIdOrAlias((int) $mapping->resolved_city_id, $stadiumCityId)) {
            return;
        }

        $resolvedCityName = $this->cityLabel((int) $mapping->resolved_city_id);
        $stadiumCityName = $this->cityLabel($stadiumCityId);
        $message = $resolvedCityName && $stadiumCityName
            ? "The stadium belongs to {$stadiumCityName}, but this XS2 venue resolved to {$resolvedCityName}. Choose a stadium in the resolved city."
            : 'The stadium must belong to the resolved local city.';

        throw ValidationException::withMessages(['stadium_id' => [$message]]);
    }

    private function citiesMatchByIdOrAlias(int $resolvedCityId, int $stadiumCityId): bool
    {
        if ($resolvedCityId === $stadiumCityId) {
            return true;
        }

        $resolvedCity = $this->schema->cityById($resolvedCityId);
        $stadiumCity = $this->schema->cityById($stadiumCityId);
        if (! $resolvedCity || ! $stadiumCity) {
            return false;
        }

        $resolvedName = $this->text->normalizeCity($this->schema->cityName($resolvedCity) ?? '');
        $stadiumName = $this->text->normalizeCity($this->schema->cityName($stadiumCity) ?? '');

        return $resolvedName !== '' && $resolvedName === $stadiumName;
    }

    private function cityLabel(int $cityId): ?string
    {
        $city = $this->schema->cityById($cityId);

        return $city ? $this->schema->cityName($city) : null;
    }

    private function persistConfirmedMapping(
        Xs2StadiumMapping $mapping,
        int $stadiumId,
        string $mappingMethod,
        ?object $stadium = null,
    ): Xs2StadiumMapping {
        $attributes = [
            'stadium_id' => $stadiumId,
            'status' => 'mapped',
            'mapping_method' => $mappingMethod,
            'manually_confirmed' => true,
            'mapped_at' => now(),
            'mapping_error' => null,
        ];

        if ($stadium) {
            $stadiumCityId = $this->schema->stadiumCityId($stadium);
            if ($stadiumCityId !== null) {
                $attributes['resolved_city_id'] = $stadiumCityId;
            }

            $stadiumCountryId = $this->schema->stadiumCountryId($stadium);
            if ($stadiumCountryId !== null) {
                $attributes['resolved_country_id'] = $stadiumCountryId;
            }
        }

        $mapping = DB::transaction(function () use ($mapping, $attributes): Xs2StadiumMapping {
            $mapping = Xs2StadiumMapping::query()->lockForUpdate()->findOrFail($mapping->id);
            $mapping->update($attributes);

            return $mapping;
        });

        ResolvePendingXs2Listings::dispatchAfterMappingChange('stadium', $mapping->id);

        return $mapping;
    }

    /**
     * When an XS2 event is linked to a local event, attempt to map the XS2
     * venue to the same Seatsbroker stadium. Already-confirmed mappings are
     * left unchanged.
     */
    public function mapVenueForLocalEvent(
        Xs2Event $xs2Event,
        MatchInfo $localEvent,
        ?float $autoMapThreshold = null,
    ): ?Xs2StadiumMapping {
        $venue = $this->ensureVenueFromEvent($xs2Event);
        if (! $venue instanceof Xs2Venue) {
            return null;
        }

        return DB::transaction(function () use ($venue, $localEvent, $autoMapThreshold): ?Xs2StadiumMapping {
            $mapping = Xs2StadiumMapping::query()
                ->where('xs2_venue_id', $venue->id)
                ->lockForUpdate()
                ->first();

            if ($this->isConfirmed($mapping)) {
                return $mapping;
            }

            $localStadiumId = $this->localStadiumId($localEvent);
            if ($localStadiumId !== null) {
                $mapping = $mapping ?? $this->resolve($venue, $autoMapThreshold);

                return $this->confirm($mapping, $localStadiumId);
            }

            $mapping = $this->resolve($venue, $autoMapThreshold);
            if ($this->isConfirmed($mapping)) {
                return $mapping;
            }

            $threshold = $this->autoMapThreshold($autoMapThreshold);
            if ($mapping->stadium_id !== null
                && $mapping->status === 'pending_stadium_mapping'
                && (float) ($mapping->confidence_score ?? 0) >= $threshold) {
                return $this->confirm($mapping, (int) $mapping->stadium_id);
            }

            return $mapping;
        });
    }

    private function localStadiumId(MatchInfo $localEvent): ?int
    {
        $venue = $localEvent->getAttribute('venue');
        if (! is_numeric($venue)) {
            return null;
        }

        $stadiumId = (int) $venue;

        return $stadiumId > 0 ? $stadiumId : null;
    }

    private function ensureVenueFromEvent(Xs2Event $event): ?Xs2Venue
    {
        $externalVenueId = trim((string) ($event->venue_id ?? ''));
        if ($externalVenueId === '') {
            return null;
        }

        $venue = Xs2Venue::query()->firstOrNew(['external_venue_id' => $externalVenueId]);
        $venue->fill([
            'venue_name' => $event->venue_name,
            'city_name' => $event->city,
            'country_code' => $this->normalizeCountryCode((string) ($event->iso_country ?? '')),
            'raw_payload' => is_array($event->raw_payload) ? $event->raw_payload : [],
            'last_synced_at' => now(),
        ]);
        $venue->save();

        return $venue;
    }

    private function normalizeCountryCode(string $value): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $value));
        if ($code === '') {
            return null;
        }

        if (strlen($code) === 2) {
            return $code;
        }

        return self::ISO_ALPHA3_TO_ALPHA2[$code] ?? $code;
    }

    public function resolve(Xs2Venue $venue, ?float $autoMapThreshold = null): Xs2StadiumMapping
    {
        return DB::transaction(function () use ($venue, $autoMapThreshold): Xs2StadiumMapping {
            $mapping = Xs2StadiumMapping::query()
                ->where('xs2_venue_id', $venue->id)
                ->lockForUpdate()
                ->firstOrNew(['xs2_venue_id' => $venue->id]);

            if ($mapping->exists && $mapping->manually_confirmed) {
                return $mapping;
            }

            $country = $this->countries->resolve($venue);
            if (! $country->resolved || ! $country->countryId || ! $country->country) {
                return $this->save($mapping, [
                    'stadium_id' => null,
                    'resolved_country_id' => null,
                    'resolved_city_id' => null,
                    'status' => 'pending_country_resolution',
                    'confidence_score' => null,
                    'mapping_method' => null,
                    'matched_fields' => $country->matchedFields,
                    'candidate_scores' => null,
                    'mapped_at' => null,
                    'mapping_error' => $country->reason,
                ]);
            }

            $city = $this->cities->resolve($venue, $country->country);
            if (! $city->resolved || ! $city->cityId) {
                return $this->save($mapping, [
                    'stadium_id' => null,
                    'resolved_country_id' => $country->countryId,
                    'resolved_city_id' => null,
                    'status' => 'pending_city_resolution',
                    'confidence_score' => $city->confidenceScore,
                    'mapping_method' => $city->mappingMethod,
                    'matched_fields' => [...$country->matchedFields, ...$city->matchedFields],
                    'candidate_scores' => $city->candidateScores,
                    'mapped_at' => null,
                    'mapping_error' => $city->reason,
                ]);
            }

            $stadiumRows = collect($this->schema->stadiumsForCity($city->cityId));
            foreach ($this->schema->stadiumIdsMatchingName((string) $venue->venue_name) as $stadiumId) {
                if ($stadiumRows->contains(fn (object $row): bool => $this->schema->stadiumId($row) === $stadiumId)) {
                    continue;
                }
                $row = $this->schema->stadiumById($stadiumId);
                if ($row) {
                    $stadiumRows->push($row);
                }
            }

            $candidates = $stadiumRows
                ->map(fn (object $stadium) => $this->scorer->score($venue, $stadium, $country->countryId))
                ->sortByDesc(fn ($result) => $result->score)
                ->take(5)
                ->values();
            $best = $candidates->first();
            $candidateScores = $candidates->map(fn ($result) => $result->toArray())->all();

            if (! $best) {
                return $this->save($mapping, [
                    'stadium_id' => null,
                    'resolved_country_id' => $country->countryId,
                    'resolved_city_id' => $city->cityId,
                    'status' => 'unmatched',
                    'confidence_score' => null,
                    'mapping_method' => null,
                    'matched_fields' => [...$country->matchedFields, ...$city->matchedFields],
                    'candidate_scores' => [],
                    'mapped_at' => null,
                    'mapping_error' => 'No local stadium exists in the resolved city.',
                ]);
            }

            $attributes = [
                'stadium_id' => $best->stadiumId,
                'resolved_country_id' => $country->countryId,
                'resolved_city_id' => $city->cityId,
                'confidence_score' => $best->score,
                'mapping_method' => $best->method,
                'matched_fields' => array_values(array_unique([...$country->matchedFields, ...$city->matchedFields, ...$best->matchedFields])),
                'candidate_scores' => $candidateScores,
                'mapped_at' => null,
                'mapping_error' => null,
            ];

            if ($best->score >= $this->autoMapThreshold($autoMapThreshold)) {
                return $this->save($mapping, [...$attributes, 'status' => 'mapped', 'mapped_at' => now()]);
            }

            if ($best->score >= (float) config('xs2.mapping.stadium_pending_threshold', 80)) {
                return $this->save($mapping, [...$attributes, 'status' => 'pending_stadium_mapping', 'mapping_error' => 'Administrator confirmation is required.']);
            }

            return $this->save($mapping, [...$attributes, 'stadium_id' => null, 'status' => 'unmatched', 'mapping_error' => 'No local stadium met the pending confidence threshold.']);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function save(Xs2StadiumMapping $mapping, array $attributes): Xs2StadiumMapping
    {
        $mapping->fill($attributes);
        $mapping->save();

        return $mapping;
    }

    private function autoMapThreshold(?float $override): float
    {
        return $override ?? (float) config('xs2.mapping.stadium_auto_map_threshold', 95);
    }
}
