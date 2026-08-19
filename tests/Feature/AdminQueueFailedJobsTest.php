<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminQueueFailedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
        $this->createJobsTable();
        $this->createFailedJobsTable();
    }

    public function test_admin_can_list_failed_jobs(): void
    {
        $this->seedFailedJob('xs2-sync', 'App\\Jobs\\SyncXs2EventInventory');

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queues/failed-jobs')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.queue', 'xs2-sync')
            ->assertJsonStructure([
                'data' => [
                    'available',
                    'data' => [
                        '*' => ['id', 'uuid', 'queue', 'job_name', 'failed_at'],
                    ],
                    'meta' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_admin_can_delete_failed_job(): void
    {
        $uuid = $this->seedFailedJob('seller-api', 'App\\Jobs\\PushXs2TicketToSellerApi');

        $token = $this->adminToken();

        $this->withToken($token)
            ->deleteJson('/api/admin/queues/failed-jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $uuid);

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_queue_snapshot_includes_health_metrics(): void
    {
        $this->seedJobs('xs2-sync', pending: 1);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queues')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'health' => [
                        'oldest_pending_job_seconds',
                        'failed_jobs_count',
                        'workers' => ['supported', 'detected', 'process_count', 'processes'],
                    ],
                    'failed_jobs_summary' => ['available', 'total'],
                ],
            ]);
    }

    public function test_supervisor_config_includes_deployment_steps(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/queues/supervisor-config')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['profile', 'config', 'install_path', 'deployment_steps'],
            ])
            ->assertJsonPath('data.config', fn ($value): bool => str_contains((string) $value, '[group:seatsbroker-workers]'));
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => 6]);

        return $admin->createToken('queue-failed-jobs-test')->plainTextToken;
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

    private function createFailedJobsTable(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function seedFailedJob(string $queue, string $displayName): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => class_basename($displayName)]),
            'exception' => 'RuntimeException: Test failure',
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    private function seedJobs(string $queue, int $pending = 0): void
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
                'created_at' => $now - 120,
            ]);
        }
    }
}
