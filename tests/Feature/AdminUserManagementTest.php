<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSharedUsersTable();

        config([
            'provider-auth.store_id' => 13,
        ]);
    }

    public function test_admin_can_list_users_with_search(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        User::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Admin',
            'email' => 'alice@example.com',
            'user_type' => User::ADMIN_USER_TYPE,
        ]);
        User::factory()->create([
            'first_name' => 'Bob',
            'last_name' => 'Builder',
            'email' => 'bob@example.com',
            'user_type' => 1,
        ]);
        User::factory()->create([
            'store_id' => 99,
            'email' => 'other-store@example.com',
        ]);

        $this->withToken($token)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonFragment(['email' => 'alice@example.com'])
            ->assertJsonFragment(['email' => 'bob@example.com'])
            ->assertJsonMissing(['email' => 'other-store@example.com']);

        $this->withToken($token)
            ->getJson('/api/admin/users?search=alice')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'alice@example.com');
    }

    public function test_admin_can_create_user(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/admin/users', [
                'first_name' => 'New',
                'last_name' => 'Admin',
                'email' => 'new-admin@example.com',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'User created successfully.')
            ->assertJsonPath('data.email', 'new-admin@example.com')
            ->assertJsonPath('data.user_type', User::ADMIN_USER_TYPE)
            ->assertJsonPath('data.status', User::ACTIVE_STATUS);

        $created = User::query()->where('email', 'new-admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secret-password', $created->password));
        $this->assertSame(13, $created->store_id);
    }

    public function test_admin_can_update_user(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $target = User::factory()->create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'old@example.com',
            'status' => User::ACTIVE_STATUS,
            'user_type' => User::ADMIN_USER_TYPE,
        ]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/admin/users/{$target->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Person',
                'email' => 'updated@example.com',
                'status' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User updated successfully.')
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.email', 'updated@example.com')
            ->assertJsonPath('data.status', 0);
    }

    public function test_admin_can_change_user_password(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $target = User::factory()->create([
            'password' => Hash::make('old-password'),
            'user_type' => User::ADMIN_USER_TYPE,
        ]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/admin/users/{$target->id}/password", [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');

        $target->refresh();
        $this->assertTrue(Hash::check('new-password', $target->password));
    }

    public function test_admin_cannot_manage_users_from_another_store(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $foreignUser = User::factory()->create(['store_id' => 99]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/admin/users/{$foreignUser->id}", [
                'first_name' => 'Blocked',
            ])
            ->assertNotFound();

        $this->withToken($token)
            ->putJson("/api/admin/users/{$foreignUser->id}/password", [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson("/api/admin/users/{$foreignUser->id}")
            ->assertNotFound();
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $user = User::factory()->create(['user_type' => 1]);
        $token = $user->createToken('regular-user')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/users')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/admin/users', [
                'first_name' => 'Blocked',
                'last_name' => 'User',
                'email' => 'blocked@example.com',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
            ])
            ->assertForbidden();
    }

    public function test_create_user_requires_valid_payload(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/users', [
                'first_name' => 'Duplicate',
                'last_name' => 'User',
                'email' => $admin->email,
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_admin_can_delete_user(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $target = User::factory()->create([
            'user_type' => User::ADMIN_USER_TYPE,
        ]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('message', 'User deleted successfully.');

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_other_admin_when_multiple_exist(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $otherAdmin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/admin/users/{$otherAdmin->id}")
            ->assertOk()
            ->assertJsonPath('message', 'User deleted successfully.');

        $this->assertSoftDeleted('users', ['id' => $otherAdmin->id]);
        $this->assertSame(1, User::query()->where('user_type', User::ADMIN_USER_TYPE)->count());
    }

    public function test_admin_cannot_delete_user_from_another_store(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $foreignUser = User::factory()->create(['store_id' => 99]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/admin/users/{$foreignUser->id}")
            ->assertNotFound();
    }

    public function test_update_user_requires_unique_email(): void
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);
        $target = User::factory()->create([
            'email' => 'target@example.com',
            'user_type' => User::ADMIN_USER_TYPE,
        ]);
        User::factory()->create([
            'email' => 'taken@example.com',
            'user_type' => User::ADMIN_USER_TYPE,
        ]);
        $token = $admin->createToken('admin-users')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/admin/users/{$target->id}", [
                'email' => 'taken@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
