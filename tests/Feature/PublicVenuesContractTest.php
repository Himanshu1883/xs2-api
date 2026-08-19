<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicVenuesContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'xs2_category_mapping_details',
            'xs2_category_mappings',
            'xs2_category_contexts',
            'xs2_categories',
            'event_mappings',
            'xs2_events',
            'xs2_stadium_mappings',
            'xs2_venues',
            'match_info',
            'stadium_details',
            'stadium_seats',
            'api_stadium',
            'stadium',
            'teams',
            'tournament',
            'game_category',
            'cities',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('cities', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('team_name');
        });

        Schema::create('tournament', function (Blueprint $table): void {
            $table->increments('t_id');
            $table->string('tournament_name');
            $table->unsignedInteger('category')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
        });

        Schema::create('game_category', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('category_name');
            $table->unsignedTinyInteger('status')->default(1);
        });

        Schema::create('stadium', function (Blueprint $table): void {
            $table->increments('s_id');
            $table->string('stadium_name');
            $table->unsignedInteger('city')->nullable();
        });

        Schema::create('api_stadium', function (Blueprint $table): void {
            $table->increments('stadium_id');
            $table->string('stadium_name');
        });

        Schema::create('stadium_seats', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('seat_category');
        });

        Schema::create('stadium_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('stadium_id');
            $table->unsignedInteger('category')->nullable();
            $table->string('full_block_name')->nullable();
            $table->string('block_id')->nullable();
        });

        Schema::create('match_info', function (Blueprint $table): void {
            $table->increments('m_id');
            $table->unsignedInteger('venue')->nullable();
            $table->string('match_name')->nullable();
            $table->unsignedInteger('team_1')->nullable();
            $table->unsignedInteger('team_2')->nullable();
            $table->unsignedInteger('city')->nullable();
            $table->unsignedInteger('category')->nullable();
            $table->unsignedInteger('tournament')->nullable();
            $table->dateTime('match_date')->nullable();
        });

        Schema::create('xs2_venues', function (Blueprint $table): void {
            $table->id();
            $table->string('external_venue_id')->unique();
            $table->string('venue_name')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_stadium_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_venue_id');
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->string('status')->default('unmatched');
            $table->timestamps();
        });

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('event_name')->nullable();
            $table->string('sport_type')->nullable();
            $table->string('event_status')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('tournament_name')->nullable();
            $table->dateTime('date_start_local')->nullable();
            $table->timestamps();
        });

        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->unsignedInteger('m_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('xs2_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id')->nullable();
            $table->string('external_category_id');
            $table->string('external_event_id')->nullable();
            $table->string('category_name')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_category_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->unique();
            $table->string('external_venue_id')->nullable();
            $table->string('category_type')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id');
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('xs2_category_mapping_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_mapping_id');
            $table->unsignedBigInteger('stadium_detail_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('block')->nullable();
            $table->string('section')->nullable();
            $table->string('name')->nullable();
            $table->string('stadium_seat_name')->nullable();
            $table->timestamps();
        });

        DB::table('cities')->insert(['id' => 10, 'name' => 'Milan']);
        DB::table('game_category')->insert([
            ['id' => 1, 'category_name' => 'Football', 'status' => 1],
            ['id' => 2, 'category_name' => 'Concert', 'status' => 1],
        ]);
        DB::table('teams')->insert([
            ['id' => 1, 'team_name' => 'Home FC'],
            ['id' => 2, 'team_name' => 'Away United'],
        ]);
        DB::table('tournament')->insert([
            ['t_id' => 7, 'tournament_name' => 'Serie A', 'category' => 1, 'status' => 1],
            ['t_id' => 8, 'tournament_name' => 'World Tour', 'category' => 2, 'status' => 1],
        ]);
        DB::table('stadium')->insert([
            ['s_id' => 1, 'stadium_name' => 'San Siro', 'city' => 10],
            ['s_id' => 2, 'stadium_name' => 'Legacy Park', 'city' => null],
        ]);
        DB::table('api_stadium')->insert([
            ['stadium_id' => 3, 'stadium_name' => 'Catalog Arena'],
            ['stadium_id' => 1, 'stadium_name' => 'Duplicate Id Row'],
        ]);
        DB::table('stadium_seats')->insert([
            ['id' => 11, 'seat_category' => 'Longside'],
            ['id' => 22, 'seat_category' => 'Shortside'],
            ['id' => 33, 'seat_category' => 'Block Category'],
        ]);
        DB::table('stadium_details')->insert([
            ['id' => 101, 'stadium_id' => 1, 'category' => 11, 'full_block_name' => 'Longside_1', 'block_id' => '1'],
            ['id' => 102, 'stadium_id' => 1, 'category' => 11, 'full_block_name' => 'Longside_2', 'block_id' => '2'],
            ['id' => 103, 'stadium_id' => 1, 'category' => 22, 'full_block_name' => 'Shortside_1', 'block_id' => '1'],
            ['id' => 104, 'stadium_id' => 2, 'category' => 33, 'full_block_name' => 'Block_A', 'block_id' => 'A'],
        ]);
        $derbyAt = now()->addDays(25)->setTime(20, 0, 0);
        $cupAt = now()->addDays(55)->setTime(18, 0, 0);
        $friendlyAt = now()->addDays(8)->setTime(15, 0, 0);

        DB::table('match_info')->insert([
            ['m_id' => 1, 'venue' => 1, 'match_name' => 'Derby', 'team_1' => 1, 'team_2' => 2, 'city' => 10, 'category' => 1, 'tournament' => 7, 'match_date' => $derbyAt->format('Y-m-d H:i:s')],
            ['m_id' => 2, 'venue' => 1, 'match_name' => 'Cup Final', 'team_1' => 1, 'team_2' => 2, 'city' => 10, 'category' => 1, 'tournament' => 7, 'match_date' => $cupAt->format('Y-m-d H:i:s')],
            ['m_id' => 3, 'venue' => 2, 'match_name' => 'Friendly', 'team_1' => null, 'team_2' => null, 'city' => null, 'category' => 2, 'tournament' => 8, 'match_date' => $friendlyAt->format('Y-m-d H:i:s')],
        ]);

        DB::table('xs2_venues')->insert([
            'id' => 50,
            'external_venue_id' => 'xs2-san-siro',
            'venue_name' => 'Giuseppe Meazza',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('xs2_stadium_mappings')->insert([
            'id' => 80,
            'xs2_venue_id' => 50,
            'stadium_id' => 1,
            'status' => 'mapped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('xs2_events')->insert([
            'id' => 90,
            'external_event_id' => 'xs2-event-1',
            'event_name' => 'XS2 Derby Night',
            'sport_type' => 'Football',
            'event_status' => 'active',
            'venue_name' => 'Giuseppe Meazza',
            'tournament_name' => 'Serie A',
            'date_start_local' => now()->addDays(25)->setTime(20, 0, 0)->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('event_mappings')->insert([
            'xs2_event_id' => 90,
            'm_id' => 1,
            'status' => 'mapped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('xs2_categories')->insert([
            'id' => 70,
            'xs2_event_id' => 90,
            'external_category_id' => 'cat-longside',
            'external_event_id' => 'xs2-event-1',
            'category_name' => 'Longside_Upper',
            'raw_payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('xs2_category_contexts')->insert([
            'xs2_category_id' => 70,
            'external_venue_id' => 'xs2-san-siro',
            'category_type' => 'seated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('xs2_category_mappings')->insert([
            'id' => 60,
            'xs2_category_id' => 70,
            'xs2_stadium_mapping_id' => 80,
            'stadium_id' => 1,
            'stadium_seat_id' => 11,
            'status' => 'mapped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('xs2_category_mapping_details')->insert([
            'xs2_category_mapping_id' => 60,
            'stadium_detail_id' => 101,
            'stadium_seat_id' => 11,
            'block' => '1',
            'section' => 'Upper',
            'name' => 'Longside Upper 1',
            'stadium_seat_name' => 'Longside',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_public_venues_lists_stadium_and_api_catalog_rows(): void
    {
        // Catalog Arena has no future events, so it is excluded by default.
        $this->getJson('/api/venues?per_page=100&sort=name')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => 1, 'name' => 'San Siro', 'city' => 'Milan', 'source' => 'stadium'])
            ->assertJsonFragment(['id' => 2, 'name' => 'Legacy Park', 'city' => null, 'source' => 'stadium'])
            ->assertJsonMissing(['name' => 'Catalog Arena']);
    }

    public function test_public_venues_support_search(): void
    {
        $this->getJson('/api/venues?search=San%20Siro')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'San Siro');

        $this->getJson('/api/venues?search=Catalog')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_public_venues_include_per_venue_stats_and_xs2_mapping(): void
    {
        $response = $this->getJson('/api/venues?per_page=100&sort=id')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame([
            'id' => 1,
            'name' => 'San Siro',
            'city' => 'Milan',
            'city_id' => 10,
            'source' => 'stadium',
            'event_count' => 2,
            'category_count' => 2,
            'section_count' => 3,
            'xs2_mapped' => true,
            'xs2_venue_id' => 'xs2-san-siro',
            'xs2_venue_name' => 'Giuseppe Meazza',
        ], $byId->get(1));

        $this->assertSame([
            'id' => 2,
            'name' => 'Legacy Park',
            'city' => null,
            'city_id' => null,
            'source' => 'stadium',
            'event_count' => 1,
            'category_count' => 1,
            'section_count' => 1,
            'xs2_mapped' => false,
            'xs2_venue_id' => null,
            'xs2_venue_name' => null,
        ], $byId->get(2));

        $this->assertNull($byId->get(3));
    }

    public function test_public_venue_events_include_xs2_mapping_when_present(): void
    {
        $response = $this->getJson('/api/venues/1/events')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.venue.id', 1)
            ->assertJsonPath('meta.venue.xs2_mapped', true);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId->get(1)['xs2_mapped']);
        $this->assertSame('XS2 Derby Night', $byId->get(1)['xs2_event']['name']);
        $this->assertSame('xs2-event-1', $byId->get(1)['xs2_event']['external_event_id']);
        $this->assertFalse($byId->get(2)['xs2_mapped']);
        $this->assertNull($byId->get(2)['xs2_event']);
    }

    public function test_public_venue_categories_and_sections_include_xs2_mapping(): void
    {
        $categories = $this->getJson('/api/venues/1/categories')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data');

        $longside = collect($categories)->firstWhere('stadium_seat_id', 11);
        $this->assertSame('Longside', $longside['name']);
        $this->assertTrue($longside['xs2_mapped']);
        $this->assertSame('cat-longside', $longside['xs2_categories'][0]['external_category_id']);
        $this->assertSame('Longside', $longside['xs2_categories'][0]['name']);
        $this->assertSame('Upper', $longside['xs2_categories'][0]['section']);

        $shortside = collect($categories)->firstWhere('stadium_seat_id', 22);
        $this->assertFalse($shortside['xs2_mapped']);

        $sections = $this->getJson('/api/venues/1/sections')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->json('data');

        $mappedSection = collect($sections)->firstWhere('stadium_detail_id', 101);
        $this->assertTrue($mappedSection['xs2_mapped']);
        $this->assertSame('Longside Upper 1', $mappedSection['xs2_sections'][0]['name']);

        $unmappedSection = collect($sections)->firstWhere('stadium_detail_id', 102);
        $this->assertFalse($unmappedSection['xs2_mapped']);
    }

    public function test_public_venue_detail_endpoints_return_404_for_unknown_venue(): void
    {
        $this->getJson('/api/venues/999/events')->assertNotFound();
        $this->getJson('/api/venues/999/categories')->assertNotFound();
        $this->getJson('/api/venues/999/sections')->assertNotFound();
    }

    public function test_public_venues_filter_by_league_date_and_performer(): void
    {
        $this->getJson('/api/venues?tournament_id=7&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.event_count', 2);

        $friendlyDay = now()->addDays(8)->toDateString();
        $this->getJson("/api/venues?date_from={$friendlyDay}&date_to={$friendlyDay}&per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 2)
            ->assertJsonPath('data.0.event_count', 1);

        $this->getJson('/api/venues?performer=Home%20FC&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 1);

        $this->getJson('/api/venues?category_id=2&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 2);

        $betweenDerbyAndCup = now()->addDays(40)->toDateString();
        $this->getJson("/api/venues/1/events?tournament_id=7&date_from={$betweenDerbyAndCup}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 2);
    }

    public function test_public_venue_filter_options_list_categories_and_leagues(): void
    {
        $this->getJson('/api/venues/filter-options')
            ->assertOk()
            ->assertJsonPath('data.categories.0.name', 'Concert')
            ->assertJsonFragment(['id' => 1, 'name' => 'Football'])
            ->assertJsonFragment(['id' => 7, 'name' => 'Serie A', 'category_id' => 1]);
    }
}
