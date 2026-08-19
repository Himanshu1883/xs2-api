<?php

namespace Tests\Feature;

use App\Jobs\SyncXs2EventsJob;
use App\Models\CronExecutionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminCronJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
    }

    public function test_admin_can_list_cron_jobs_dashboard(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queue/cron-jobs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'total_jobs',
                        'running',
                        'failed',
                        'idle',
                        'disabled',
                        'schedule_health',
                        'queue_pending',
                        'queue_running',
                        'execution_logs_available',
                    ],
                    'scheduler' => [
                        'timezone',
                        'definition_file',
                        'queue_workers',
                    ],
                    'jobs' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'schedule',
                            'command',
                            'queue',
                            'enabled',
                            'status',
                            'supports_run_now',
                            'execution_logs_available',
                            'category',
                        ],
                    ],
                ],
            ]);
    }

    public function test_admin_can_view_cron_job_execution_logs(): void
    {
        CronExecutionLog::query()->create([
            'cron_job_id' => 'xs2-inventory-incremental',
            'trigger' => 'scheduled',
            'status' => 'success',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'duration_ms' => 60000,
            'message' => 'Scheduled run completed successfully.',
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queue/cron-jobs/xs2-inventory-incremental/logs')
            ->assertOk()
            ->assertJsonPath('data.cron_job_id', 'xs2-inventory-incremental')
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonPath('data.logs.0.status', 'success');
    }

    public function test_admin_can_view_global_cron_execution_logs_via_live_stats(): void
    {
        CronExecutionLog::query()->create([
            'cron_job_id' => 'xs2-inventory-incremental',
            'trigger' => 'scheduled',
            'status' => 'success',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
            'duration_ms' => 60000,
            'message' => 'Scheduled run completed successfully.',
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queue/live-stats')
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_execution_logs')
            ->assertJsonPath('data.recent_execution_logs.0.cron_job_id', 'xs2-inventory-incremental')
            ->assertJsonPath('data.recent_execution_logs.0.status', 'success');
    }

    public function test_unknown_cron_job_logs_return_validation_error(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queue/cron-jobs/not-a-real-job/logs')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cron_job_id']);
    }

    public function test_admin_can_trigger_manual_cron_run(): void
    {
        Queue::fake();
        Artisan::shouldReceive('call')
            ->once()
            ->with('xs2:sync-inventory', ['--mode' => 'incremental'])
            ->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Queued 0 inventory sync job(s).');

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/queue/cron-jobs/xs2-inventory-incremental/run')
            ->assertAccepted()
            ->assertJsonPath('data.cron_job_id', 'xs2-inventory-incremental')
            ->assertJsonPath('data.status', 'success');

        $this->assertDatabaseHas('cron_execution_logs', [
            'cron_job_id' => 'xs2-inventory-incremental',
            'trigger' => 'manual',
            'status' => 'success',
        ]);
    }

    public function test_admin_can_run_xs2_events_sync_for_all_sports(): void
    {
        Queue::fake();
        config()->set('services.xs2.sports', ['soccer', 'tennis']);
        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/queue/cron-jobs/xs2-events-sync/run')
            ->assertAccepted()
            ->assertJsonPath('data.cron_job_id', 'xs2-events-sync')
            ->assertJsonPath('data.status', 'success');

        Queue::assertPushed(SyncXs2EventsJob::class, 2);
    }

    public function test_cron_config_exposes_single_xs2_events_sync_task(): void
    {
        config()->set('services.xs2.sports', ['soccer', 'tennis']);
        $token = $this->adminToken();

        $response = $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk();

        $tasks = collect($response->json('data.tasks'));
        $this->assertNull($tasks->firstWhere('id', 'xs2-events-tennis-incremental'));
        $this->assertNull($tasks->firstWhere('id', 'xs2-events-soccer-full'));

        $task = $tasks->firstWhere('id', 'xs2-events-sync');
        $this->assertNotNull($task);
        $this->assertSame('xs2:sync-events', $task['command']);
        $this->assertSame('xs2', $task['category']);
        $this->assertSame('Manual only', $task['schedule']);
        $this->assertArrayHasKey('what_it_does', $task['extra']);
        $this->assertSame(['soccer', 'tennis'], $task['extra']['configured_sports']);
    }

    public function test_cron_config_exposes_sb_order_to_xs2_order_sync_task(): void
    {
        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.listing_base_url', 'https://sandbox-sellerapi.seatsbrokers.com');
        config()->set('xs2.sb_bookings_sync.enabled', true);

        $token = $this->adminToken();

        $response = $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk();

        $task = collect($response->json('data.tasks'))->firstWhere('id', 'xs2-sb-order-sync');
        $this->assertNotNull($task);
        $this->assertSame('seller-api:sync-bookings', $task['command']);
        $this->assertSame('xs2', $task['category']);
        $this->assertTrue($task['enabled']);
        $this->assertArrayHasKey('what_it_does', $task['extra']);
        $this->assertSame('sb_order_xs2_order_sync', $task['extra']['cron_role']);
    }

    public function test_admin_can_run_sb_order_to_xs2_order_sync(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('seller-api:sync-bookings')
            ->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Synced 0 bookings.');

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/queue/cron-jobs/xs2-sb-order-sync/run')
            ->assertAccepted()
            ->assertJsonPath('data.cron_job_id', 'xs2-sb-order-sync')
            ->assertJsonPath('data.status', 'success');
    }

    private function adminToken(): string
    {
        $user = User::factory()->create(['user_type' => 6]);

        return $user->createToken('cron-job-test')->plainTextToken;
    }
}
