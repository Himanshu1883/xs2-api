<?php

namespace App\Http\Resources;

use App\Models\MatchInfo;
use App\Support\EnglishDisplayText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchInfo */
class AdminEventSearchResource extends JsonResource
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

        return [
            'id' => $this->m_id,
            'name' => EnglishDisplayText::resolve(
                $this->getAttribute('legacy_match_name'),
                $this->match_name,
                EnglishDisplayText::teamEventTitle(
                    $this->legacy_home_team_name,
                    $this->legacy_away_team_name,
                ),
            ),
            // Local event rows have no time-zone column. Keep the original
            // wall-clock value instead of implying that it is UTC.
            'starts_at' => $this->localDateTime($this->match_date),
            'sport_type' => $xs2Event?->sport_type,
            'venue_name' => EnglishDisplayText::resolve($this->legacy_venue_name, $xs2Event?->venue_name),
            'tournament_name' => EnglishDisplayText::resolve(
                $this->legacy_tournament_name,
                $this->tournament,
                $xs2Event?->tournament_name,
            ),
            'home_team_name' => EnglishDisplayText::resolve($this->legacy_home_team_name, $this->team_1),
            'away_team_name' => EnglishDisplayText::resolve($this->legacy_away_team_name, $this->team_2),
        ];
    }

    /**
     * Legacy local-event fields can contain a reference ID. Resources must not
     * serialize that ID as though it were a display name.
     */
    private function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);

        return $name === '' || ctype_digit($name) ? null : $name;
    }

    private function localDateTime(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d\\TH:i:s')
            : null;
    }
}
