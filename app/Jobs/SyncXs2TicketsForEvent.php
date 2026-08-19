<?php

namespace App\Jobs;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Models\EventMapping;
use App\Services\Xs2\Xs2TicketSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncXs2TicketsForEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // A local rate-limit response is an expected, temporary condition. It
    // releases the job without consuming the exception allowance below.
    public int $tries = 0;

    public int $maxExceptions = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600];

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $mappingId, public bool $full = false)
    {
        $this->onQueue(config('services.xs2.queue'));
    }

    public function uniqueId(): string
    {
        return 'xs2-tickets:'.$this->mappingId.':'.($this->full ? 'full' : 'incremental');
    }

    public function handle(Xs2TicketSyncService $service): void
    {
        $lock = Cache::lock('xs2-ticket-sync:'.$this->mappingId, config('services.xs2.event_sync_lock_minutes') * 60);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }try {
            $service->sync(EventMapping::with('xs2Event')->findOrFail($this->mappingId), $this->full);
        } catch (Xs2RateLimitException $exception) {
            $this->release(max(1, $exception->retryAfter));
        } finally {
            $lock->release();
        }
    }
}
