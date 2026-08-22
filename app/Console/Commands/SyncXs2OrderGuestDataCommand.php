<?php

namespace App\Console\Commands;

use App\Models\SbOrder;
use App\Services\SellerApi\SellerBookingSyncService;
use Illuminate\Console\Command;

class SyncXs2OrderGuestDataCommand extends Command
{
    protected $signature = 'xs2:sync-order-guest-data {--sb-order-id=} {--limit=}';

    protected $description = 'Fetch SB order attendee_details from Seats Broker once per order (skips already fetched).';

    public function handle(SellerBookingSyncService $sync): int
    {
        if (! (bool) config('xs2.sb_order_guest_data_sync.enabled', true)) {
            $this->warn('SB attendee fetch is disabled (XS2_SB_ORDER_GUEST_DATA_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        $sbOrderId = $this->option('sb-order-id');
        if (filled($sbOrderId)) {
            $order = SbOrder::query()->find((int) $sbOrderId);
            if ($order === null) {
                $this->error('SB order not found.');

                return self::FAILURE;
            }

            if ($order->attendee_fetched_at !== null) {
                $this->line('Skipped: attendee details already fetched for SB order '.$order->id.'.');

                return self::SUCCESS;
            }

            try {
                $refreshed = $sync->fetchAttendees($order, false);
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $refreshed->loadMissing('attendees');
            if ($refreshed->attendees->isEmpty()) {
                $this->line('No attendee details returned yet for SB order '.$order->id.'; cron will retry.');

                return self::SUCCESS;
            }

            $this->info('Fetched '.$refreshed->attendees->count().' attendee(s) for SB order '.$order->id.'.');

            return self::SUCCESS;
        }

        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $summary = $sync->fetchPendingAttendees($limit);

        $this->info(sprintf(
            'Attendee fetch finished: %d fetched, %d skipped, %d failed.',
            $summary['fetched'],
            $summary['skipped'],
            $summary['failed'],
        ));

        foreach ($summary['errors'] as $error) {
            $this->warn(sprintf(
                'SB order %s: %s',
                $error['sb_order_id'] ?? '?',
                $error['message'],
            ));
        }

        return ($summary['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
