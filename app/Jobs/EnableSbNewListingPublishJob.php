<?php

namespace App\Jobs;

use App\Services\Admin\IntegrationSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deferred enable for xs2:publish-new-sb-listings after Start All restores inventory crons first.
 */
class EnableSbNewListingPublishJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue(config('xs2.queue', config('services.xs2.queue', 'default')));
    }

    public function handle(IntegrationSettingService $integrationSettings): void
    {
        $integrationSettings->set(IntegrationSettingService::XS2_SB_NEW_LISTING_PUBLISH_ENABLED, 'true');
        config(['xs2.sb_new_listing_publish.enabled' => true]);

        Log::channel(config('xs2.log_channel', 'stack'))->info(
            'Deferred enable: Seats Broker new listing publish cron turned on after inventory window.',
        );
    }
}
