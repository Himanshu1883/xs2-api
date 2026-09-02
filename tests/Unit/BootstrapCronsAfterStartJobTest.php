<?php

namespace Tests\Unit;

use App\Jobs\BootstrapCronsAfterStartJob;
use App\Jobs\RunAdminCronJob;
use App\Services\Admin\CronExecutionLogService;
use App\Services\Admin\CronToggleService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BootstrapCronsAfterStartJobTest extends TestCase
{
    public function test_bootstrap_queues_order_sync_when_enabled(): void
    {
        config()->set('xs2.enabled', true);
        config()->set('services.seller_api.enabled', true);
        config()->set('xs2.sb_bookings_sync.enabled', true);
        config()->set('xs2.sb_new_listing_publish.enabled', true);
        config()->set('xs2.sb_listing_inventory.enabled', true);
        config()->set('xs2.sb_order_guest_data_sync.enabled', true);

        Queue::fake();

        (new BootstrapCronsAfterStartJob())->handle(
            app(CronExecutionLogService::class),
            app(CronToggleService::class),
        );

        Queue::assertPushed(RunAdminCronJob::class, fn (RunAdminCronJob $job): bool => $job->cronJobId === 'xs2-sb-order-sync');
        Queue::assertPushed(RunAdminCronJob::class, fn (RunAdminCronJob $job): bool => $job->cronJobId === 'xs2-inventory-full');
        Queue::assertPushed(RunAdminCronJob::class, fn (RunAdminCronJob $job): bool => $job->cronJobId === 'xs2-sb-listing-inventory');
    }

    public function test_bootstrap_skips_order_sync_when_disabled(): void
    {
        config()->set('xs2.enabled', true);
        config()->set('services.seller_api.enabled', true);
        config()->set('xs2.sb_bookings_sync.enabled', false);
        config()->set('xs2.sb_new_listing_publish.enabled', false);
        config()->set('xs2.sb_listing_inventory.enabled', false);
        config()->set('xs2.sb_order_guest_data_sync.enabled', false);

        Queue::fake();

        (new BootstrapCronsAfterStartJob())->handle(
            app(CronExecutionLogService::class),
            app(CronToggleService::class),
        );

        Queue::assertPushed(RunAdminCronJob::class, fn (RunAdminCronJob $job): bool => $job->cronJobId === 'xs2-inventory-full');
        Queue::assertNotPushed(RunAdminCronJob::class, fn (RunAdminCronJob $job): bool => $job->cronJobId === 'xs2-sb-order-sync');
    }
}
