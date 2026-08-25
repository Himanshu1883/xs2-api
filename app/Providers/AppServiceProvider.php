<?php

namespace App\Providers;

use App\Contracts\MarketplaceListingPublisher;
use App\Contracts\Xs2ReservationService;
use App\Listeners\CronCommandInstrumentationListener;
use App\Services\Admin\CronControlService;
use App\Services\Admin\CronExecutionContext;
use App\Services\Admin\CronIntervalService;
use App\Services\Admin\QueueProfileService;
use App\Services\Admin\CronExecutionLogService;
use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\SellerApi\SellerApiRequestDebugger;
use App\Services\SplitListings\SeatsBrokerListingPublisher;
use App\Services\Xs2\UnsupportedXs2ReservationService;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use App\Services\Xs2\Xs2ApiRequestDebugger;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Xs2ReservationService::class, UnsupportedXs2ReservationService::class);
        $this->app->bind(MarketplaceListingPublisher::class, SeatsBrokerListingPublisher::class);
        $this->app->singleton(SellerApiDebugRecorder::class);
        $this->app->singleton(SellerApiRequestDebugger::class);
        $this->app->singleton(Xs2ApiDebugRecorder::class);
        $this->app->singleton(Xs2ApiRequestDebugger::class);
        $this->app->singleton(CronExecutionContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        try {
            app(CronControlService::class)->applyConfigOverrides();
            app(CronIntervalService::class)->applyConfigOverrides();
            app(QueueProfileService::class)->applyActiveProfileToRuntime();
        } catch (\Throwable) {
            // Ignore during migrations or before integration_settings exists.
        }

        $this->app->booted(function (): void {
            try {
                app(CronExecutionLogService::class)->attachScheduleHooks();
            } catch (\Throwable) {
                // Ignore during migrations or when the database is temporarily unavailable.
            }
        });

        Event::listen(CommandStarting::class, [CronCommandInstrumentationListener::class, 'handleStarting']);
        Event::listen(CommandFinished::class, [CronCommandInstrumentationListener::class, 'handleFinished']);
    }
}
