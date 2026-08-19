<?php

namespace App\Http\Resources;

use App\Services\Mapping\LegacyMasterDataSchema;
use App\Support\EnglishDisplayText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class Xs2StadiumMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $schema = app(LegacyMasterDataSchema::class);
        $country = $this->resolved_country_id ? $schema->countryById((int) $this->resolved_country_id) : null;
        $city = $this->resolved_city_id ? $schema->cityById((int) $this->resolved_city_id) : null;
        $stadium = $this->stadium_id ? $schema->stadiumById((int) $this->stadium_id) : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'confidence_score' => $this->confidence_score === null ? null : (float) $this->confidence_score,
            'mapping_method' => $this->mapping_method,
            'matched_fields' => $this->matched_fields ?? [],
            'candidate_scores' => $this->candidate_scores ?? [],
            'manually_confirmed' => $this->manually_confirmed,
            'mapping_error' => $this->mapping_error,
            'mapped_at' => $this->mapped_at,
            'venue' => [
                'external_venue_id' => $this->venue?->external_venue_id,
                'name' => EnglishDisplayText::preferEnglish($this->venue?->venue_name),
                'country_code' => $this->venue?->country_code,
                'city' => EnglishDisplayText::preferEnglish($this->venue?->city_name),
                'address' => $this->venue?->address,
                'coordinates' => [
                    'latitude' => $this->venue?->latitude,
                    'longitude' => $this->venue?->longitude,
                ],
                'last_synced_at' => $this->venue?->last_synced_at,
            ],
            'resolved_country' => $country ? ['id' => $this->resolved_country_id, 'name' => EnglishDisplayText::preferEnglish($schema->countryName($country))] : null,
            'resolved_city' => $city ? ['id' => $this->resolved_city_id, 'name' => EnglishDisplayText::preferEnglish($schema->cityName($city))] : null,
            'stadium' => $stadium ? ['id' => $this->stadium_id, 'name' => EnglishDisplayText::preferEnglish($schema->stadiumName($stadium))] : null,
            'xs2_events_count' => (int) ($this->xs2_events_count ?? 0),
            'seatsbroker_events_count' => (int) ($this->seatsbroker_events_count ?? 0),
            'categories_count' => (int) ($this->category_mappings_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
