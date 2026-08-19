<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SeatsbrokerCatalogId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class SellerApiEventImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSharedUsersTable();

        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.base_url', 'https://externalapi.test');
        config()->set('services.seller_api.api_key', 'test-bearer-token');
        config()->set('services.seller_api.events_endpoint', '/api/events');
        config()->set('services.seller_api.venues_endpoint', '/api/venues');
        config()->set('seller-api.events_endpoint', '/api/events');
        config()->set('seller-api.venues_endpoint', '/api/venues');
        config()->set('seller-api.catalog_per_page', 100);
        config()->set('seller-api.catalog_sandbox_base_url', 'https://sandbox-externalapi.test');
        config()->set('seller-api.catalog_production_base_url', 'https://externalapi.test');
        config()->set('cache.default', 'array');

        foreach (['stadium_details', 'stadium_seats', 'stadium', 'match_info', 'teams', 'tournament', 'game_category', 'cities', 'countries'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('countries', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('sortname')->nullable();
            $table->string('name');
            $table->integer('phonecode')->default(0);
            $table->integer('add_by')->default(0);
            $table->string('create_date')->default('');
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('state_id')->default(0);
            $table->integer('add_by')->default(0);
            $table->string('create_date')->default('');
        });

        Schema::create('game_category', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('category_name');
            $table->integer('parent_cat_id')->default(0);
            $table->string('image')->nullable();
            $table->string('create_date')->nullable();
            $table->integer('status')->default(1);
            $table->integer('store_id')->nullable();
            $table->integer('add_by')->default(0);
        });

        Schema::create('tournament', function (Blueprint $table): void {
            $table->increments('t_id');
            $table->string('tournament_name');
            $table->string('status')->default('1');
            $table->string('create_date')->nullable();
            $table->string('popular_tournament')->default('0');
            $table->integer('sort_by')->default(0);
            $table->integer('show_in_list')->default(1);
            $table->string('attendee_status')->default('0');
            $table->integer('category')->nullable();
            $table->string('source_type')->nullable();
            $table->integer('sitemap_status')->default(0);
            $table->integer('show_on_footer')->default(0);
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('team_name');
            $table->string('category')->nullable();
            $table->string('team_image')->nullable();
            $table->string('create_date')->nullable();
            $table->integer('status')->default(1);
            $table->integer('show_status')->default(1);
            $table->integer('store_id')->nullable();
            $table->string('source_type')->nullable();
            $table->integer('sitemap_status')->default(0);
            $table->integer('show_on_footer')->default(0);
        });

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

        Schema::create('match_info', function (Blueprint $table): void {
            $table->integer('m_id')->primary();
            $table->string('match_name');
            $table->string('extra_title')->nullable();
            $table->string('team_1')->nullable();
            $table->string('team_2')->nullable();
            $table->string('hometown')->nullable();
            $table->string('tournament')->nullable();
            $table->string('slug')->nullable();
            $table->string('status')->default('1');
            $table->string('availability')->nullable();
            $table->string('matchticket')->nullable();
            $table->string('daysremaining')->nullable();
            $table->string('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('hot_tickets')->nullable();
            $table->dateTime('match_date');
            $table->string('match_time')->nullable();
            $table->unsignedInteger('venue')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('create_date')->nullable();
            $table->string('event_type')->nullable();
            $table->string('price_type')->nullable();
            $table->unsignedInteger('store_id')->nullable();
            $table->string('xs2event_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('category')->nullable();
            $table->integer('tixstock_status')->nullable();
            $table->integer('oneclicket_status')->nullable();
            $table->integer('xs2event_status')->nullable();
            $table->integer('oneboxoffice_status')->nullable();
            $table->integer('upcoming_events')->default(0);
            $table->string('url_key')->default('');
            $table->integer('request')->default(0);
            $table->integer('epl_status')->default(0);
            $table->integer('confirm_status')->default(0);
            $table->integer('affiliate_status')->default(0);
            $table->tinyInteger('show_match_name')->default(0);
        });
    }

    public function test_search_fetches_events_by_name_from_catalog_api(): void
    {
        Http::fake([
            'https://externalapi.test/api/events*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame('Alaves', $query['event_name'] ?? null);
                $this->assertSame('10', (string) ($query['limit'] ?? ''));
                $this->assertSame('en', $query['lang'] ?? null);
                $this->assertSame('1', (string) ($query['page'] ?? ''));

                return Http::response([
                    'data' => [
                        $this->catalogEvent(10624, 'Alaves vs Getafe', 'Alaves', 'Getafe', 'La Liga'),
                    ],
                    'meta' => ['current_page' => 1, 'last_page' => 1],
                ]);
            },
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/seller-api/events/search?q=Alaves&environment=production&limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.match_name', 'Alaves vs Getafe')
            ->assertJsonPath('data.0.already_exists', false)
            ->assertJsonPath('meta.environment', 'production')
            ->assertJsonPath(
                'meta.request_url',
                'https://externalapi.test/api/events?page=1&limit=10&lang=en&event_name=Alaves',
            )
            ->assertJsonStructure([
                'meta' => ['environment', 'request_url', 'seller_api_debug'],
            ])
            ->assertJsonCount(1, 'data');
    }

    public function test_search_by_tournament_fetches_catalog_events(): void
    {
        $tournamentId = 10;
        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);

        Http::fake([
            'https://externalapi.test/api/events*' => function ($request) use ($catalogTournamentId) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame($catalogTournamentId, $query['tournament_id'] ?? null);
                $this->assertSame('1', (string) ($query['page'] ?? ''));
                $this->assertSame('100', (string) ($query['per_page'] ?? ''));

                return Http::response([
                    'data' => [
                        $this->catalogEvent(10624, 'Alaves vs Getafe', 'Alaves', 'Getafe', 'La Liga'),
                    ],
                    'meta' => ['current_page' => 1, 'last_page' => 2],
                ]);
            },
        ]);

        DB::table('tournament')->insert([
            't_id' => $tournamentId,
            'tournament_name' => 'La Liga',
            'status' => '1',
            'create_date' => (string) time(),
            'popular_tournament' => '0',
            'sort_by' => 0,
            'show_in_list' => 1,
            'attendee_status' => '0',
            'category' => 1,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/seller-api/events/search-by-tournament?tournament_id=10&environment=production&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.0.match_name', 'Alaves vs Getafe')
            ->assertJsonPath('meta.tournament_id', 10)
            ->assertJsonPath('meta.tournament_name', 'La Liga')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.environment', 'production')
            ->assertJsonPath(
                'meta.request_url',
                'https://externalapi.test/api/events?page=1&per_page=100&tournament_id='.$catalogTournamentId,
            )
            ->assertJsonCount(1, 'data');
    }

    public function test_import_creates_event_and_related_rows_idempotently(): void
    {
        $eventId = SeatsbrokerCatalogId::hash(11411);
        $stadiumId = 1580;
        $teamA = 9001;
        $tournamentId = 77;

        Http::fake([
            'https://externalapi.test/api/events*event_id*' => Http::response([
                'data' => [[
                    'event_id' => $eventId,
                    'tournament_id' => SeatsbrokerCatalogId::hash($tournamentId),
                    'stadium_id' => SeatsbrokerCatalogId::hash($stadiumId),
                    'team_image_a' => 'https://example.test/poppy.jpg',
                    'team_image_b' => null,
                    'match_name' => 'Poppy Hollywood',
                    'team_name_a' => 'Poppy',
                    'team_name_b' => null,
                    'team_id_a' => SeatsbrokerCatalogId::hash($teamA),
                    'team_id_b' => null,
                    'match_date' => '2026-08-08 01:00:00',
                    'match_time' => '01:00',
                    'event_type' => 'other',
                    'category_name' => 'Other Events',
                    'tournament_name' => 'Pop Tour',
                    'stadium_name' => 'Hollywood Palladium',
                    'stadium_image' => 'https://example.test/map.svg',
                    'country_name' => 'United States',
                    'city_name' => 'Los Angeles',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
            'https://externalapi.test/api/venues*' => Http::response([
                'data' => [[
                    's_id' => $stadiumId,
                    'venue_id' => SeatsbrokerCatalogId::hash($stadiumId),
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

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/seller-api/events/import', [
                'event_id' => $eventId,
                'environment' => 'production',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.m_id', 11411)
            ->assertJsonPath('meta.environment', 'production')
            ->assertJsonStructure([
                'meta' => ['environment', 'request_url', 'seller_api_debug'],
            ]);

        $this->assertDatabaseHas('match_info', [
            'm_id' => 11411,
            'match_name' => 'Poppy Hollywood',
            'team_1' => (string) $teamA,
            'tournament' => (string) $tournamentId,
            'venue' => $stadiumId,
            'upcoming_events' => 0,
        ]);
        $this->assertDatabaseHas('teams', ['id' => $teamA, 'team_name' => 'Poppy']);
        $this->assertDatabaseHas('tournament', ['t_id' => $tournamentId, 'tournament_name' => 'Pop Tour']);
        $this->assertDatabaseHas('game_category', ['category_name' => 'Other Events']);
        $this->assertDatabaseHas('stadium', ['s_id' => $stadiumId, 'stadium_name' => 'Hollywood Palladium']);
        $this->assertDatabaseHas('stadium_seats', ['id' => 2393]);
        $this->assertDatabaseHas('stadium_details', ['id' => 1078395, 'stadium_id' => $stadiumId]);

        $this->withToken($token)
            ->postJson('/api/admin/seller-api/events/import', [
                'event_id' => $eventId,
                'environment' => 'production',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'already_exists');
    }

    public function test_import_normalizes_doubled_team_image_urls(): void
    {
        $eventId = SeatsbrokerCatalogId::hash(11412);
        $stadiumId = 1581;
        $teamA = 9002;
        $innerUrl = 'https://upload.wikimedia.org/wikipedia/en/thumb/8/8a/Arsenal_FC.svg/1200px-Arsenal_FC.svg.png';
        $doubledUrl = 'https://media.seatsbrokers.com/backend-uploads/teams/'.$innerUrl;

        Http::fake([
            'https://externalapi.test/api/events*event_id*' => Http::response([
                'data' => [[
                    'event_id' => $eventId,
                    'tournament_id' => SeatsbrokerCatalogId::hash(78),
                    'stadium_id' => SeatsbrokerCatalogId::hash($stadiumId),
                    'team_image_a' => $doubledUrl,
                    'team_image_b' => null,
                    'match_name' => 'Arsenal Test Event',
                    'team_name_a' => 'Arsenal',
                    'team_name_b' => null,
                    'team_id_a' => SeatsbrokerCatalogId::hash($teamA),
                    'team_id_b' => null,
                    'match_date' => '2026-08-08 01:00:00',
                    'match_time' => '01:00',
                    'event_type' => 'match',
                    'category_name' => 'Football',
                    'tournament_name' => 'Premier League',
                    'stadium_name' => 'Emirates Stadium',
                    'stadium_image' => null,
                    'country_name' => 'United Kingdom',
                    'city_name' => 'London',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
            'https://externalapi.test/api/venues*' => Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/seller-api/events/import', [
                'event_id' => $eventId,
                'environment' => 'production',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('teams', [
            'id' => $teamA,
            'team_name' => 'Arsenal',
            'team_image' => $innerUrl,
        ]);
    }

    public function test_bulk_sync_preview_returns_request_urls_for_both_environments(): void
    {
        $tournamentId = 10;
        DB::table('tournament')->insert([
            't_id' => $tournamentId,
            'tournament_name' => 'La Liga',
            'status' => '1',
            'create_date' => (string) time(),
            'popular_tournament' => '0',
            'sort_by' => 0,
            'show_in_list' => 1,
            'attendee_status' => '0',
            'category' => 1,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ]);

        $token = $this->adminToken();
        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);

        $this->withToken($token)
            ->getJson("/api/admin/seller-api/events/bulk-sync/preview?tournament_id={$tournamentId}")
            ->assertOk()
            ->assertJsonPath('data.tournament_id', $tournamentId)
            ->assertJsonPath('data.tournament_name', 'La Liga')
            ->assertJsonPath('data.catalog_tournament_id', $catalogTournamentId)
            ->assertJsonPath('data.default_environment', 'production')
            ->assertJsonPath(
                'data.request_urls.sandbox',
                'https://sandbox-externalapi.test/api/events?page=1&per_page=100&tournament_id='.$catalogTournamentId,
            )
            ->assertJsonPath(
                'data.request_urls.production',
                'https://externalapi.test/api/events?page=1&per_page=100&tournament_id='.$catalogTournamentId,
            );
    }

    public function test_bulk_sync_preview_uses_integration_settings_catalog_urls(): void
    {
        app(\App\Services\Admin\IntegrationSettingService::class)->set(
            \App\Services\Admin\IntegrationSettingService::SELLER_CATALOG_SANDBOX_BASE_URL,
            'https://sandbox-from-settings.test',
        );
        app(\App\Services\Admin\IntegrationSettingService::class)->set(
            \App\Services\Admin\IntegrationSettingService::SELLER_CATALOG_PRODUCTION_BASE_URL,
            'https://production-from-settings.test',
        );

        $tournamentId = 11;
        DB::table('tournament')->insert([
            't_id' => $tournamentId,
            'tournament_name' => 'Premier League',
            'status' => '1',
            'create_date' => (string) time(),
            'popular_tournament' => '0',
            'sort_by' => 0,
            'show_in_list' => 1,
            'attendee_status' => '0',
            'category' => 1,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ]);

        $token = $this->adminToken();
        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);

        $this->withToken($token)
            ->getJson("/api/admin/seller-api/events/bulk-sync/preview?tournament_id={$tournamentId}")
            ->assertOk()
            ->assertJsonPath(
                'data.request_urls.sandbox',
                'https://sandbox-from-settings.test/api/events?page=1&per_page=100&tournament_id='.$catalogTournamentId,
            )
            ->assertJsonPath(
                'data.request_urls.production',
                'https://production-from-settings.test/api/events?page=1&per_page=100&tournament_id='.$catalogTournamentId,
            );
    }

    public function test_bulk_sync_skips_existing_and_imports_new_events_with_venue_creation(): void
    {
        $tournamentId = 10;
        $existingMId = 5001;
        $newMId = 5002;
        $existingStadiumId = 900;
        $newStadiumId = 901;
        $teamA = 8001;
        $teamB = 8002;

        DB::table('tournament')->insert([
            't_id' => $tournamentId,
            'tournament_name' => 'La Liga',
            'status' => '1',
            'create_date' => (string) time(),
            'popular_tournament' => '0',
            'sort_by' => 0,
            'show_in_list' => 1,
            'attendee_status' => '0',
            'category' => 1,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ]);

        DB::table('match_info')->insert([
            'm_id' => $existingMId,
            'match_name' => 'Existing Derby',
            'team_1' => (string) $teamA,
            'team_2' => (string) $teamB,
            'hometown' => (string) $teamA,
            'tournament' => (string) $tournamentId,
            'slug' => 'existing-derby-tickets',
            'status' => '1',
            'availability' => '1',
            'matchticket' => '1000',
            'daysremaining' => '0',
            'match_date' => '2026-09-01 18:00:00',
            'match_time' => '18:00',
            'venue' => $existingStadiumId,
            'create_date' => now()->format('Y-m-d H:i:s'),
            'event_type' => 'match',
            'price_type' => 'EUR',
            'store_id' => 13,
            'source_type' => '1boxoffice',
            'category' => '1',
            'tixstock_status' => 1,
            'oneclicket_status' => 1,
            'xs2event_status' => 1,
            'oneboxoffice_status' => 1,
        ]);

        DB::table('stadium')->insert([
            's_id' => $existingStadiumId,
            'stadium_type' => 1,
            'stadium_image' => null,
            'stadium_name' => 'Existing Ground',
            'country' => null,
            'city' => null,
            'width' => '',
            'height' => '',
            'main_team' => '',
            'map_code' => '',
            'status' => '1',
            'attendee_status' => '0',
            'create_date' => now()->format('Y-m-d H:i:s'),
            'stadium_name_ar' => '',
            'source_type' => '1boxoffice',
            'category' => '1',
        ]);

        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);
        $existingEvent = $this->fullCatalogEvent(
            $existingMId,
            'Existing Derby',
            $teamA,
            $teamB,
            $tournamentId,
            $existingStadiumId,
            'Existing Ground',
        );
        $newEvent = $this->fullCatalogEvent(
            $newMId,
            'New Cup Final',
            $teamA,
            $teamB,
            $tournamentId,
            $newStadiumId,
            'New Arena',
        );

        Http::fake([
            'https://externalapi.test/api/events*' => function ($request) use ($catalogTournamentId, $existingEvent, $newEvent) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame($catalogTournamentId, $query['tournament_id'] ?? null);

                return Http::response([
                    'data' => [$existingEvent, $newEvent],
                    'meta' => ['current_page' => 1, 'last_page' => 1],
                ]);
            },
            'https://externalapi.test/api/venues*' => Http::response([
                'data' => [[
                    's_id' => $newStadiumId,
                    'venue_id' => SeatsbrokerCatalogId::hash($newStadiumId),
                    'name' => 'New Arena',
                    'venue_image' => 'https://example.test/new-arena.svg',
                    'blocks' => [[
                        'id' => 2001,
                        'category' => 3001,
                        'full_block_name' => 'North-N1',
                        'block_color' => 'rgba(0,0,0,1)',
                    ]],
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ]),
        ]);

        $token = $this->adminToken();

        $status = $this->bulkSyncAndFetchStatus($tournamentId, 'production', $token);

        $status
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.environment', 'production')
            ->assertJsonPath('data.result.fetched', 2)
            ->assertJsonPath('data.result.created', 1)
            ->assertJsonPath('data.result.skipped', 1)
            ->assertJsonPath('data.result.failed', 0)
            ->assertJsonPath('data.result.created_events.0.m_id', $newMId);

        $this->assertDatabaseHas('match_info', [
            'm_id' => $existingMId,
            'match_name' => 'Existing Derby',
        ]);
        $this->assertDatabaseHas('match_info', [
            'm_id' => $newMId,
            'match_name' => 'New Cup Final',
            'venue' => $newStadiumId,
        ]);
        $this->assertDatabaseHas('stadium', ['s_id' => $newStadiumId, 'stadium_name' => 'New Arena']);
        $this->assertDatabaseHas('stadium_seats', ['id' => 3001]);
        $this->assertDatabaseHas('stadium_details', ['id' => 2001, 'stadium_id' => $newStadiumId]);
        $this->assertDatabaseHas('stadium', ['s_id' => $existingStadiumId, 'stadium_name' => 'Existing Ground']);
        $this->assertSame(1, DB::table('stadium')->where('s_id', $existingStadiumId)->count());
    }

    public function test_bulk_sync_returns_debug_details_when_catalog_request_fails(): void
    {
        $tournamentId = 10;
        DB::table('tournament')->insert([
            't_id' => $tournamentId,
            'tournament_name' => 'La Liga',
            'status' => '1',
            'create_date' => (string) time(),
            'popular_tournament' => '0',
            'sort_by' => 0,
            'show_in_list' => 1,
            'attendee_status' => '0',
            'category' => 1,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ]);

        $catalogTournamentId = SeatsbrokerCatalogId::hash($tournamentId);

        Http::fake([
            'https://externalapi.test/api/events*' => Http::response([
                'message' => 'Invalid bearer token.',
            ], 401),
        ]);

        $token = $this->adminToken();

        $status = $this->bulkSyncAndFetchStatus($tournamentId, 'production', $token);

        $status
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('message', 'Seller API catalog request failed with HTTP 401.')
            ->assertJsonPath('data.message', 'Seller API catalog request failed with HTTP 401.')
            ->assertJsonPath('data.debug.environment', 'production')
            ->assertJsonPath('data.debug.http_status', 401)
            ->assertJsonPath('data.debug.response_body.message', 'Invalid bearer token.')
            ->assertJsonPath(
                'data.debug.request_url',
                'https://externalapi.test/api/events?page=1&per_page=100&tournament_id='.$catalogTournamentId,
            )
            ->assertJsonStructure([
                'data' => [
                    'seller_api_debug' => [[
                        'operation',
                        'method',
                        'url',
                        'response_status',
                        'response_body',
                    ]],
                ],
            ]);
    }

    public function test_bulk_sync_returns_connection_debug_when_catalog_host_is_unreachable(): void
    {
        $tournamentId = 11;
        DB::table('tournament')->insert([
            't_id' => $tournamentId,
            'tournament_name' => 'Serie A',
            'status' => '1',
            'create_date' => (string) time(),
            'popular_tournament' => '0',
            'sort_by' => 0,
            'show_in_list' => 1,
            'attendee_status' => '0',
            'category' => 1,
            'source_type' => '1boxoffice',
            'sitemap_status' => 0,
            'show_on_footer' => 0,
        ]);

        Http::fake([
            'https://sandbox-externalapi.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host sandbox-externalapi.test.'),
        ]);

        $token = $this->adminToken();

        $status = $this->bulkSyncAndFetchStatus($tournamentId, 'sandbox', $token);

        $status
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.debug.environment', 'sandbox')
            ->assertJsonPath('data.debug.cause', 'Seller API catalog request could not connect: Could not resolve host sandbox-externalapi.test.')
            ->assertJsonStructure([
                'data' => [
                    'seller_api_debug' => [[
                        'error',
                        'url',
                    ]],
                ],
            ]);
    }

    public function test_bulk_sync_status_endpoint_returns_cached_run(): void
    {
        $state = \App\Services\SellerApi\SellerBulkEventSyncState::create(10, 'sandbox', [
            'tournament_name' => 'La Liga',
            'request_urls' => [
                'sandbox' => 'https://sandbox.test/events',
                'production' => 'https://production.test/events',
            ],
            'catalog_tournament_id' => 'abc',
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/seller-api/events/bulk-sync/'.$state['sync_id'])
            ->assertOk()
            ->assertJsonPath('data.sync_id', $state['sync_id'])
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_catalog_id_round_trips(): void
    {
        $this->assertSame('c4ca4238a0b923820dcc509a6f75849b', SeatsbrokerCatalogId::hash(1));
        $this->assertSame(1149, SeatsbrokerCatalogId::resolve('09c6c3783b4a70054da74f2538ed47c6'));
    }

    /** @return array<string, mixed> */
    private function fullCatalogEvent(
        int $mId,
        string $name,
        int $teamA,
        int $teamB,
        int $tournamentId,
        int $stadiumId,
        string $stadiumName,
    ): array {
        return [
            'event_id' => SeatsbrokerCatalogId::hash($mId),
            'tournament_id' => SeatsbrokerCatalogId::hash($tournamentId),
            'stadium_id' => SeatsbrokerCatalogId::hash($stadiumId),
            'team_id_a' => SeatsbrokerCatalogId::hash($teamA),
            'team_id_b' => SeatsbrokerCatalogId::hash($teamB),
            'match_name' => $name,
            'team_name_a' => 'Team A',
            'team_name_b' => 'Team B',
            'match_date' => '2026-09-10 20:00:00',
            'match_time' => '20:00',
            'event_type' => 'match',
            'category_name' => 'Football',
            'tournament_name' => 'La Liga',
            'stadium_name' => $stadiumName,
            'stadium_image' => 'https://example.test/stadium.svg',
            'country_name' => 'Spain',
            'city_name' => 'Madrid',
        ];
    }

    /** @return array<string, mixed> */
    private function catalogEvent(
        int $mId,
        string $name,
        string $teamA,
        ?string $teamB = null,
        ?string $tournament = null,
    ): array {
        return [
            'event_id' => SeatsbrokerCatalogId::hash($mId),
            'match_name' => $name,
            'team_name_a' => $teamA,
            'team_name_b' => $teamB,
            'tournament_name' => $tournament,
            'match_date' => '2026-08-16 17:00:00',
            'stadium_name' => 'Test Stadium',
            'category_name' => 'Football',
        ];
    }

    private function bulkSyncAndFetchStatus(int $tournamentId, string $environment, string $token): \Illuminate\Testing\TestResponse
    {
        $queued = $this->withToken($token)
            ->postJson('/api/admin/seller-api/events/bulk-sync', [
                'tournament_id' => $tournamentId,
                'environment' => $environment,
            ])
            ->assertJsonStructure(['data' => ['sync_id', 'status']]);

        $syncId = (string) $queued->json('data.sync_id');
        $this->assertNotSame('', $syncId);

        $status = (string) $queued->json('data.status');
        if (in_array($status, ['completed', 'failed'], true)) {
            return $queued->assertOk();
        }

        return $this->withToken($token)
            ->getJson('/api/admin/seller-api/events/bulk-sync/'.$syncId)
            ->assertOk();
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => 6]);

        return $admin->createToken('seller-event-import-test')->plainTextToken;
    }
}
