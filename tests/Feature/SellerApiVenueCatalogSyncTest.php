<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SellerApi\SellerVenueCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class SellerApiVenueCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSharedUsersTable();

        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.base_url', 'https://externalapi.test');
        config()->set('services.seller_api.api_key', 'test-bearer-token');
        config()->set('services.seller_api.venues_endpoint', '/api/venues');
        config()->set('seller-api.venues_endpoint', '/api/venues');
        config()->set('seller-api.catalog_per_page', 100);

        Schema::dropIfExists('stadium_details');
        Schema::dropIfExists('stadium_seats');
        Schema::dropIfExists('stadium');

        Schema::create('stadium', function (Blueprint $table): void {
            $table->integer('s_id')->primary();
            $table->integer('stadium_type')->default(1);
            $table->string('stadium_image')->nullable();
            $table->string('stadium_name')->nullable();
            $table->integer('country')->nullable();
            $table->integer('city')->nullable();
            $table->string('width')->default('');
            $table->string('height')->default('');
            $table->string('main_team')->default('');
            $table->text('map_code');
            $table->string('status')->default('1');
            $table->string('attendee_status')->default('0');
            $table->string('create_date')->default('');
            $table->text('stadium_name_ar');
            $table->string('source_type')->nullable();
            $table->string('category')->nullable();
        });

        Schema::create('stadium_seats', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('seat_category');
            $table->string('category_color')->nullable();
            $table->string('status');
            $table->string('create_date');
            $table->string('event_type')->default('match');
            $table->string('source_type')->default('1boxoffice');
        });

        Schema::create('stadium_details', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('stadium_id');
            $table->string('full_block_name')->nullable();
            $table->string('block_id');
            $table->integer('category')->nullable();
            $table->string('block_color');
            $table->integer('match_id')->nullable();
            $table->string('active_color')->nullable();
            $table->string('source_type')->nullable();
        });
    }

    public function test_sync_persists_venue_category_and_section(): void
    {
        Http::fake([
            'https://externalapi.test/api/venues*' => Http::response([
                'data' => [[
                    's_id' => 1580,
                    'venue_id' => 'abc',
                    'name' => 'Hollywood Palladium',
                    'venue_image' => 'https://example.test/map.svg',
                    'blocks' => [[
                        'id' => 1078395,
                        'category' => 2393,
                        'full_block_name' => 'Balcony-GA_Balcony-GA',
                        'block_color' => 'rgba(0,0,0,1)',
                    ]],
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $summary = app(SellerVenueCatalogSyncService::class)->sync();

        $this->assertSame(1, $summary['venues_created']);
        $this->assertSame(1, $summary['categories_created']);
        $this->assertSame(1, $summary['sections_created']);

        $this->assertDatabaseHas('stadium', [
            's_id' => 1580,
            'stadium_name' => 'Hollywood Palladium',
        ]);
        $this->assertDatabaseHas('stadium_seats', [
            'id' => 2393,
            'seat_category' => 'Balcony GA',
        ]);
        $this->assertDatabaseHas('stadium_details', [
            'id' => 1078395,
            'stadium_id' => 1580,
            'full_block_name' => 'Balcony-GA_Balcony-GA',
            'block_id' => 'Balcony-GA',
            'category' => 2393,
        ]);
    }

    public function test_admin_endpoint_runs_sync(): void
    {
        Http::fake([
            'https://externalapi.test/api/venues*' => Http::response([
                'data' => [[
                    's_id' => 42,
                    'venue_id' => 'v42',
                    'name' => 'Test Arena',
                    'venue_image' => '',
                    'blocks' => [[
                        'id' => 9001,
                        'category' => 77,
                        'full_block_name' => 'Longside_Block-1',
                        'block_color' => 'rgba(1,2,3,1)',
                    ]],
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $admin = User::factory()->create(['user_type' => 6]);
        $token = $admin->createToken('seller-venue-sync-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/seller-api/sync-venues')
            ->assertOk()
            ->assertJsonPath('data.venues_created', 1)
            ->assertJsonPath('data.categories_created', 1)
            ->assertJsonPath('data.sections_created', 1);
    }

    public function test_sync_venue_by_stadium_id_uses_direct_lookup(): void
    {
        Http::fake([
            'https://externalapi.test/api/venues?page=1&per_page=1&s_id=1580*' => Http::response([
                'data' => [[
                    's_id' => 1580,
                    'venue_id' => 'abc',
                    'name' => 'Hollywood Palladium',
                    'venue_image' => 'https://example.test/map.svg',
                    'blocks' => [[
                        'id' => 1078395,
                        'category' => 2393,
                        'full_block_name' => 'Balcony-GA_Balcony-GA',
                        'block_color' => 'rgba(0,0,0,1)',
                    ]],
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $summary = app(SellerVenueCatalogSyncService::class)->syncVenueByStadiumId(1580);

        $this->assertTrue($summary['found']);
        $this->assertSame(1, $summary['venues_created']);
        Http::assertSentCount(1);
    }
}
