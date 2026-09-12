<?php

namespace App\Console\Commands;

use App\Models\Xs2Order;
use App\Support\Xs2BookingOrderIdentity;
use Illuminate\Console\Command;

class CleanupSbPendingXs2OrdersCommand extends Command
{
    protected $signature = 'xs2:cleanup-sb-pending-orders
                            {--dry-run : Show rows that would be deleted without deleting}';

    protected $description = 'Delete orphaned xs2_orders rows with sb-pending placeholder external_order_id values.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = Xs2BookingOrderIdentity::PENDING_EXTERNAL_ORDER_PREFIX;

        $query = Xs2Order::query()
            ->where('external_order_id', 'like', $prefix.'%');

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No sb-pending xs2_orders rows found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $query->orderBy('id')->limit(20)->get(['id', 'external_order_id', 'sb_order_id'])
                ->each(function (Xs2Order $order): void {
                    $this->line(sprintf(
                        '#%d sb_order_id=%s external_order_id=%s',
                        $order->id,
                        $order->sb_order_id ?? 'null',
                        $order->external_order_id,
                    ));
                });

            if ($count > 20) {
                $this->line(sprintf('... and %d more', $count - 20));
            }

            $this->info(sprintf('Would delete %d sb-pending xs2_orders row(s).', $count));

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info(sprintf('Deleted %d sb-pending xs2_orders row(s).', $deleted));

        return self::SUCCESS;
    }
}
