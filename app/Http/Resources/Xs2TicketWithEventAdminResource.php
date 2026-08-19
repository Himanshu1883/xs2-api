<?php

namespace App\Http\Resources;

use App\Models\EventMapping;
use App\Models\MatchInfo;
use App\Support\EnglishDisplayText;
use App\Support\Xs2VenueMappingSerializer;
use Illuminate\Http\Request;

/**
 * Extends the per-event ticket shape with the parent event's identity, for
 * the cross-event listings endpoint. The per-event endpoint omits this
 * because the caller already knows which event it asked for.
 */
class Xs2TicketWithEventAdminResource extends Xs2TicketAdminResource
{
    public function toArray(Request $request): array
    {
        $mapping = $this->xs2Event?->mapping;
        $isMapped = $this->isMappedToSeatsBroker($mapping);
        $localEvent = $isMapped ? $mapping?->event : null;

        return [
            ...parent::toArray($request),
            'event' => [
                'mapping_id' => $mapping?->id,
                'name' => $this->xs2Event?->event_name,
                'venue_id' => $this->xs2Event?->venue_id,
                'venue_name' => $this->xs2Event?->venue_name,
                'city' => $this->xs2Event?->city,
                // XS2 provides a local wall-clock value without a time zone;
                // do not serialize it with a UTC offset (see EventMappingResource).
                'starts_at' => $this->localDateTime($this->xs2Event?->date_start_local),
                'is_mapped' => $isMapped,
                'mapping_status' => $mapping?->status,
                'venue_mapping' => Xs2VenueMappingSerializer::forEvent($this->xs2Event),
                'local_event' => $localEvent ? [
                    'id' => $localEvent->m_id,
                    'name' => $this->localEventDisplayName($localEvent),
                ] : null,
            ],
        ];
    }

    private function isMappedToSeatsBroker(?EventMapping $mapping): bool
    {
        if ($mapping === null) {
            return false;
        }

        return in_array($mapping->status, ['mapped', 'created'], true);
    }

    private function localEventDisplayName(MatchInfo $event): ?string
    {
        return EnglishDisplayText::resolve(
            EnglishDisplayText::preferEnglish($event->getAttribute('legacy_match_name')),
            EnglishDisplayText::preferEnglish($event->getAttribute('match_name')),
            EnglishDisplayText::teamEventTitle(
                EnglishDisplayText::preferEnglish($event->getAttribute('team_1')),
                EnglishDisplayText::preferEnglish($event->getAttribute('team_2')),
            ),
        );
    }

    private function localDateTime(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d\\TH:i:s')
            : null;
    }
}
