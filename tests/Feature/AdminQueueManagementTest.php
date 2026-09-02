<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminQueueManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
        $this->createJobsTable();
    }

    public function test_admin_can_view_queue_job_counts(): void
    {
        $this->seedJobs('seller-api', pending: 2, running: 1);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queues')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.totals.pending', 2)
            ->assertJsonPath('data.totals.running', 1)
            ->assertJsonStructure([
                'data' => [
                    'available',
                    'connection',
                    'totals' => ['pending', 'running', 'delayed', 'total', 'failed'],
                    'queues',
                    'other_queues',
                ],
            ]);
    }

    public function test_cron_config_includes_queue_stats(): void
    {
        $this->seedJobs('xs2-sync', pending: 3);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk()
            ->assertJsonPath('data.scheduler.queue_stats.totals.pending', 3)
            ->assertJsonStructure([
                'data' => [
                    'scheduler' => [
                        'queue_stats' => ['available', 'connection', 'totals', 'other_queues'],
                        'queue_workers',
                    ],
                ],
            ]);
    }

    public function test_admin_can_view_queue_live_stats(): void
    {
        $this->seedJobs('xs2-sync', pending: 5, running: 2);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queue/live-stats')
            ->assertOk()
            ->assertJsonPath('data.activity_window_minutes', 5)
            ->assertJsonPath('data.queue.pending', 5)
            ->assertJsonPath('data.queue.running', 2)
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'activity_window_minutes',
                    'is_active',
                    'tickets' => ['total', 'updated_recent'],
                    'inventory_sync' => ['completed', 'running', 'failed', 'pending', 'total'],
                    'listings' => ['updated_recent'],
                    'queue' => ['available', 'pending', 'running', 'delayed', 'total', 'failed'],
                    'recent_execution_logs',
                ],
            ]);
    }

    public function test_admin_can_clear_pending_queue_jobs(): void
    {
        $this->seedJobs('seller-api', pending: 4, running: 1);

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/queues/clear', ['queue' => 'seller-api'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 4)
            ->assertJsonPath('data.snapshot.totals.pending', 0)
            ->assertJsonPath('data.snapshot.totals.running', 1);
    }

    public function test_admin_can_stop_all_queue_jobs(): void
    {
        $this->seedJobs('xs2-sync', pending: 2, running: 1);

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/queues/stop')
            ->assertOk()
            ->assertJsonPath('data.jobs_deleted', 3)
            ->assertJsonPath('data.snapshot.totals.total', 0);
    }

    public function test_promote_delayed_command_makes_jobs_available(): void
    {
        $now = now()->getTimestamp();
        DB::table('jobs')->insert([
            'queue' => 'xs2-sync',
            'payload' => json_encode(['displayName' => 'TestJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now + 3600,
            'created_at' => $now,
        ]);

        $this->artisan('queue:promote-delayed', ['--queue' => 'xs2-sync'])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->where('available_at', '>', $now)->count());
    }

    public function test_promote_delayed_command_can_target_seller_api_queue(): void
    {
        $now = now()->getTimestamp();
        DB::table('jobs')->insert([
            'queue' => 'seller-api',
            'payload' => json_encode(['displayName' => 'TestJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now + 3600,
            'created_at' => $now,
        ]);

        $this->artisan('queue:promote-delayed', ['--queue' => 'seller-api'])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'seller-api')->where('available_at', '>', $now)->count());
    }

    public function test_stop_all_clears_queue_restart_signal(): void
    {
        $this->seedJobs('seller-api', pending: 2);

        cache()->forever('illuminate:queue:restart', now()->getTimestamp());

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/queues/stop')
            ->assertOk();

        $this->assertNull(cache()->get('illuminate:queue:restart'));
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => 6]);

        return $admin->createToken('queue-management-test')->plainTextToken;
    }

    private function createJobsTable(): void
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
    }

    private function seedJobs(string $queue, int $pending = 0, int $running = 0): void
    {
        $now = now()->getTimestamp();
        $payload = json_encode(['displayName' => 'TestJob']);

        for ($index = 0; $index < $pending; $index++) {
            DB::table('jobs')->insert([
                'queue' => $queue,
                'payload' => $payload,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ]);
        }

        for ($index = 0; $index < $running; $index++) {
            DB::table('jobs')->insert([
                'queue' => $queue,
                'payload' => $payload,
                'attempts' => 1,
                'reserved_at' => $now,
                'available_at' => $now,
                'created_at' => $now,
            ]);
        }
    }
}
