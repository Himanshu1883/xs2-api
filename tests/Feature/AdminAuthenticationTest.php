<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Production migrations deliberately do not own the shared identity
        // table. Provision its contract only in this isolated test database.
        $this->createSharedUsersTable();
    }

    public function test_an_admin_can_log_in_and_use_admin_routes(): void
    {
        config([
            'provider-auth.store_id' => 13,
            'provider-auth.token_ttl_minutes' => 30,
        ]);
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create([
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $login->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', $user->display_name)
            ->assertJsonPath('data.expires_at', now()->addMinutes(30)->toIso8601String());

        $token = $login->json('data.token');
        [, $plainTextToken] = explode('|', $token, 2);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'token' => hash('sha256', $plainTextToken),
        ]);
        $this->assertTrue(
            PersonalAccessToken::query()->firstOrFail()->expires_at->equalTo(now()->addMinutes(30)),
        );

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings')
            ->assertOk();
    }

    public function test_an_admin_from_another_store_cannot_log_in(): void
    {
        config(['provider-auth.store_id' => 13]);

        $user = User::factory()->create([
            'store_id' => 99,
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_inactive_admin_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'status' => 0,
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_soft_deleted_admin_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);
        $user->delete();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_admin_requiring_two_factor_authentication_is_not_issued_a_token(): void
    {
        $user = User::factory()->create([
            'two_factor_enabled' => true,
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_non_admin_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'user_type' => 16,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create([
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_a_non_admin_token_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create(['user_type' => 16]);
        $token = $user->createToken('non-admin-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings')
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');
    }

    public function test_an_admin_token_loses_access_when_the_shared_identity_is_deactivated(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('admin-test', ['*'], now()->addHour())->plainTextToken;

        $user->update(['status' => 0]);
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings')
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');
    }

    public function test_an_expired_admin_token_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('expired-admin-test', ['*'], now()->subMinute())->plainTextToken;

        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create([
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.')
            ->assertJsonStructure(['data']);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Sanctum guards are cached between test requests; a real HTTP request
        // starts with a fresh guard instance.
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings')
            ->assertUnauthorized();
    }

    public function test_authenticated_admin_can_fetch_session_profile(): void
    {
        config([
            'provider-auth.store_id' => 13,
            'provider-auth.token_ttl_minutes' => 30,
        ]);
        $this->travelTo(now()->startOfSecond());

        $user = User::factory()->create([
            'user_type' => 6,
            'password' => Hash::make('correct-password'),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->json('data.token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('message', 'Authenticated.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', $user->display_name)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.expires_at', now()->addMinutes(30)->toIso8601String());
    }
}
