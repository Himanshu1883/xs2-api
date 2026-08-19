<?php

namespace App\Services\Xs2;

use App\Models\Xs2Category;
use App\Models\Xs2CategoryContext;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Services\Mapping\StadiumCategoryMappingService;
use Carbon\Carbon;

class Xs2CategorySyncService
{
    public function __construct(
        private readonly Xs2Client $client,
        private readonly StadiumCategoryMappingService $mappings,
    ) {}

    /** @return array<string,int> */
    public function sync(Xs2Event $event, ?Xs2StadiumMapping $stadiumMapping = null): array
    {
        $summary = array_fill_keys(['created', 'updated', 'unchanged', 'mapped', 'pending', 'unmatched', 'unsupported'], 0);
        $venueId = (string) ($event->venue_id ?? '');

        foreach ($this->client->getCategoriesForEvent($event->external_event_id) as $payload) {
            $base = $this->normalize($payload, $event->external_event_id);
            $category = Xs2Category::query()->firstOrNew([
                'external_category_id' => $base['external_category_id'],
                'external_event_id' => $event->external_event_id,
            ]);
            $created = ! $category->exists;
            $category->fill([
                ...$base,
                'xs2_event_id' => $event->id,
                'last_synced_at' => now(),
            ]);
            $changed = $created || $category->isDirty();
            $category->save();
            $summary[$created ? 'created' : ($changed ? 'updated' : 'unchanged')]++;

            $context = Xs2CategoryContext::query()->firstOrNew(['xs2_category_id' => $category->id]);
            $context->fill([
                'external_venue_id' => (string) ($payload['venue_id'] ?? $venueId) ?: null,
                'category_type' => $this->nullableString($payload['category_type'] ?? $payload['type'] ?? null),
                'slug' => $this->nullableString($payload['slug'] ?? null),
                'options' => is_array($payload['options'] ?? null) ? $payload['options'] : [],
                'on_svg' => array_key_exists('on_svg', $payload) ? (bool) $payload['on_svg'] : null,
                'external_created_at' => $this->date($payload['created'] ?? null),
                'external_updated_at' => $this->date($payload['updated'] ?? null),
            ]);
            $context->save();

            if ($stadiumMapping) {
                $mapping = $this->mappings->resolve($category, $stadiumMapping);
                match ($mapping->status) {
                    'mapped' => $summary['mapped']++,
                    'unsupported' => $summary['unsupported']++,
                    'unmatched' => $summary['unmatched']++,
                    default => $summary['pending']++,
                };
            } else {
                $summary['pending']++;
            }
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    private function normalize(array $payload, string $eventId): array
    {
        $id = $payload['category_id'] ?? $payload['id'] ?? null;
        if (! is_scalar($id) || trim((string) $id) === '') {
            throw new \InvalidArgumentException('XS2 category ID is missing.');
        }

        return [
            'external_category_id' => (string) $id,
            'external_event_id' => $eventId,
            'category_name' => $this->nullableString($payload['category_name'] ?? $payload['name'] ?? null),
            'description' => $this->nullableString($payload['description'] ?? null),
            'party_size_together' => (bool) ($payload['party_size_together'] ?? $payload['seats_together'] ?? false),
            'raw_payload' => $payload,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
