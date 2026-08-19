<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\IntegrationSettingService;
use App\Services\Admin\QueueProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminQueueProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
    }

    public function test_admin_can_apply_minimal_queue_profile(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('queue-profile-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/queues/profile', ['profile' => 'minimal'])
            ->assertOk()
            ->assertJsonPath('data.profile', 'minimal')
            ->assertJsonPath('data.applied.label', 'Minimal load');

        $settings = app(IntegrationSettingService::class);
        $this->assertSame('minimal', $settings->value(QueueProfileService::SETTING_PROFILE));
        $this->assertSame(1, (int) config('xs2.queue_workers.xs2_sync'));
        $this->assertSame(0, (int) config('xs2.queue_workers.seller_api'));
    }

    public function test_default_profile_is_minimal_when_low_load_mode_enabled(): void
    {
        config(['app.low_load_mode' => true]);

        $profiles = app(QueueProfileService::class);

        $this->assertSame(QueueProfileService::PROFILE_MINIMAL, $profiles->activeProfileId());
    }

    public function test_queue_snapshot_includes_profile_and_backpressure(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('queue-snapshot-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/queues')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'profile' => ['active_profile', 'profiles', 'active'],
                    'backpressure' => [
                        'pending_jobs',
                        'max_pending_jobs',
                        'load_percent',
                        'overloaded',
                    ],
                    'supervisor_config',
                    'health',
                    'failed_jobs_summary',
                ],
            ]);
    }

    public function test_admin_can_get_supervisor_config(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('supervisor-config-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/queues/supervisor-config')
            ->assertOk()
            ->assertJsonStructure(['data' => ['profile', 'config', 'install_path']]);
    }
}
