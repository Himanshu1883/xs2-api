<?php

namespace App\Jobs;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Models\Xs2Event;
use App\Models\Xs2Venue;
use App\Services\Xs2\Xs2CategorySyncService;
use App\Services\Xs2\Xs2VenueSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncXs2CategoriesForEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue(config('xs2.mapping_queue', config('services.xs2.mapping_queue', 'xs2-mapping')));
    }

    public function uniqueId(): string
    {
        return "xs2-categories:{$this->eventId}";
    }

    public function handle(Xs2VenueSyncService $venues, Xs2CategorySyncService $categories): void
    {
        $event = Xs2Event::query()->findOrFail($this->eventId);
        $venueId = (string) ($event->venue_id ?? '');
        $stadiumMapping = $venueId === ''
            ? null
            : Xs2Venue::query()
                ->with('stadiumMapping')
                ->where('external_venue_id', $venueId)
                ->first()?->stadiumMapping;

        // Venue and category endpoints are triggered independently, so jobs
        // can be consumed out of order. Establish the prerequisite here when
        // it is absent instead of storing categories that cannot be mapped
        // until an unrelated future sync happens.
        if (! $stadiumMapping && $venueId !== '') {
            try {
                $stadiumMapping = $venues->syncForEvent($event)['mapping'];
            } catch (Xs2RateLimitException $exception) {
                $this->release(max(1, $exception->retryAfter));

                return;
            }
        }

        try {
            $summary = $categories->sync($event, $stadiumMapping);
            Log::channel(config('xs2.log_channel', 'stack'))->info('XS2 categories synchronized.', [
                'provider' => 'xs2event',
                'external_event_id' => $event->external_event_id,
                'summary' => $summary,
            ]);
        } catch (Xs2RateLimitException $exception) {
            $this->release(max(1, $exception->retryAfter));
        }
    }
}
