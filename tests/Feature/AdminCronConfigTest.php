<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminCronConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
    }

    public function test_admin_can_view_cron_configuration(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-config-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk()
            ->assertJsonPath('data.scheduler.definition_file', 'routes/console.php')
            ->assertJsonStructure([
                'data' => [
                    'scheduler' => [
                        'timezone',
                        'runner_command',
                        'queue_workers',
                        'configured_sports',
                        'xs2_enabled',
                        'schedule_health' => ['status', 'task_counts', 'total_tasks'],
                    ],
                    'tasks' => [
                        '*' => [
                            'id',
                            'name',
                            'enabled',
                            'expression',
                            'schedule',
                            'status',
                            'is_running',
                            'last_result',
                            'last_run_at',
                            'next_run_at',
                            'last_successful_at',
                        ],
                    ],
                ],
            ]);
    }

    public function test_cron_config_exposes_schedule_expression_and_status_badges(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-config-status-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk();

        $tasks = collect($response->json('data.tasks'));
        $inventoryIncremental = $tasks->firstWhere('id', 'xs2-inventory-incremental');

        $this->assertNotNull($inventoryIncremental);
        $this->assertSame('2,32 * * * *', $inventoryIncremental['expression']);
        $this->assertSame('Every 30 minutes', $inventoryIncremental['schedule']);
        $this->assertTrue($inventoryIncremental['interval_configurable']);
        $this->assertSame(30, $inventoryIncremental['interval_minutes']);
        $this->assertNotNull($inventoryIncremental['next_run_at']);
        $this->assertContains($inventoryIncremental['status'], ['running', 'idle', 'failed', 'disabled']);
        $this->assertIsBool($inventoryIncremental['is_running']);
        $this->assertIsBool($inventoryIncremental['enabled']);
    }

    public function test_admin_can_view_cron_sync_logs(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-config-logs-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/cron-config/logs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'is_active',
                    'running_task_count',
                    'running_tasks',
                    'sync_states',
                    'inventory' => [
                        'running',
                        'failed',
                        'completed_recent',
                        'recent_events',
                    ],
                    'api_debug_batches',
                    'global_api_debug',
                    'entries',
                ],
            ]);
    }

    public function test_admin_can_stop_all_crons(): void
    {
        config()->set('app.scheduler_enabled', true);
        config()->set('xs2.enabled', true);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-stop-all-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/cron-config/stop-all', ['stop_queues' => false])
            ->assertOk()
            ->assertJsonPath('data.action', 'stop')
            ->assertJsonPath('data.scheduler_enabled', false)
            ->assertJsonPath('data.snapshot.scheduler.scheduler_enabled', false);

        $this->assertSame(
            'false',
            app(\App\Services\Admin\IntegrationSettingService::class)->value(
                \App\Services\Admin\IntegrationSettingService::APP_SCHEDULER_ENABLED,
            ),
        );
    }

    public function test_admin_can_start_all_crons_after_stop(): void
    {
        config()->set('app.scheduler_enabled', true);
        config()->set('xs2.enabled', true);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-start-all-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/cron-config/stop-all', ['stop_queues' => false])
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/admin/cron-config/start-all')
            ->assertOk()
            ->assertJsonPath('data.action', 'start')
            ->assertJsonPath('data.scheduler_enabled', true)
            ->assertJsonPath('data.snapshot.scheduler.scheduler_enabled', true);
    }

    public function test_start_all_enables_scheduler_even_when_env_default_is_false(): void
    {
        config()->set('app.scheduler_enabled', false);
        config()->set('app.low_load_mode', false);
        config()->set('xs2.enabled', true);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-start-all-env-false-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/cron-config/stop-all', ['stop_queues' => false])
            ->assertOk()
            ->assertJsonPath('data.scheduler_enabled', false);

        $this->withToken($token)
            ->postJson('/api/admin/cron-config/start-all')
            ->assertOk()
            ->assertJsonPath('message', 'Scheduled crons re-enabled. Queue workers must be restarted manually if they were stopped.')
            ->assertJsonPath('data.scheduler_enabled', true)
            ->assertJsonPath('data.low_load_mode', false)
            ->assertJsonPath('data.snapshot.scheduler.scheduler_enabled', true);

        $this->assertSame(
            'true',
            app(\App\Services\Admin\IntegrationSettingService::class)->value(
                \App\Services\Admin\IntegrationSettingService::APP_SCHEDULER_ENABLED,
            ),
        );
    }

    public function test_stop_all_crons_also_stops_queue_workers(): void
    {
        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $now = now()->getTimestamp();
        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue' => 'xs2-sync',
            'payload' => json_encode(['displayName' => 'TestJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-stop-queues-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/cron-config/stop-all')
            ->assertOk()
            ->assertJsonPath('data.queue.jobs_deleted', 1)
            ->assertJsonPath('data.snapshot.scheduler.queue_stats.totals.total', 0);
    }

    public function test_stop_all_crons_disables_every_integration_flag(): void
    {
        config()->set('app.scheduler_enabled', true);
        config()->set('xs2.enabled', true);
        config()->set('services.seller_api.enabled', true);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-stop-flags-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/cron-config/stop-all', ['stop_queues' => false])
            ->assertOk()
            ->assertJsonPath('data.low_load_mode', true)
            ->assertJsonStructure([
                'data' => [
                    'aws_emergency_steps' => [
                        '*' => ['title', 'command', 'note'],
                    ],
                ],
            ]);

        $settings = app(\App\Services\Admin\IntegrationSettingService::class);

        $this->assertSame('false', $settings->value(\App\Services\Admin\IntegrationSettingService::XS2_ENABLED));
        $this->assertSame('false', $settings->value(\App\Services\Admin\IntegrationSettingService::SELLER_API_ENABLED));
        $this->assertSame('true', $settings->value(\App\Services\Admin\IntegrationSettingService::APP_LOW_LOAD_MODE));
    }

    public function test_cron_config_exposes_low_load_mode_and_aws_steps(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-low-load-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'scheduler' => [
                        'low_load_mode',
                        'aws_emergency_steps' => [
                            '*' => ['title', 'command', 'note'],
                        ],
                    ],
                ],
            ]);
    }

    public function test_event_sync_tasks_are_manual_only_by_default(): void
    {
        config()->set('xs2.events_sync.schedule_enabled', false);
        config()->set('services.seller_api.enabled', true);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-manual-events-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk();

        $tasks = collect($response->json('data.tasks'));
        $xs2Events = $tasks->firstWhere('id', 'xs2-events-sync');
        $sbEvents = $tasks->firstWhere('id', 'sb-events-sync');

        $this->assertNotNull($xs2Events);
        $this->assertSame('Manual only', $xs2Events['schedule']);
        $this->assertNull($xs2Events['expression']);
        $this->assertTrue($xs2Events['extra']['manual_only']);

        $this->assertNotNull($sbEvents);
        $this->assertSame('Manual only', $sbEvents['schedule']);
        $this->assertNull($sbEvents['expression']);
        $this->assertTrue($sbEvents['extra']['manual_only']);
    }

    public function test_xs2_events_sync_is_not_registered_when_manual_only(): void
    {
        config()->set('xs2.events_sync.schedule_enabled', false);

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $xs2EventCommands = $events->filter(
            fn ($event): bool => str_contains((string) ($event->command ?? ''), 'xs2:sync-events'),
        );

        $this->assertCount(0, $xs2EventCommands);
    }

    public function test_admin_can_update_cron_duration_for_a_scheduled_job(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-duration-test')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/queue/cron-jobs/xs2-sb-order-sync/interval', [
                'interval_minutes' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.cron_job_id', 'xs2-sb-order-sync')
            ->assertJsonPath('data.interval_minutes', 5)
            ->assertJsonPath('data.interval_is_overridden', true)
            ->assertJsonPath('data.requires_schedule_work_restart', true)
            ->assertJsonPath('data.job.interval_minutes', 5)
            ->assertJsonPath('data.job.extra.sync_interval_minutes', 5);

        $this->assertSame(
            '5',
            app(\App\Services\Admin\IntegrationSettingService::class)->value(
                \App\Services\Admin\IntegrationSettingService::SB_BOOKINGS_SYNC_INTERVAL_MINUTES,
            ),
        );

        $intervals = app(\App\Services\Admin\CronIntervalService::class);
        config(['xs2.sb_bookings_sync.sync_interval_minutes' => 2]);
        $intervals->applyConfigOverrides();

        $this->assertSame(5, $intervals->minutesFor('xs2-sb-order-sync'));
        $this->assertSame(
            '2,7,12,17,22,27,32,37,42,47,52,57 * * * *',
            $intervals->staggeredExpression(5, 17),
        );
    }

    public function test_cron_duration_rejects_out_of_range_and_manual_jobs(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('cron-duration-validation-test')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/admin/queue/cron-jobs/xs2-sb-order-sync/interval', [
                'interval_minutes' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['interval_minutes']);

        $this->withToken($token)
            ->patchJson('/api/admin/queue/cron-jobs/xs2-inventory-incremental/interval', [
                'interval_minutes' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['interval_minutes']);

        $this->withToken($token)
            ->patchJson('/api/admin/queue/cron-jobs/sb-events-sync/interval', [
                'interval_minutes' => 5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cron_job_id']);
    }
}
