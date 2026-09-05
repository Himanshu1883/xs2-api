<?php

namespace Tests\Unit;

use App\Services\Admin\CronToggleService;
use App\Services\Admin\IntegrationSettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CronToggleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('integration_settings')) {
            Schema::create('integration_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->boolean('is_secret')->default(false);
                $table->timestamps();
            });
        } else {
            \Illuminate\Support\Facades\DB::table('integration_settings')->delete();
        }
    }

    public function test_start_all_on_runs_all_except_explicitly_disabled(): void
    {
        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(true);
        $service->setCronEnabled('xs2-sb-order-sync', false);
        $service->setCronEnabled('xs2-sb-listing-inventory', false);

        $this->assertTrue($service->shouldRun('xs2-inventory-full', true));
        $this->assertFalse($service->shouldRun('xs2-sb-order-sync', true));
        $this->assertFalse($service->shouldRun('xs2-sb-listing-inventory', true));
    }

    public function test_start_all_off_runs_only_individually_enabled(): void
    {
        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(false);
        $service->setCronEnabled('xs2-sb-order-sync', true);

        $this->assertFalse($service->shouldRun('xs2-inventory-full', true));
        $this->assertTrue($service->shouldRun('xs2-sb-order-sync', true));
        $this->assertFalse($service->shouldRun('xs2-sb-listing-inventory', true));
    }

    public function test_start_all_off_with_second_cron_enabled(): void
    {
        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(false);
        $service->setCronEnabled('xs2-sb-order-sync', true);
        $service->setCronEnabled('xs2-sb-listing-inventory', true);

        $this->assertTrue($service->shouldRun('xs2-sb-order-sync', true));
        $this->assertTrue($service->shouldRun('xs2-sb-listing-inventory', true));
        $this->assertFalse($service->shouldRun('xs2-inventory-full', true));
    }

    public function test_start_all_off_with_all_disabled_runs_nothing(): void
    {
        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(false);

        $this->assertFalse($service->shouldRun('xs2-inventory-full', true));
        $this->assertFalse($service->shouldRun('xs2-sb-order-sync', true));
        $this->assertFalse($service->schedulerShouldBeActive());
    }

    public function test_scheduler_stays_active_when_start_all_off_but_cron_enabled(): void
    {
        $settings = app(IntegrationSettingService::class);
        $settings->set(IntegrationSettingService::APP_SCHEDULER_ENABLED, 'true');

        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(false);
        $service->setCronEnabled('xs2-sb-order-sync', true);

        $this->assertTrue($service->schedulerShouldBeActive());
    }

    public function test_scheduler_stays_active_when_master_disabled_but_cron_individually_enabled(): void
    {
        $settings = app(IntegrationSettingService::class);
        $settings->set(IntegrationSettingService::APP_SCHEDULER_ENABLED, 'false');

        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(false);
        $service->setCronEnabled('xs2-sb-new-listing-publish', true);

        $this->assertTrue($service->schedulerShouldBeActive());
        $this->assertTrue($service->shouldRun('xs2-sb-new-listing-publish', true));
    }

    public function test_scheduler_stays_active_when_start_all_on_despite_master_disabled(): void
    {
        $settings = app(IntegrationSettingService::class);
        $settings->set(IntegrationSettingService::APP_SCHEDULER_ENABLED, 'false');

        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(true);

        $this->assertTrue($service->schedulerShouldBeActive());
    }

    public function test_config_prerequisite_blocks_execution(): void
    {
        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(true);

        $this->assertFalse($service->shouldRun('xs2-sb-order-sync', false));
    }

    public function test_opt_in_retry_cron_does_not_run_with_start_all_unless_explicitly_enabled(): void
    {
        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(true);

        $this->assertFalse($service->shouldRun('xs2-sb-failed-listing-publish-retry', true));

        $service->setCronEnabled('xs2-sb-failed-listing-publish-retry', true);

        $this->assertTrue($service->shouldRun('xs2-sb-failed-listing-publish-retry', true));
    }
}
