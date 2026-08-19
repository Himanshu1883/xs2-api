<?php

namespace App\Http\Resources;

use App\Models\Xs2SyncState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class Xs2SyncStateResource extends JsonResource
{
    /** @var list<string> */
    private const PUBLIC_METADATA_FIELDS = [
        'events_received',
        'events_created',
        'events_updated',
        'events_mapped',
        'events_pending',
        'local_events_created',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Xs2SyncState|null $state */
        $state = $this->resource['state'];
        $sport = $this->resource['sport'];

        return [
            'resource' => "events:{$sport}",
            'status' => $state?->status ?? 'never_run',
            'last_attempted_at' => $state?->last_attempted_at?->toIso8601String(),
            'last_successful_at' => $state?->last_successful_at?->toIso8601String(),
            'last_error' => filled($state?->last_error)
                ? 'The most recent synchronization failed. Review the application logs.'
                : null,
            'metadata' => $this->publicMetadata($state?->metadata),
        ];
    }

    /** @param array<string, mixed>|null $metadata */
    private function publicMetadata(?array $metadata): array
    {
        return collect(self::PUBLIC_METADATA_FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => max(0, (int) ($metadata[$field] ?? 0))])
            ->all();
    }
}
