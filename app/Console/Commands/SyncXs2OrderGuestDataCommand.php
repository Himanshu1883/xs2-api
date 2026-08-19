<?php

namespace App\Console\Commands;

use App\Services\Xs2\SbOrderXs2GuestDataSyncService;
use Illuminate\Console\Command;

class SyncXs2OrderGuestDataCommand extends Command
{
    protected $signature = 'xs2:sync-order-guest-data {--sb-order-id=} {--limit=}';

    protected $description = 'Push SB order attendee_details to linked XS2 booking order guest data.';

    public function handle(SbOrderXs2GuestDataSyncService $service): int
    {
        if (! (bool) config('xs2.sb_order_guest_data_sync.enabled', true)) {
            $this->warn('SB → XS2 order guest data sync is disabled (XS2_SB_ORDER_GUEST_DATA_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        $sbOrderId = $this->option('sb-order-id');
        if (filled($sbOrderId)) {
            $order = \App\Models\SbOrder::query()->with(['attendees', 'xs2Order'])->find((int) $sbOrderId);
            if ($order === null) {
                $this->error('SB order not found.');

                return self::FAILURE;
            }

            $result = $service->syncForSbOrder($order);
            if ($result['synced'] ?? false) {
                $this->info('Guest data synced to XS2 for SB order '.$order->id.'.');

                return self::SUCCESS;
            }

            if ($result['skipped'] ?? false) {
                $this->line('Skipped: '.($result['reason'] ?? 'unknown reason'));

                return self::SUCCESS;
            }

            $this->error($result['error'] ?? 'Guest data sync failed.');

            return self::FAILURE;
        }

        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $summary = $service->syncPending($limit);

        $this->info(sprintf(
            'Guest data sync finished: %d synced, %d skipped, %d failed.',
            $summary['synced'],
            $summary['skipped'],
            $summary['failed'],
        ));

        foreach ($summary['errors'] as $error) {
            $this->warn(sprintf(
                'SB order %s / XS2 order %s: %s',
                $error['sb_order_id'] ?? '?',
                $error['xs2_order_id'] ?? '?',
                $error['message'],
            ));
        }

        return ($summary['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
