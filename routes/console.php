<?php

use App\Services\Admin\CronIntervalService;
use App\Services\Admin\CronToggleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$overlapMinutes = max(5, (int) config('xs2.sync.event_lock_minutes', 10));
$eventsSyncScheduled = (bool) config('xs2.events_sync.schedule_enabled', false);

$intervals = app(CronIntervalService::class);
$incrementalInterval = $intervals->minutesFor('xs2-inventory-incremental');
$fullInventoryCron = $intervals->hourlyExpression($intervals->minutesFor('xs2-inventory-full'));
$eventsIntervalMinutes = max(60, (int) config('xs2.sync.events_interval_minutes', 60));
$eventsCron = '0 */'.max(1, (int) ceil($eventsIntervalMinutes / 60)).' * * *';

// Register all schedule events unconditionally so admin UI can compute next_run_at
// even when the scheduler was stopped via integration_settings. Runtime ->when() gates
// honour Start All / per-cron toggles without requiring a PHP worker restart.
$toggles = fn (): CronToggleService => app(CronToggleService::class);
$schedulerEnabled = fn (): bool => $toggles()->schedulerShouldBeActive();
$xs2Enabled = fn (): bool => $schedulerEnabled() && (bool) config('xs2.enabled', true);
$sellerApiEnabled = fn (): bool => $xs2Enabled() && (bool) config('services.seller_api.enabled', true);
$shouldRun = fn (string $cronJobId, bool $configEnabled = true): bool => $toggles()->shouldRun($cronJobId, $configEnabled);

Schedule::command('xs2:sync-inventory --mode=incremental')
    ->cron($intervals->staggeredExpression($incrementalInterval, 2))
    // At the top of the hour the full run has priority; it already covers
    // the same inventory changes as an incremental run.
    ->when(fn (): bool => $shouldRun('xs2-inventory-incremental', $xs2Enabled()) && now()->minute !== 0)
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

Schedule::command('xs2:sync-inventory --mode=full')
    ->cron($fullInventoryCron)
    ->when(fn (): bool => $shouldRun('xs2-inventory-full', $xs2Enabled()))
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

if ($eventsSyncScheduled) {
    Schedule::command('xs2:sync-events')
        ->cron($eventsCron)
        // At midnight the daily full run reconciles every sport; skip hourly
        // incrementals at hour :00 so the full snapshot can take priority.
        ->when(fn (): bool => $shouldRun('xs2-events-sync', $xs2Enabled()) && now()->hour !== 0)
        ->withoutOverlapping($overlapMinutes)
        ->onOneServer();

    Schedule::command('xs2:sync-events --full')
        ->daily()
        ->when(fn (): bool => $shouldRun('xs2-events-sync', $xs2Enabled()))
        ->withoutOverlapping($overlapMinutes)
        ->onOneServer();
}

Schedule::command('xs2:sync-order-guest-data')
    ->cron($intervals->staggeredExpression($intervals->minutesFor('xs2-sb-order-guest-data-sync'), 22))
    ->when(fn (): bool => $shouldRun(
        'xs2-sb-order-guest-data-sync',
        $xs2Enabled() && (bool) config('xs2.sb_order_guest_data_sync.enabled', true),
    ))
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

Schedule::command('xs2:sync-sb-listing-inventory')
    ->cron($intervals->staggeredExpression($intervals->minutesFor('xs2-sb-listing-inventory'), 12))
    ->when(fn (): bool => $shouldRun(
        'xs2-sb-listing-inventory',
        $sellerApiEnabled() && (bool) config('xs2.sb_listing_inventory.enabled', true),
    ))
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

$sbPublishInterval = $intervals->minutesFor('xs2-sb-new-listing-publish');
$sbPublishSchedule = Schedule::command('xs2:publish-new-sb-listings');
if ($sbPublishInterval <= 1) {
    $sbPublishSchedule->everyMinute();
} else {
    $sbPublishSchedule->cron($intervals->staggeredExpression($sbPublishInterval, 7));
}
$sbPublishSchedule
    ->when(fn (): bool => $shouldRun(
        'xs2-sb-new-listing-publish',
        $sellerApiEnabled() && (bool) config('xs2.sb_new_listing_publish.enabled', true),
    ))
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

$sbFailedRetryInterval = $intervals->minutesFor('xs2-sb-failed-listing-publish-retry');
Schedule::command('xs2:retry-failed-listing-publish')
    ->cron($intervals->staggeredExpression($sbFailedRetryInterval, 27))
    ->when(fn (): bool => $shouldRun(
        'xs2-sb-failed-listing-publish-retry',
        $sellerApiEnabled() && (bool) config('xs2.sb_failed_listing_publish_retry.enabled', false),
    ))
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

$sbBookingsInterval = $intervals->minutesFor('xs2-sb-order-sync');
$sbBookingsSchedule = Schedule::command('seller-api:sync-bookings');
if ($sbBookingsInterval <= 1) {
    $sbBookingsSchedule->everyMinute();
} elseif ($sbBookingsInterval === 2) {
    $sbBookingsSchedule->everyTwoMinutes();
} else {
    $sbBookingsSchedule->cron($intervals->staggeredExpression($sbBookingsInterval, 17));
}
$sbBookingsSchedule
    ->when(fn (): bool => $shouldRun(
        'xs2-sb-order-sync',
        $sellerApiEnabled() && (bool) config('xs2.sb_bookings_sync.enabled', true),
    ))
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->when(fn (): bool => $shouldRun('sanctum-prune-expired', $schedulerEnabled()))
    ->withoutOverlapping($overlapMinutes)
    ->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
