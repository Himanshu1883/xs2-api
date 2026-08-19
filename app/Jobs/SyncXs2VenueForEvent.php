<?php

namespace App\Jobs;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Models\Xs2Event;
use App\Services\Xs2\Xs2VenueSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncXs2VenueForEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue(config('xs2.queue', config('services.xs2.queue')));
    }

    public function uniqueId(): string
    {
        return "xs2-venue:{$this->eventId}";
    }

    public function handle(Xs2VenueSyncService $venues): void
    {
        $event = Xs2Event::query()->findOrFail($this->eventId);

        try {
            $result = $venues->syncByExternalVenueId((string) $event->venue_id);
            Log::channel(config('xs2.log_channel', 'stack'))->info('XS2 venue synchronized.', [
                'provider' => 'xs2event',
                'external_event_id' => $event->external_event_id,
                'external_venue_id' => $result['venue']->external_venue_id,
            ]);
        } catch (Xs2RateLimitException $exception) {
            $this->release(max(1, $exception->retryAfter));
        }
    }
}
