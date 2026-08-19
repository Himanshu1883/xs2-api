<?php

namespace App\Http\Resources;

use App\Models\MatchInfo;
use App\Support\EnglishDisplayText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchInfo */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mapping = $this->resource->relationLoaded('publicXs2Mappings')
            ? $this->publicXs2Mappings->first()
            : null;
        $xs2Event = $mapping?->xs2Event;
        $ticketCount = $xs2Event?->number_of_tickets;
        $xs2Mapped = $mapping !== null && $xs2Event !== null;

        return [
            'id' => $this->m_id,
            'slug' => null,
            'name' => EnglishDisplayText::resolve(
                $this->getAttribute('legacy_match_name'),
                $this->match_name,
                EnglishDisplayText::teamEventTitle(
                    $this->legacy_home_team_name,
                    $this->legacy_away_team_name,
                ),
            ) ?? 'Unnamed event',
            'sport_type' => $xs2Event?->sport_type,
            // Neither the legacy event table nor XS2 supplies a time zone for
            // these values. Emit the wall-clock time without a false UTC offset.
            'starts_at' => $this->localDateTime($this->match_date),
            'ends_at' => $this->localDateTime($xs2Event?->date_stop_local),
            'date_confirmed' => $xs2Event?->date_confirmed,
            'status' => $xs2Event?->event_status ?? 'active',
            'venue' => [
                'id' => $this->localId($this->getAttribute('venue')),
                'name' => EnglishDisplayText::resolve($xs2Event?->venue_name, $this->legacy_venue_name),
                'city' => EnglishDisplayText::resolve($xs2Event?->city, $this->legacy_city_name),
                'country_code' => $xs2Event?->iso_country,
            ],
            'tournament' => [
                'id' => null,
                'name' => EnglishDisplayText::resolve($xs2Event?->tournament_name, $this->legacy_tournament_name),
            ],
            'home_team' => [
                'id' => null,
                'name' => EnglishDisplayText::resolve(
                    $this->legacy_home_team_name,
                    $xs2Event?->hometeam_name,
                    $this->team_1,
                ),
            ],
            'away_team' => [
                'id' => null,
                'name' => EnglishDisplayText::resolve(
                    $this->legacy_away_team_name,
                    $xs2Event?->visitingteam_name,
                    $this->team_2,
                ),
            ],
            'description' => $xs2Event?->event_description,
            'xs2_mapped' => $xs2Mapped,
            'xs2_mapping_id' => $xs2Mapped ? $mapping->id : null,
            'xs2_event_id' => $xs2Mapped ? $xs2Event->external_event_id : null,
            'xs2_event_name' => $xs2Mapped ? $xs2Event->event_name : null,
            'inventory' => [
                'has_xs2_inventory' => $ticketCount !== null && $ticketCount > 0,
                'ticket_count' => $ticketCount,
                // XS2 supplies whole-EUR integer values, not supplier net rates.
                'minimum_price' => $xs2Event?->min_ticket_price_eur,
                'maximum_price' => $xs2Event?->max_ticket_price_eur,
                // Public catalogue events are priced in EUR; unmapped legacy rows
                // have no local currency column, so always expose the default.
                'currency' => 'EUR',
            ],
        ];
    }

    /**
     * Legacy match_info fields may contain a foreign-key ID. Never expose one
     * as a public display name when a joined reference record is unavailable.
     */
    private function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);

        return $name === '' || ctype_digit($name) ? null : $name;
    }

    private function localId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * A local schema datetime and XS2's event datetime are both wall-clock
     * values. An ISO offset (including `+00:00`) would claim a timezone that
     * neither source provides.
     */
    private function localDateTime(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d\\TH:i:s')
            : null;
    }
}
