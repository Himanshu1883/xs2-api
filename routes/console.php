<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$schedulerEnabled = (bool) config('app.scheduler_enabled', true);
$xs2Enabled = (bool) config('xs2.enabled', true);
$sellerApiEnabled = (bool) config('services.seller_api.enabled', true);
$overlapMinutes = max(5, (int) config('xs2.sync.event_lock_minutes', 10));
$incrementalInterval = max(10, (int) config('xs2.sync.incremental_interval_minutes', 30));
$fullIntervalMinutes = max(60, (int) config('xs2.sync.full_interval_minutes', 180));
$eventsIntervalMinutes = max(60, (int) config('xs2.sync.events_interval_minutes', 60));
$eventsSyncScheduled = (bool) config('xs2.events_sync.schedule_enabled', false);
$fullInventoryCron = '0 */'.max(1, (int) ceil($fullIntervalMinutes / 60)).' * * *';
$eventsCron = '0 */'.max(1, (int) ceil($eventsIntervalMinutes / 60)).' * * *';

/**
 * Spread recurring tasks across the hour so they do not all fire at once.
 *
 * @return non-empty-string
 */
$staggeredCron = static function (int $intervalMinutes, int $offsetMinute): string {
    $interval = max(10, min(59, $intervalMinutes));
    $start = $offsetMinute % $interval;
    $minutes = [];
    for ($minute = $start; $minute < 60; $minute += $interval) {
        $minutes[] = (string) $minute;
    }

    return implode(',', $minutes).' * * * *';
};

if ($schedulerEnabled) {
    if ($xs2Enabled) {
        Schedule::command('xs2:sync-inventory --mode=incremental')
            ->cron($staggeredCron($incrementalInterval, 2))
            // At the top of the hour the full run has priority; it already covers
            // the same inventory changes as an incremental run.
            ->when(fn (): bool => now()->minute !== 0)
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
        Schedule::command('xs2:sync-inventory --mode=full')
            ->cron($fullInventoryCron)
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();

        if ($eventsSyncScheduled) {
            Schedule::command('xs2:sync-events')
                ->cron($eventsCron)
                // At midnight the daily full run reconciles every sport; skip hourly
                // incrementals at hour :00 so the full snapshot can take priority.
                ->when(fn (): bool => now()->hour !== 0)
                ->withoutOverlapping($overlapMinutes)
                ->onOneServer();

            Schedule::command('xs2:sync-events --full')
                ->daily()
                ->withoutOverlapping($overlapMinutes)
                ->onOneServer();
        }
    }

    if ($xs2Enabled && (bool) config('xs2.sb_order_guest_data_sync.enabled', true)) {
        $guestDataInterval = max(1, min(59, (int) config('xs2.sb_order_guest_data_sync.sync_interval_minutes', 30)));
        Schedule::command('xs2:sync-order-guest-data')
            ->cron($staggeredCron($guestDataInterval, 22))
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
    }

    if ($xs2Enabled && $sellerApiEnabled && (bool) config('xs2.sb_listing_inventory.enabled', true)) {
        $sbListingInterval = max(1, min(59, (int) config('xs2.sb_listing_inventory.sync_interval_minutes', 30)));
        Schedule::command('xs2:sync-sb-listing-inventory')
            ->cron($staggeredCron($sbListingInterval, 12))
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
    }

    if ($xs2Enabled && $sellerApiEnabled && (bool) config('xs2.sb_new_listing_publish.enabled', true)) {
        $sbPublishInterval = max(1, min(59, (int) config('xs2.sb_new_listing_publish.sync_interval_minutes', 30)));
        Schedule::command('xs2:publish-new-sb-listings')
            ->cron($staggeredCron($sbPublishInterval, 7))
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
    }

    if ($xs2Enabled && $sellerApiEnabled && (bool) config('xs2.sb_bookings_sync.enabled', true)) {
        $sbBookingsInterval = max(1, min(59, (int) config('xs2.sb_bookings_sync.sync_interval_minutes', 30)));
        Schedule::command('seller-api:sync-bookings')
            ->cron($staggeredCron($sbBookingsInterval, 17))
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
    }

    Schedule::command('sanctum:prune-expired --hours=24')
        ->daily()
        ->withoutOverlapping($overlapMinutes)
        ->onOneServer();
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
