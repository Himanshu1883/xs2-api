<?php

namespace App\Jobs;

use App\Services\Xs2\Xs2OrderSyncService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches XS2 booking orders in the background so the admin HTTP request returns quickly.
 */
class SyncXs2OrdersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue(config('xs2.admin_cron_queue', 'admin-cron'));
    }

    public function uniqueId(): string
    {
        return 'xs2-orders-sync';
    }

    public function handle(Xs2OrderSyncService $sync): void
    {
        $summary = $sync->sync();

        Log::info('XS2 order sync job completed.', $summary);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('XS2 order sync job failed.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
