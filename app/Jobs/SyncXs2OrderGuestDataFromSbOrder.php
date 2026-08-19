<?php

namespace App\Jobs;

use App\Models\SbOrder;
use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncXs2OrderGuestDataFromSbOrder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $sbOrderId)
    {
        $this->onQueue(config('xs2.sb_order_guest_data_sync.queue', config('xs2.guest_queue', 'xs2-guest')));
    }

    public function uniqueId(): string
    {
        return 'sb-xs2-guest-data:'.$this->sbOrderId;
    }

    public function handle(SbOrderXs2GuestDataSyncService $service): void
    {
        $order = SbOrder::query()->with(['attendees', 'xs2Order'])->find($this->sbOrderId);
        if ($order === null) {
            return;
        }

        $result = $service->syncForSbOrder($order);

        if ($result['skipped'] ?? false) {
            Log::debug('Skipped XS2 guest data sync for SB order.', [
                'sb_order_id' => $this->sbOrderId,
                'reason' => $result['reason'] ?? null,
            ]);

            return;
        }

        if ($result['synced'] ?? false) {
            Log::info('XS2 guest data synced from SB order.', [
                'sb_order_id' => $this->sbOrderId,
                'xs2_order_id' => $result['xs2_order_id'] ?? null,
            ]);

            return;
        }

        Log::warning('XS2 guest data sync failed for SB order.', [
            'sb_order_id' => $this->sbOrderId,
            'xs2_order_id' => $result['xs2_order_id'] ?? null,
            'error' => $result['error'] ?? null,
        ]);
    }
}
