<?php

namespace App\Support;

use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Services\Mapping\LegacyMasterDataSchema;

final class Xs2VenueMappingSerializer
{
    /**
     * @return array{id: int|null, status: string, stadium: array{id: int, name: string|null}|null}|null
     */
    public static function forEvent(?Xs2Event $xs2Event): ?array
    {
        if ($xs2Event === null || $xs2Event->venue_id === null || trim((string) $xs2Event->venue_id) === '') {
            return null;
        }

        $venue = $xs2Event->relationLoaded('venue') ? $xs2Event->venue : null;
        $stadiumMapping = $venue?->relationLoaded('stadiumMapping') ? $venue->stadiumMapping : null;

        if (! $stadiumMapping instanceof Xs2StadiumMapping) {
            return [
                'id' => null,
                'status' => 'pending_stadium_mapping',
                'stadium' => null,
            ];
        }

        $schema = app(LegacyMasterDataSchema::class);
        $stadium = $stadiumMapping->stadium_id ? $schema->stadiumById((int) $stadiumMapping->stadium_id) : null;

        return [
            'id' => $stadiumMapping->id,
            'status' => $stadiumMapping->status,
            'stadium' => $stadium
                ? ['id' => (int) $stadiumMapping->stadium_id, 'name' => EnglishDisplayText::preferEnglish($schema->stadiumName($stadium))]
                : null,
        ];
    }
}
