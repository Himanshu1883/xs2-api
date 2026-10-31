<?php

namespace App\Jobs;

use App\Services\SellerApi\SellerBookingSyncService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches Seller API bookings in the background so admin HTTP returns quickly.
 */
class SyncSellerBookingsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        return 'sb-bookings-sync';
    }

    public function handle(SellerBookingSyncService $sync): void
    {
        $summary = $sync->sync();

        Log::info('Seller API booking sync job completed.', $summary);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Seller API booking sync job failed.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
