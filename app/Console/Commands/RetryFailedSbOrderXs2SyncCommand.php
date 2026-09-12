<?php

namespace App\Console\Commands;

use App\Jobs\CreateXs2SandboxOrderFromSbOrder;
use App\Models\SbOrder;
use App\Models\SbOrderXs2SyncLog;
use App\Services\Xs2\SbOrderXs2SandboxOrderService;
use App\Support\Xs2BookingOrderIdentity;
use Illuminate\Console\Command;

class RetryFailedSbOrderXs2SyncCommand extends Command
{
    protected $signature = 'xs2:retry-failed-sb-order-sync
                            {--sb-order= : Limit to one SB order id}
                            {--dry-run : Show eligible orders without queueing}
                            {--limit=50 : Maximum orders to queue per run}';

    protected $description = 'Retry XS2 reservation+booking for SB orders whose sync log is failed and no real XS2 order exists.';

    public function handle(SbOrderXs2SandboxOrderService $service): int
    {
        if (! (bool) config('xs2.sb_order_xs2_sync.retry_enabled', true)) {
            $this->warn('SB order XS2 sync retry is disabled (XS2_SB_ORDER_XS2_SYNC_RETRY_ENABLED=false).');

            return self::SUCCESS;
        }

        $sbOrderId = filled($this->option('sb-order')) ? (int) $this->option('sb-order') : null;
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = SbOrderXs2SyncLog::query()
            ->where('status', SbOrderXs2SyncLog::STATUS_FAILED)
            ->when($sbOrderId !== null, fn ($query) => $query->where('sb_order_id', $sbOrderId))
            ->orderBy('updated_at');

        $queued = 0;
        $skipped = 0;

        foreach ($query->cursor() as $log) {
            if ($queued >= $limit) {
                break;
            }

            $order = SbOrder::query()->with('xs2Order')->find($log->sb_order_id);
            if ($order === null) {
                $skipped++;

                continue;
            }

            $existing = $order->xs2Order;
            if ($existing !== null && $service->orderIsComplete($existing)) {
                $skipped++;

                continue;
            }

            if (
                $existing !== null
                && Xs2BookingOrderIdentity::isPendingExternalOrderId($existing->external_order_id)
            ) {
                if (! $dryRun) {
                    $existing->delete();
                }
            }

            if ($service->resolveQueueSkipReason($order) !== null) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    'Would queue SB order #%d (%s): %s',
                    $order->id,
                    $order->booking_no,
                    mb_substr((string) ($log->error ?? 'failed'), 0, 120),
                ));
                $queued++;

                continue;
            }

            CreateXs2SandboxOrderFromSbOrder::dispatch($order->id);
            $queued++;
        }

        $this->info(sprintf(
            '%s %d SB order(s)%s.',
            $dryRun ? 'Would queue' : 'Queued',
            $queued,
            $skipped > 0 ? sprintf(' (%d skipped)', $skipped) : '',
        ));

        return self::SUCCESS;
    }
}
