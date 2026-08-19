<?php

namespace App\Services\Xs2;

use Illuminate\Support\Carbon;

class Xs2EventNormalizer
{
    /**
     * XS2 documents event dates as local event date/times and does not expose a
     * timezone field. They are intentionally stored without UTC conversion.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function normalize(array $event): array
    {
        return [
            'external_event_id' => $event['event_id'],
            'event_name' => $event['event_name'],
            'date_start_local' => $this->localDate($event['date_start'] ?? null),
            'date_stop_local' => $this->localDate($event['date_stop'] ?? null),
            'event_status' => $event['event_status'] ?? null,
            'tournament_id' => $event['tournament_id'] ?? null,
            'tournament_name' => $event['tournament_name'] ?? null,
            'tournament_type' => $event['tournament_type'] ?? null,
            'season' => $event['season'] ?? null,
            'venue_id' => $event['venue_id'] ?? null,
            'venue_name' => $event['venue_name'] ?? null,
            'location_id' => $event['location_id'] ?? null,
            'city' => $event['city'] ?? null,
            'iso_country' => $event['iso_country'] ?? null,
            'latitude' => $this->numeric($event['latitude'] ?? null),
            'longitude' => $this->numeric($event['longitude'] ?? null),
            'sport_type' => $event['sport_type'] ?? null,
            'date_confirmed' => (bool) ($event['date_confirmed'] ?? false),
            'date_start_main_event' => $this->localDate($event['date_start_main_event'] ?? null),
            'date_stop_main_event' => $this->localDate($event['date_stop_main_event'] ?? null),
            'hometeam_id' => $event['hometeam_id'] ?? null,
            'hometeam_name' => $event['hometeam_name'] ?? null,
            'visitingteam_id' => $event['visiting_id'] ?? null,
            'visitingteam_name' => $event['visiting_name'] ?? null,
            'event_description' => $event['event_description'] ?? null,
            // XS2's EventResponse defines these as integer EUR amounts, not cents.
            'min_ticket_price_eur' => $event['min_ticket_price_eur'] ?? null,
            'max_ticket_price_eur' => $event['max_ticket_price_eur'] ?? null,
            'number_of_tickets' => $event['number_of_tickets'] ?? null,
            'slug' => $event['slug'] ?? null,
            'is_popular' => (bool) ($event['is_popular'] ?? false),
            'date_visibility' => $event['date_visibility'] ?? null,
            'external_created_at' => $this->date($event['created'] ?? null),
            'external_updated_at' => $this->date($event['updated'] ?? null),
            'raw_payload' => $event,
            'last_synced_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ];
    }

    private function localDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return str_replace('T', ' ', substr($value, 0, 19));
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
