<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Services\Xs2\EventMappingService;
use App\Services\Xs2\Xs2EventNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Xs2EventCategoryCompatibilityTest extends TestCase
{
    private int $footballCategoryId;

    private int $sportsCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-31 00:00:00');
        Queue::fake();
        $this->createTables();
        $this->seedCategories();
    }

    public function test_auto_map_matches_football_xs2_event_to_sports_local_event(): void
    {
        $start = $this->fixtureStart();
        $localEventId = $this->insertLocalEvent([
            'match_name' => 'Atalanta Vs Parma',
            'team_1' => 'Atalanta',
            'team_2' => 'Parma',
            'category' => $this->sportsCategoryId,
            'match_date' => $start->format('Y-m-d H:i:s'),
        ]);
        $xs2Event = $this->createXs2Event([
            'event_name' => 'Atalanta vs Parma',
            'hometeam_name' => 'Atalanta',
            'visitingteam_name' => 'Parma',
            'sport_type' => 'soccer',
            'date_start' => $start->format('Y-m-d\\TH:i:s'),
            'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ]);

        $mapping = app(EventMappingService::class)->map($xs2Event);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame($localEventId, $mapping->m_id);
        $this->assertSame(100.0, (float) $mapping->match_score);
    }

    public function test_auto_map_matches_football_xs2_event_to_football_local_event(): void
    {
        $start = $this->fixtureStart();
        $localEventId = $this->insertLocalEvent([
            'match_name' => 'Atalanta Vs Parma',
            'team_1' => 'Atalanta',
            'team_2' => 'Parma',
            'category' => $this->footballCategoryId,
            'match_date' => $start->format('Y-m-d H:i:s'),
        ]);
        $xs2Event = $this->createXs2Event([
            'event_name' => 'Atalanta vs Parma',
            'hometeam_name' => 'Atalanta',
            'visitingteam_name' => 'Parma',
            'sport_type' => 'soccer',
            'date_start' => $start->format('Y-m-d\\TH:i:s'),
            'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ]);

        $mapping = app(EventMappingService::class)->map($xs2Event);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame($localEventId, $mapping->m_id);
    }

    public function test_auto_map_does_not_match_sports_event_with_different_name_or_date(): void
    {
        $start = $this->fixtureStart();
        $this->insertLocalEvent([
            'match_name' => 'Inter vs Milan',
            'team_1' => 'Inter',
            'team_2' => 'Milan',
            'category' => $this->sportsCategoryId,
            'match_date' => $start->format('Y-m-d H:i:s'),
        ]);
        $xs2Event = $this->createXs2Event([
            'event_name' => 'Atalanta vs Parma',
            'hometeam_name' => 'Atalanta',
            'visitingteam_name' => 'Parma',
            'sport_type' => 'soccer',
            'date_start' => $start->format('Y-m-d\\TH:i:s'),
            'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ]);

        $mapping = app(EventMappingService::class)->map($xs2Event);

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
    }

    public function test_auto_map_does_not_match_sports_event_outside_date_tolerance(): void
    {
        $start = $this->fixtureStart();
        $this->insertLocalEvent([
            'match_name' => 'Atalanta Vs Parma',
            'team_1' => 'Atalanta',
            'team_2' => 'Parma',
            'category' => $this->sportsCategoryId,
            'match_date' => $start->copy()->addDays(5)->format('Y-m-d H:i:s'),
        ]);
        $xs2Event = $this->createXs2Event([
            'event_name' => 'Atalanta vs Parma',
            'hometeam_name' => 'Atalanta',
            'visitingteam_name' => 'Parma',
            'sport_type' => 'soccer',
            'date_start' => $start->format('Y-m-d\\TH:i:s'),
            'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ]);

        $mapping = app(EventMappingService::class)->map($xs2Event);

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertLessThan(100, (float) ($mapping->match_score ?? 0));
    }

    public function test_auto_map_does_not_create_duplicate_mapping_when_local_event_is_already_mapped(): void
    {
        $start = $this->fixtureStart();
        $localEventId = $this->insertLocalEvent([
            'match_name' => 'Atalanta Vs Parma',
            'team_1' => 'Atalanta',
            'team_2' => 'Parma',
            'category' => $this->sportsCategoryId,
            'match_date' => $start->format('Y-m-d H:i:s'),
        ]);
        $existingXs2Event = $this->createXs2Event([
            'event_id' => 'xs2-existing-atalanta',
            'event_name' => 'Atalanta vs Parma',
            'hometeam_name' => 'Atalanta',
            'visitingteam_name' => 'Parma',
            'sport_type' => 'soccer',
            'date_start' => $start->format('Y-m-d\\TH:i:s'),
            'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ]);
        EventMapping::create([
            'xs2_event_id' => $existingXs2Event->id,
            'm_id' => $localEventId,
            'status' => 'mapped',
            'mapping_method' => 'manual',
        ]);

        $duplicateXs2Event = $this->createXs2Event([
            'event_id' => 'xs2-duplicate-atalanta',
            'event_name' => 'Atalanta vs Parma',
            'hometeam_name' => 'Atalanta',
            'visitingteam_name' => 'Parma',
            'sport_type' => 'soccer',
            'date_start' => $start->format('Y-m-d\\TH:i:s'),
            'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ]);

        $mapping = app(EventMappingService::class)->map($duplicateXs2Event);

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertSame('local_event_already_has_public_mapping', $mapping->match_details['reason'] ?? null);
        $this->assertSame(1, EventMapping::query()->where('m_id', $localEventId)->whereIn('status', ['mapped', 'created'])->count());
    }

    public function test_manual_event_search_includes_sports_event_when_football_category_is_selected(): void
    {
        $start = $this->fixtureStart();
        $localEventId = $this->insertLocalEvent([
            'match_name' => 'Atalanta Vs Parma',
            'team_1' => 'Atalanta',
            'team_2' => 'Parma',
            'category' => $this->sportsCategoryId,
            'match_date' => $start->format('Y-m-d H:i:s'),
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;
        $dateFrom = $start->copy()->subDays(2)->toDateString();
        $dateTo = $start->copy()->addDays(2)->toDateString();

        $this->withToken($token)
            ->getJson('/api/admin/events/search?'.http_build_query([
                'search' => 'Atalanta',
                'category_id' => $this->footballCategoryId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => 20,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $localEventId)
            ->assertJsonPath('data.0.name', 'Atalanta Vs Parma');
    }

    public function test_auto_map_events_command_maps_football_xs2_event_to_sports_local_event(): void
    {
        $start = $this->fixtureStart();
        $localEventId = $this->insertLocalEvent([
            'match_name' => 'Atalanta Vs Parma',
            'team_1' => 'Atalanta',
            'team_2' => 'Parma',
            'category' => $this->sportsCategoryId,
            'match_date' => $start->format('Y-m-d H:i:s'),
        ]);
        $xs2Event = $this->createXs2Event([
            'event_id' => 'xs2-command-atalanta',
            'event_name' => 'Atalanta vs Parma',
            'hometeam_name' => 'Atalanta',
            'visitingteam_name' => 'Parma',
            'sport_type' => 'soccer',
            'date_start' => $start->format('Y-m-d\\TH:i:s'),
            'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ]);

        Artisan::call('xs2:auto-map-events', ['--min-score' => 100, '--event-id' => $xs2Event->external_event_id]);

        $mapping = EventMapping::query()->where('xs2_event_id', $xs2Event->id)->first();
        $this->assertNotNull($mapping);
        $this->assertSame('mapped', $mapping->status);
        $this->assertSame($localEventId, $mapping->m_id);
    }

    /** @param array<string, mixed> $overrides */
    private function insertLocalEvent(array $overrides): int
    {
        $id = (int) ($overrides['m_id'] ?? random_int(1000, 9999));
        DB::table('match_info')->insert([
            'm_id' => $id,
            'match_name' => 'Atalanta Vs Parma',
            'team_1' => 'Atalanta',
            'team_2' => 'Parma',
            'city' => 'Bergamo',
            'tournament' => 'Serie A',
            'category' => $this->sportsCategoryId,
            'match_date' => $this->fixtureStart()->format('Y-m-d H:i:s'),
            'status' => 1,
            ...$overrides,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function createXs2Event(array $overrides): Xs2Event
    {
        $payload = [
            'event_id' => 'xs2-atalanta-parma',
            'event_name' => 'Atalanta vs Parma',
            'date_start' => $this->fixtureStart()->format('Y-m-d\\TH:i:s'),
            'date_stop' => $this->fixtureStart()->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
            'event_status' => 'notstarted',
            'tournament_id' => 'serie-a',
            'tournament_name' => 'Serie A',
            'venue_id' => 'venue-atalanta',
            'venue_name' => 'Gewiss Stadium',
            'location_id' => 'bergamo',
            'city' => 'Bergamo',
            'iso_country' => 'ITA',
            'sport_type' => 'soccer',
            'hometeam_name' => 'Atalanta',
            'visiting_name' => 'Parma',
            ...$overrides,
        ];

        return Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($payload));
    }

    private function fixtureStart(): \Carbon\Carbon
    {
        return now()->addDays(30)->setTime(13, 0, 0);
    }

    private function seedCategories(): void
    {
        $this->footballCategoryId = (int) DB::table('game_category')->insertGetId([
            'category_name' => 'Football',
            'status' => 1,
        ]);
        $this->sportsCategoryId = (int) DB::table('game_category')->insertGetId([
            'category_name' => 'Sports',
            'status' => 1,
        ]);
    }

    private function createTables(): void
    {
        foreach (['personal_access_tokens', 'event_mappings', 'match_info', 'teams', 'tournament', 'cities', 'stadium', 'game_category', 'xs2_events', 'users'] as $table) {
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
            $table->boolean('is_admin')->default(false);
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

        Schema::create('game_category', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('category_name');
            $table->unsignedTinyInteger('status')->default(1);
        });

        Schema::create('stadium', function (Blueprint $table): void {
            $table->increments('s_id');
            $table->string('stadium_name')->nullable();
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('tournament', function (Blueprint $table): void {
            $table->increments('t_id');
            $table->string('tournament_name')->nullable();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('team_name')->nullable();
        });

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('event_name');
            foreach (['date_start_local', 'date_stop_local', 'date_start_main_event', 'date_stop_main_event', 'external_created_at', 'external_updated_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
            foreach (['event_status', 'tournament_id', 'tournament_name', 'tournament_type', 'season', 'venue_id', 'venue_name', 'location_id', 'city', 'iso_country', 'sport_type', 'hometeam_id', 'hometeam_name', 'visitingteam_id', 'visitingteam_name', 'slug', 'date_visibility'] as $column) {
                $table->string($column)->nullable();
            }
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('date_confirmed')->default(false);
            $table->text('event_description')->nullable();
            $table->unsignedBigInteger('min_ticket_price_eur')->nullable();
            $table->unsignedBigInteger('max_ticket_price_eur')->nullable();
            $table->unsignedInteger('number_of_tickets')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->json('raw_payload');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });

        Schema::create('match_info', function (Blueprint $table): void {
            $table->increments('m_id');
            $table->string('match_name');
            $table->string('team_1')->nullable();
            $table->string('team_2')->nullable();
            $table->string('city')->nullable();
            $table->string('tournament')->nullable();
            $table->unsignedInteger('category')->nullable();
            $table->unsignedInteger('venue')->nullable();
            $table->boolean('status')->default(true);
            $table->dateTime('match_date');
        });

        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_event_id')->unique();
            $table->unsignedBigInteger('m_id')->nullable();
            $table->string('status');
            $table->string('mapping_method')->nullable();
            $table->decimal('match_score', 5, 2)->nullable();
            $table->json('match_details')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }
}
