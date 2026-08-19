<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminEventCatalogReferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['personal_access_tokens', 'stadium', 'cities', 'teams', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('user_type')->nullable();
            $table->unsignedInteger('store_id')->default(13);
            $table->boolean('two_factor_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('team_name');
            $table->boolean('status')->default(true);
        });
        Schema::create('cities', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('status')->default(true);
        });
        Schema::create('stadium', function (Blueprint $table): void {
            $table->increments('s_id');
            $table->string('stadium_name');
            $table->unsignedInteger('city');
            $table->boolean('status')->default(true);
        });
    }

    public function test_admin_can_load_safe_local_reference_options_for_event_creation(): void
    {
        DB::table('teams')->insert([
            ['id' => 10, 'team_name' => 'Alpha FC', 'status' => true],
            ['id' => 11, 'team_name' => 'Archived Alpha', 'status' => false],
        ]);
        DB::table('cities')->insert([
            ['id' => 20, 'name' => 'London', 'status' => true],
            ['id' => 21, 'name' => 'Liverpool', 'status' => true],
        ]);
        DB::table('stadium')->insert([
            ['s_id' => 30, 'stadium_name' => 'Alpha Stadium', 'city' => 20, 'status' => true],
            ['s_id' => 31, 'stadium_name' => 'Other Stadium', 'city' => 21, 'status' => true],
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('admin-reference-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/events/teams?search=Alpha')
            ->assertOk()
            ->assertJsonPath('data.0.id', 10)
            ->assertJsonPath('data.0.name', 'Alpha FC')
            ->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('/api/admin/events/cities?search=Lon')
            ->assertOk()
            ->assertJsonPath('data.0.id', 20)
            ->assertJsonPath('data.0.name', 'London');

        $this->withToken($token)
            ->getJson('/api/admin/events/venues?city_id=20&search=Alpha')
            ->assertOk()
            ->assertJsonPath('data.0.id', 30)
            ->assertJsonPath('data.0.name', 'Alpha Stadium')
            ->assertJsonCount(1, 'data');
    }
}
