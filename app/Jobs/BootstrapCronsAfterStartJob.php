<?php

namespace App\Jobs;

use App\Services\Admin\CronExecutionLogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kicks off the safe Start All pipeline: inventory → publish → SB qty reconcile → guest data.
 */
class BootstrapCronsAfterStartJob implements ShouldQueue
{
    use Queueable;

    /** @var list<array{id: string, delay_seconds: int}> */
    private const SEQUENCE = [
        ['id' => 'xs2-inventory-full', 'delay_seconds' => 0],
        ['id' => 'xs2-sb-new-listing-publish', 'delay_seconds' => 120],
        ['id' => 'xs2-sb-listing-inventory', 'delay_seconds' => 300],
        ['id' => 'xs2-sb-order-sync', 'delay_seconds' => 330],
        ['id' => 'xs2-sb-order-guest-data-sync', 'delay_seconds' => 360],
    ];

    public function __construct()
    {
        $this->onQueue(config('xs2.admin_cron_queue', 'default'));
    }

    public function handle(CronExecutionLogService $executionLogs): void
    {
        foreach (self::SEQUENCE as $step) {
            $cronJobId = (string) $step['id'];
            if (! $this->isEnabled($cronJobId)) {
                continue;
            }

            $logId = $executionLogs->start($cronJobId, 'start_all');
            $pending = RunAdminCronJob::dispatch($cronJobId, $logId, force: true, trigger: 'start_all');

            $delaySeconds = max(0, (int) ($step['delay_seconds'] ?? 0));
            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
        }
    }

    private function isEnabled(string $cronJobId): bool
    {
        if (! (bool) config('xs2.enabled', true)) {
            return false;
        }

        return match ($cronJobId) {
            'xs2-inventory-full' => true,
            'xs2-sb-new-listing-publish' => (bool) config('services.seller_api.enabled', true)
                && (bool) config('xs2.sb_new_listing_publish.enabled', true),
            'xs2-sb-listing-inventory' => (bool) config('services.seller_api.enabled', true)
                && (bool) config('xs2.sb_listing_inventory.enabled', true),
            'xs2-sb-order-sync' => (bool) config('services.seller_api.enabled', true)
                && (bool) config('xs2.sb_bookings_sync.enabled', true),
            'xs2-sb-order-guest-data-sync' => (bool) config('xs2.sb_order_guest_data_sync.enabled', true),
            default => false,
        };
    }
}
