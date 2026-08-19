<?php

namespace App\Jobs;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use App\Services\Xs2\Xs2EventSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncXs2EventsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const UNIQUE_FOR = 1800;

    // Lock contention and local rate limiting release rather than fail the
    // job, so they must not exhaust its execution attempts.
    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $timeout = 300;

    public int $uniqueFor = self::UNIQUE_FOR;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600];

    public function __construct(public readonly string $sport, public readonly bool $fullSync = false)
    {
        $this->onQueue(config('services.xs2.queue'));
    }

    public function uniqueId(): string
    {
        return 'xs2-events:'.$this->sport.':'.($this->fullSync ? 'full' : 'incremental');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('xs2-events:'.$this->sport))
                ->shared()
                ->releaseAfter(60)
                ->expireAfter(max(1, (int) config('services.xs2.event_sync_lock_minutes', 10)) * 60),
        ];
    }

    public function handle(Xs2EventSyncService $syncService, Xs2ApiDebugRecorder $recorder): void
    {
        $taskId = 'xs2-events-sync';
        $recorder->enable();

        try {
            $summary = $syncService->sync($this->sport, $this->fullSync);
            Log::info('XS2 event sync completed.', ['sport' => $this->sport, 'full_sync' => $this->fullSync, 'summary' => $summary]);
        } catch (Xs2RateLimitException $exception) {
            $this->release(max(1, $exception->retryAfter));
        } finally {
            $interactions = $recorder->flush();
            if ($interactions !== []) {
                $recorder->appendCronInteractions($interactions, 'xs2-events', $taskId);
            }
        }
    }
}
