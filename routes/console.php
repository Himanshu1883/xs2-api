<?php

use App\Services\Admin\CronIntervalService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$schedulerEnabled = (bool) config('app.scheduler_enabled', true);
$xs2Enabled = (bool) config('xs2.enabled', true);
$sellerApiEnabled = (bool) config('services.seller_api.enabled', true);
$overlapMinutes = max(5, (int) config('xs2.sync.event_lock_minutes', 10));
$eventsSyncScheduled = (bool) config('xs2.events_sync.schedule_enabled', false);

$intervals = app(CronIntervalService::class);
$incrementalInterval = $intervals->minutesFor('xs2-inventory-incremental');
$fullInventoryCron = $intervals->hourlyExpression($intervals->minutesFor('xs2-inventory-full'));
$eventsIntervalMinutes = max(60, (int) config('xs2.sync.events_interval_minutes', 60));
$eventsCron = '0 */'.max(1, (int) ceil($eventsIntervalMinutes / 60)).' * * *';

if ($schedulerEnabled) {
    if ($xs2Enabled) {
        Schedule::command('xs2:sync-inventory --mode=incremental')
            ->cron($intervals->staggeredExpression($incrementalInterval, 2))
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
        $guestDataInterval = $intervals->minutesFor('xs2-sb-order-guest-data-sync');
        Schedule::command('xs2:sync-order-guest-data')
            ->cron($intervals->staggeredExpression($guestDataInterval, 22))
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
    }

    if ($xs2Enabled && $sellerApiEnabled && (bool) config('xs2.sb_listing_inventory.enabled', true)) {
        $sbListingInterval = $intervals->minutesFor('xs2-sb-listing-inventory');
        Schedule::command('xs2:sync-sb-listing-inventory')
            ->cron($intervals->staggeredExpression($sbListingInterval, 12))
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
    }

    if ($xs2Enabled && $sellerApiEnabled && (bool) config('xs2.sb_new_listing_publish.enabled', true)) {
        $sbPublishInterval = $intervals->minutesFor('xs2-sb-new-listing-publish');
        $sbPublishSchedule = Schedule::command('xs2:publish-new-sb-listings');
        if ($sbPublishInterval <= 1) {
            $sbPublishSchedule->everyMinute();
        } else {
            $sbPublishSchedule->cron($intervals->staggeredExpression($sbPublishInterval, 7));
        }
        $sbPublishSchedule
            ->withoutOverlapping($overlapMinutes)
            ->onOneServer();
    }

    if ($xs2Enabled && $sellerApiEnabled && (bool) config('xs2.sb_bookings_sync.enabled', true)) {
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
