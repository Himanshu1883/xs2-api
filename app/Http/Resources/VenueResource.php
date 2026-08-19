<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     id:int,
         *     name:?string,
         *     city_id:?int,
         *     city_name:?string,
         *     source:string,
         *     event_count?:int,
         *     category_count?:int,
         *     section_count?:int,
         *     xs2_mapped?:bool,
         *     xs2_venue_id?:?string,
         *     xs2_venue_name?:?string
         * } $row
         */
        $row = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'city' => $row['city_name'] ?? null,
            'city_id' => $row['city_id'] ?? null,
            'source' => $row['source'],
            'event_count' => (int) ($row['event_count'] ?? 0),
            'category_count' => (int) ($row['category_count'] ?? 0),
            'section_count' => (int) ($row['section_count'] ?? 0),
            'xs2_mapped' => (bool) ($row['xs2_mapped'] ?? false),
            'xs2_venue_id' => $row['xs2_venue_id'] ?? null,
            'xs2_venue_name' => $row['xs2_venue_name'] ?? null,
        ];
    }
}
