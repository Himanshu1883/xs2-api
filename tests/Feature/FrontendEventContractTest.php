<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\User;
use App\Models\Xs2Event;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FrontendEventContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    public function test_public_events_search_and_filter_using_legacy_display_names(): void
    {
        $startsAt = now()->addDays(2)->setTime(19, 30);
        $this->insertReferencedLocalEvent(110, $startsAt);

        $this->getJson('/api/events?search=Legacy%20Home')
            ->assertOk()
            ->assertJsonPath('data.0.id', 110)
            ->assertJsonPath('data.0.home_team.name', 'Legacy Home')
            ->assertJsonPath('data.0.away_team.name', 'Legacy Away')
            ->assertJsonPath('data.0.venue.id', 801)
            ->assertJsonPath('data.0.venue.name', 'Legacy Park')
            ->assertJsonPath('data.0.venue.city', 'Legacy City')
            ->assertJsonPath('data.0.tournament.name', 'Legacy Cup')
            ->assertJsonPath('data.0.xs2_mapped', false)
            ->assertJsonPath('data.0.starts_at', $startsAt->format('Y-m-d\TH:i:s'));

        $this->getJson('/api/events?city=Legacy%20City')
            ->assertOk()
            ->assertJsonPath('data.0.id', 110);

        $this->getJson('/api/events?tournament=Legacy%20Cup')
            ->assertOk()
            ->assertJsonPath('data.0.id', 110);

        $this->getJson('/api/events?tournament_id=701')
            ->assertOk()
            ->assertJsonPath('data.0.id', 110);

        $this->getJson('/api/events/filter-options')
            ->assertOk()
            ->assertJsonPath('data.tournaments.0.id', 701)
            ->assertJsonPath('data.tournaments.0.name', 'Legacy Cup');

        $this->getJson('/api/events?venue=Legacy%20Park')
            ->assertOk()
            ->assertJsonPath('data.0.id', 110);
    }

    public function test_public_events_search_matches_english_translation_names(): void
    {
        $startsAt = now()->addDays(2)->setTime(19, 30);
        $this->insertReferencedLocalEvent(120, $startsAt);
        DB::table('match_info')->where('m_id', 120)->update([
            'match_name' => 'Non-display placeholder title',
        ]);
        DB::table('match_info_lang')->insert([
            'match_id' => 120,
            'match_name' => 'Legacy Home vs Legacy Away',
            'language' => 'en',
        ]);

        $this->getJson('/api/events?search=Legacy%20Home')
            ->assertOk()
            ->assertJsonPath('data.0.id', 120)
            ->assertJsonPath('data.0.name', 'Legacy Home vs Legacy Away');
    }

    public function test_admin_resources_use_legacy_display_names_and_timezone_less_local_datetimes(): void
    {
        $startsAt = now()->addDays(2)->setTime(19, 30);
        $this->insertReferencedLocalEvent(111, $startsAt);
        $xs2Event = Xs2Event::create([
            'external_event_id' => 'xs2-frontend-contract',
            'event_name' => 'Legacy Home vs Legacy Away',
            'date_start_local' => $startsAt->format('Y-m-d H:i:s'),
            'date_stop_local' => $startsAt->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'event_status' => 'active',
            'venue_name' => 'Supplier Venue',
            'city' => 'Supplier City',
            'iso_country' => 'GBR',
            'sport_type' => 'soccer',
            'raw_payload' => [],
        ]);
        $mapping = EventMapping::create([
            'xs2_event_id' => $xs2Event->id,
            'm_id' => 111,
            'status' => 'mapped',
            'mapping_method' => 'manual',
            'match_details' => [
                'candidates' => [['event_id' => 111, 'score' => 91.2]],
            ],
        ]);
        $user = User::factory()->create(['user_type' => 6]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/events/search?search=Legacy%20Away&limit=20')
            ->assertOk()
            ->assertJsonPath('data.0.id', 111)
            ->assertJsonPath('data.0.venue_name', 'Legacy Park')
            ->assertJsonPath('data.0.tournament_name', 'Legacy Cup')
            ->assertJsonPath('data.0.home_team_name', 'Legacy Home')
            ->assertJsonPath('data.0.away_team_name', 'Legacy Away')
            ->assertJsonPath('data.0.starts_at', $startsAt->format('Y-m-d\TH:i:s'));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/xs2/event-mappings/{$mapping->id}")
            ->assertOk()
            ->assertJsonPath('data.xs2_event.starts_at', $startsAt->format('Y-m-d\TH:i:s'))
            ->assertJsonPath('data.xs2_event.ends_at', $startsAt->copy()->addHours(2)->format('Y-m-d\TH:i:s'))
            ->assertJsonPath('data.local_event.id', 111)
            ->assertJsonPath('data.local_event.starts_at', $startsAt->format('Y-m-d\TH:i:s'))
            ->assertJsonPath('data.local_event.venue_id', 801)
            ->assertJsonPath('data.local_event.venue_name', 'Legacy Park')
            ->assertJsonPath('data.local_event.city', 'Legacy City')
            ->assertJsonPath('data.local_event.tournament_name', 'Legacy Cup')
            ->assertJsonPath('data.local_event.home_team_name', 'Legacy Home')
            ->assertJsonPath('data.local_event.away_team_name', 'Legacy Away')
            ->assertJsonPath('data.suggested_events.0.venue_name', 'Legacy Park')
            ->assertJsonPath('data.suggested_events.0.city', 'Legacy City');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/xs2/event-mappings')
            ->assertOk()
            ->assertJsonPath('data.0.local_event.id', 111)
            ->assertJsonPath('data.0.local_event.venue_id', 801)
            ->assertJsonPath('data.0.local_event.venue_name', 'Legacy Park');

        $this->getJson('/api/events/111')
            ->assertOk()
            ->assertJsonPath('data.starts_at', $startsAt->format('Y-m-d\TH:i:s'))
            ->assertJsonPath('data.ends_at', $startsAt->copy()->addHours(2)->format('Y-m-d\TH:i:s'))
            ->assertJsonPath('data.venue.id', 801)
            ->assertJsonPath('data.xs2_mapped', true)
            ->assertJsonPath('data.xs2_mapping_id', $mapping->id)
            ->assertJsonPath('data.xs2_event_id', 'xs2-frontend-contract')
            ->assertJsonPath('data.xs2_event_name', 'Legacy Home vs Legacy Away');
    }

    private function insertReferencedLocalEvent(int $id, Carbon $startsAt): void
    {
        DB::table('teams')->insert([
            ['id' => 501, 'team_name' => 'Legacy Home'],
            ['id' => 502, 'team_name' => 'Legacy Away'],
        ]);
        DB::table('cities')->insert(['id' => 601, 'name' => 'Legacy City']);
        DB::table('tournament')->insert(['t_id' => 701, 'tournament_name' => 'Legacy Cup']);
        DB::table('stadium')->insert(['s_id' => 801, 'stadium_name' => 'Legacy Park']);
        DB::table('match_info')->insert([
            'm_id' => $id,
            'match_name' => 'Legacy Home vs Legacy Away',
            'team_1' => '501',
            'team_2' => '502',
            'city' => '601',
            'tournament' => '701',
            'venue' => 801,
            'match_date' => $startsAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function createTables(): void
    {
        foreach (['personal_access_tokens', 'event_mappings', 'xs2_tickets', 'xs2_event_inventory_sync_states', 'xs2_events', 'match_info_lang', 'match_info', 'teams', 'tournament', 'cities', 'stadium', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('event_name');
            $table->dateTime('date_start_local')->nullable();
            $table->dateTime('date_stop_local')->nullable();
            $table->string('event_status')->nullable();
            $table->string('tournament_name')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('city')->nullable();
            $table->string('iso_country', 3)->nullable();
            $table->string('sport_type')->nullable();
            $table->string('hometeam_name')->nullable();
            $table->string('visitingteam_name')->nullable();
            $table->boolean('date_confirmed')->default(false);
            $table->text('event_description')->nullable();
            $table->unsignedInteger('number_of_tickets')->nullable();
            $table->unsignedBigInteger('min_ticket_price_eur')->nullable();
            $table->unsignedBigInteger('max_ticket_price_eur')->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_event_inventory_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id')->unique();
            $table->timestamp('tickets_last_incremental_sync_at')->nullable();
            $table->timestamp('tickets_last_full_sync_at')->nullable();
            $table->timestamp('tickets_next_sync_at')->nullable();
            $table->string('tickets_sync_status', 40)->nullable();
            $table->text('tickets_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id')->unique();
            $table->unsignedBigInteger('m_id')->nullable();
            $table->string('status');
            $table->string('mapping_method')->nullable();
            $table->decimal('match_score', 5, 2)->nullable();
            $table->json('match_details')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->unsignedInteger('stock')->default(0);
            $table->timestamps();
        });

        Schema::create('match_info_lang', function (Blueprint $table): void {
            $table->unsignedInteger('match_id');
            $table->string('match_name');
            $table->string('language');
        });

        Schema::create('match_info', function (Blueprint $table): void {
            $table->increments('m_id');
            $table->string('match_name');
            $table->string('team_1')->nullable();
            $table->string('team_2')->nullable();
            $table->string('city')->nullable();
            $table->string('tournament')->nullable();
            $table->unsignedInteger('venue')->nullable();
            $table->dateTime('match_date');
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('team_name');
        });
        Schema::create('tournament', function (Blueprint $table): void {
            $table->increments('t_id');
            $table->string('tournament_name');
        });
        Schema::create('cities', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('stadium', function (Blueprint $table): void {
            $table->increments('s_id');
            $table->string('stadium_name');
        });

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
    }
}
