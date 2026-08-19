<?php

namespace App\Http\Resources;

use App\Models\EventMapping;
use App\Models\MatchInfo;
use App\Support\EnglishDisplayText;
use App\Support\Xs2VenueMappingSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventMapping */
class EventMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $localEvent = $this->resource->relationLoaded('event') ? $this->event : null;
        $reviewer = $this->resource->relationLoaded('reviewer') ? $this->reviewer : null;
        $suggestedEvents = $this->suggestedEvents();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'mapping_method' => $this->mapping_method,
            'match_score' => $this->match_score !== null ? (float) $this->match_score : null,
            'xs2_event' => [
                'external_event_id' => $this->xs2Event?->external_event_id,
                'name' => $this->xs2Event?->event_name,
                'sport_type' => $this->xs2Event?->sport_type,
                // XS2 provides a local wall-clock value without a time zone.
                'starts_at' => $this->localDateTime($this->xs2Event?->date_start_local),
                'ends_at' => $this->localDateTime($this->xs2Event?->date_stop_local),
                'status' => $this->xs2Event?->event_status,
                'date_confirmed' => $this->xs2Event?->date_confirmed,
                'venue_id' => $this->xs2Event?->venue_id,
                'venue_name' => $this->xs2Event?->venue_name,
                'city' => $this->xs2Event?->city,
                'country_code' => $this->xs2Event?->iso_country,
                'tournament_name' => $this->xs2Event?->tournament_name,
                'home_team_name' => $this->xs2Event?->hometeam_name,
                'away_team_name' => $this->xs2Event?->visitingteam_name,
                'description' => $this->xs2Event?->event_description,
                'listings_count' => $this->xs2Event?->tickets_count,
                'ticket_count' => $this->xs2Event?->tickets_count,
                'ticket_quantity' => $this->xs2Event !== null
                    ? (int) ($this->xs2Event->tickets_sum_stock ?? 0)
                    : null,
                'sync' => $this->xs2EventSync(),
            ],
            'venue_mapping' => $this->venueMapping(),
            'local_event' => $localEvent ? [
                'id' => $localEvent->m_id,
                'name' => $this->localEventDisplayName($localEvent),
                'starts_at' => $this->localDateTime($localEvent->match_date),
                'venue_id' => $this->localId($localEvent->getAttribute('venue')),
                'venue_name' => $this->localName($localEvent, 'legacy_venue_name', 'venue'),
                'city' => $this->localName($localEvent, 'legacy_city_name', 'city'),
                'category_id' => $this->localId($localEvent->getAttribute('category')),
                'tournament_id' => $this->localId($localEvent->getAttribute('tournament')),
                'tournament_name' => $this->localName($localEvent, 'legacy_tournament_name', 'tournament'),
                'home_team_name' => $this->localName($localEvent, 'legacy_home_team_name', 'team_1'),
                'away_team_name' => $this->localName($localEvent, 'legacy_away_team_name', 'team_2'),
            ] : null,
            'suggested_events' => $suggestedEvents,
            'match_details' => $this->scoreDetails(),
            'reviewed_by' => $reviewer?->display_name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function venueMapping(): ?array
    {
        return Xs2VenueMappingSerializer::forEvent($this->xs2Event);
    }

    /**
     * @return array<string, string|null>
     */
    private function xs2EventSync(): array
    {
        $state = $this->xs2Event?->inventorySyncState;
        $lastFullSyncAt = $state?->tickets_last_full_sync_at;
        $lastIncrementalSyncAt = $state?->tickets_last_incremental_sync_at;
        $lastInventorySyncAt = collect([$lastFullSyncAt, $lastIncrementalSyncAt])
            ->filter()
            ->sortByDesc(fn (\DateTimeInterface $timestamp) => $timestamp->getTimestamp())
            ->first();

        return [
            'last_synced_at' => $this->xs2Event?->last_synced_at?->toIso8601String(),
            'last_seen_at' => $this->xs2Event?->last_seen_at?->toIso8601String(),
            'missing_since' => $this->xs2Event?->missing_since?->toIso8601String(),
            'last_inventory_sync_at' => $lastInventorySyncAt?->toIso8601String(),
            'last_full_sync_at' => $lastFullSyncAt?->toIso8601String(),
            'last_incremental_sync_at' => $lastIncrementalSyncAt?->toIso8601String(),
            'inventory_sync_status' => $state?->tickets_sync_status,
        ];
    }

    /**
     * Translate legacy storage keys to the public local-event vocabulary.
     *
     * @return array<string, mixed>|null
     */
    private function publicMatchDetails(): ?array
    {
        if (! is_array($this->match_details)) {
            return null;
        }

        $details = $this->match_details;

        if (array_key_exists('candidate_m_id', $details)) {
            $details['candidate_event_id'] = $details['candidate_m_id'];
            unset($details['candidate_m_id']);
        }

        if (is_array($details['best_match'] ?? null)) {
            $bestMatch = $details['best_match'];
            if (array_key_exists('candidate_m_id', $bestMatch)) {
                $bestMatch['candidate_event_id'] = $bestMatch['candidate_m_id'];
                unset($bestMatch['candidate_m_id']);
            }
            $details['best_match'] = $bestMatch;
        }

        if (is_array($details['candidates'] ?? null)) {
            $details['candidates'] = array_map(function (mixed $candidate): mixed {
                if (! is_array($candidate)) {
                    return $candidate;
                }

                if (array_key_exists('m_id', $candidate)) {
                    $candidate['event_id'] = $candidate['m_id'];
                    unset($candidate['m_id']);
                }

                return $candidate;
            }, $details['candidates']);
        }

        return $details;
    }

    /**
     * Candidate events are loaded in one query by the controller. Their IDs
     * remain local event IDs and are suitable for the admin mapping selector.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suggestedEvents(): array
    {
        if (! $this->resource->relationLoaded('suggestedEvents')) {
            return [];
        }

        $events = $this->suggestedEvents->keyBy('m_id');

        return collect($this->candidateRows())
            ->map(function (array $candidate) use ($events): ?array {
                $event = $events->get($candidate['event_id']);
                if (! $event) {
                    return null;
                }

                return [
                    'event_id' => $event->m_id,
                    'name' => $this->localEventDisplayName($event),
                    'starts_at' => $this->localDateTime($event->match_date),
                    'venue_name' => $this->localName($event, 'legacy_venue_name', 'venue'),
                    'city' => $this->localName($event, 'legacy_city_name', 'city'),
                    'tournament_name' => $this->localName($event, 'legacy_tournament_name', 'tournament'),
                    'home_team_name' => $this->localName($event, 'legacy_home_team_name', 'team_1'),
                    'away_team_name' => $this->localName($event, 'legacy_away_team_name', 'team_2'),
                    'score' => $candidate['score'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{event_id: int, score: float|int|null}>
     */
    private function candidateRows(): array
    {
        $details = $this->publicMatchDetails() ?? [];
        $candidates = [];

        foreach (($details['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $eventId = $candidate['event_id'] ?? $candidate['m_id'] ?? null;
            if (! is_numeric($eventId)) {
                continue;
            }

            $candidates[(int) $eventId] = [
                'event_id' => (int) $eventId,
                'score' => isset($candidate['score']) ? (float) $candidate['score'] : null,
            ];
        }

        $bestMatch = $details['best_match'] ?? null;
        if (is_array($bestMatch) && isset($bestMatch['candidate_event_id'])) {
            $eventId = (int) $bestMatch['candidate_event_id'];
            $candidates[$eventId] ??= [
                'event_id' => $eventId,
                'score' => isset($bestMatch['final_score']) ? (float) $bestMatch['final_score'] : null,
            ];
        }

        if (isset($details['candidate_event_id'])) {
            $eventId = (int) $details['candidate_event_id'];
            $candidates[$eventId] ??= [
                'event_id' => $eventId,
                'score' => isset($details['final_score']) ? (float) $details['final_score'] : null,
            ];
        }

        return array_values($candidates);
    }

    /**
     * Keep the score explanation while exposing the rich candidate records in
     * `suggested_events` rather than a second partial candidate format.
     *
     * @return array<string, mixed>|null
     */
    private function scoreDetails(): ?array
    {
        $details = $this->publicMatchDetails();
        if ($details === null) {
            return null;
        }

        unset($details['candidates'], $details['best_match'], $details['candidate_event_id']);

        return $details;
    }

    /**
     * Prefer a joined legacy display value. If an older row already stores a
     * name directly, preserve it; numeric reference IDs are never names.
     */
    private function localEventDisplayName(MatchInfo $event): ?string
    {
        return EnglishDisplayText::resolve(
            EnglishDisplayText::preferEnglish($event->getAttribute('legacy_match_name')),
            EnglishDisplayText::preferEnglish($event->getAttribute('match_name')),
            EnglishDisplayText::teamEventTitle(
                $this->localName($event, 'legacy_home_team_name', 'team_1'),
                $this->localName($event, 'legacy_away_team_name', 'team_2'),
            ),
        );
    }

    private function localName(MatchInfo $event, string $alias, string $attribute): ?string
    {
        return EnglishDisplayText::preferEnglish($event->getAttribute($alias))
            ?? EnglishDisplayText::preferEnglish($event->getAttribute($attribute));
    }

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
     * These source columns are timezone-less local datetimes. Do not serialize
     * them with a UTC offset merely because the application timezone is UTC.
     */
    private function localDateTime(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d\\TH:i:s')
            : null;
    }
}
