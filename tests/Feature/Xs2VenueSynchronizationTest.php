<?php

namespace Tests\Feature;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Jobs\SyncXs2EventInventory;
use App\Jobs\SyncXs2VenueForEvent;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2Venue;
use App\Services\Xs2\Xs2CategorySyncService;
use App\Services\Xs2\Xs2Client;
use App\Services\Xs2\Xs2EventInventorySyncService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use App\Services\Xs2\Xs2TicketNormalizer;
use App\Services\Xs2\Xs2VenueSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Xs2VenueSynchronizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createLocalVenueMasterData();

        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.venue_detail_endpoint', '/v1/venues/{venue_id}');
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        config()->set('xs2.mapping.stadium_pending_threshold', 80);
    }

    public function test_live_xs2_venue_aliases_and_iso3_country_map_to_the_local_stadium(): void
    {
        $result = app(Xs2VenueSyncService::class)->syncPayload([
            'venue_id' => 'venue-olympic-1',
            'official_name' => 'Estadi Test',
            'streetname' => 'Avinguda de la Prova 1',
            'postalcode' => '08001',
            'city' => 'Barcelona',
            'country' => 'ESP',
        ]);

        $venue = $result['venue']->fresh();

        $this->assertSame('venue-olympic-1', $venue->external_venue_id);
        $this->assertSame('Estadi Test', $venue->venue_name);
        $this->assertSame('Avinguda de la Prova 1', $venue->address);
        $this->assertSame('08001', $venue->postal_code);
        $this->assertSame('Barcelona', $venue->city_name);
        $this->assertSame('ES', $venue->country_code);
        $this->assertSame('mapped', $result['mapping']->status);
        $this->assertSame(500, (int) $result['mapping']->stadium_id);
        $this->assertSame(1, (int) $result['mapping']->resolved_country_id);
        $this->assertSame(100, (int) $result['mapping']->resolved_city_id);
    }

    public function test_event_fallback_uses_event_venue_data_without_requesting_venue_details(): void
    {
        $event = Xs2Event::create([
            'external_event_id' => 'event-with-venue-fallback',
            'event_name' => 'Test fixture',
            'venue_id' => 'venue-olympic-1',
            'venue_name' => 'Estadi Test',
            'city' => 'Barcelona',
            'iso_country' => 'ESP',
            'raw_payload' => ['venue_id' => 'venue-olympic-1'],
        ]);
        Http::fake([
            'https://xs2.test/v1/venues/*' => Http::response([
                'venue_id' => 'venue-olympic-1',
                'official_name' => 'Incorrect remote fallback',
                'city' => 'Barcelona',
                'country' => 'ESP',
            ]),
        ]);

        $result = app(Xs2VenueSyncService::class)->syncForEvent($event);

        Http::assertNothingSent();
        $this->assertSame('Estadi Test', $result['venue']->venue_name);
        $this->assertSame('ES', $result['venue']->country_code);
        $this->assertSame('mapped', $result['mapping']->status);
        $this->assertSame(500, (int) $result['mapping']->stadium_id);
    }

    public function test_dedicated_venue_sync_fetches_current_venue_details(): void
    {
        Http::fake([
            'https://xs2.test/v1/venues/venue-olympic-1' => Http::response([
                'venue' => [
                    'venue_id' => 'venue-olympic-1',
                    'official_name' => 'Updated Estadi Test',
                    'city' => 'Barcelona',
                    'country' => 'ESP',
                ],
            ]),
        ]);

        $result = app(Xs2VenueSyncService::class)->syncByExternalVenueId('venue-olympic-1');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://xs2.test/v1/venues/venue-olympic-1');
        $this->assertSame('Updated Estadi Test', $result['venue']->venue_name);
        $this->assertSame('pending_stadium_mapping', $result['mapping']->status);
    }

    public function test_dedicated_venue_job_executes_the_current_venue_sync_contract(): void
    {
        $event = Xs2Event::create([
            'external_event_id' => 'event-for-dedicated-venue-job',
            'event_name' => 'Test fixture',
            'venue_id' => 'venue-olympic-1',
            'raw_payload' => [],
        ]);
        Http::fake([
            'https://xs2.test/v1/venues/venue-olympic-1' => Http::response([
                'venue_id' => 'venue-olympic-1',
                'official_name' => 'Estadi Test',
                'city' => 'Barcelona',
                'country' => 'ESP',
            ]),
        ]);

        (new SyncXs2VenueForEvent($event->id))->handle(app(Xs2VenueSyncService::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://xs2.test/v1/venues/venue-olympic-1');
        $this->assertDatabaseHas('xs2_venues', [
            'external_venue_id' => 'venue-olympic-1',
            'venue_name' => 'Estadi Test',
            'country_code' => 'ES',
        ]);
    }

    public function test_venue_rate_limit_is_propagated_from_inventory_sync(): void
    {
        $event = Xs2Event::create([
            'external_event_id' => 'event-rate-limited-venue',
            'event_name' => 'Test fixture',
            'venue_id' => 'venue-olympic-1',
            'raw_payload' => ['venue_id' => 'venue-olympic-1'],
        ]);
        $client = \Mockery::mock(Xs2Client::class);
        $venues = \Mockery::mock(Xs2VenueSyncService::class);
        $categories = \Mockery::mock(Xs2CategorySyncService::class);
        $normalizer = \Mockery::mock(Xs2TicketNormalizer::class);
        $mappingStates = \Mockery::mock(Xs2TicketMappingStatusService::class);
        $awayTeamContext = \Mockery::mock(\App\Services\Xs2\Xs2AwayTeamContextService::class);
        $sbPublish = \Mockery::mock(\App\Services\SellerApi\SbNewListingPublishService::class);
        $venues->shouldReceive('syncForEvent')
            ->once()
            ->withArgs(fn (Xs2Event $syncedEvent): bool => $syncedEvent->is($event))
            ->andThrow(new Xs2RateLimitException(47));
        $categories->shouldNotReceive('sync');
        $client->shouldNotReceive('getTicketsForEvent');
        $sbPublish->shouldNotReceive('isPublishedOnSb');

        $service = new Xs2EventInventorySyncService(
            $client,
            $venues,
            $categories,
            $normalizer,
            $mappingStates,
            $awayTeamContext,
            $sbPublish,
        );

        try {
            $service->sync($event, 'full');
            $this->fail('The XS2 venue rate limit should be propagated to the job.');
        } catch (Xs2RateLimitException $exception) {
            $this->assertSame(47, $exception->retryAfter);
        }

        $this->assertDatabaseHas('xs2_event_inventory_sync_states', [
            'xs2_event_id' => $event->id,
            'tickets_sync_status' => 'failed',
        ]);
    }

    public function test_inventory_job_uses_one_unique_id_regardless_of_sync_mode(): void
    {
        $this->assertSame(
            (new SyncXs2EventInventory(123, 'full'))->uniqueId(),
            (new SyncXs2EventInventory(123, 'incremental'))->uniqueId(),
        );
    }

    public function test_venue_backfill_uses_existing_event_data_without_http_and_preserves_manual_mapping(): void
    {
        Xs2Event::create([
            'external_event_id' => 'event-for-venue-backfill',
            'event_name' => 'Test fixture',
            'venue_id' => 'venue-olympic-1',
            'venue_name' => 'Estadi Test',
            'city' => 'Barcelona',
            'iso_country' => 'ESP',
            'raw_payload' => ['venue_id' => 'venue-olympic-1'],
        ]);
        $venue = Xs2Venue::create([
            'external_venue_id' => 'venue-olympic-1',
            'venue_name' => 'Outdated venue name',
            'address' => 'Stored address',
            'postal_code' => '08099',
            'city_name' => 'Outdated city',
            'country_code' => 'ZZ',
            'raw_payload' => [
                'venue_id' => 'venue-olympic-1',
                'streetname' => 'Stored address',
                'postalcode' => '08099',
            ],
        ]);
        $manualMapping = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 999,
            'status' => 'mapped',
            'mapping_method' => 'manual',
            'manually_confirmed' => true,
        ]);
        Http::fake();

        $this->artisan('xs2:backfill-venues')->assertExitCode(0);

        Http::assertNothingSent();
        $venue->refresh();
        $manualMapping->refresh();
        $this->assertSame('Estadi Test', $venue->venue_name);
        $this->assertSame('Stored address', $venue->address);
        $this->assertSame('08099', $venue->postal_code);
        $this->assertSame('Barcelona', $venue->city_name);
        $this->assertSame('ES', $venue->country_code);
        $this->assertSame(999, (int) $manualMapping->stadium_id);
        $this->assertSame('manual', $manualMapping->mapping_method);
        $this->assertTrue($manualMapping->manually_confirmed);
    }

    public function test_resolve_stadiums_command_accepts_a_numeric_local_venue_id(): void
    {
        $venue = Xs2Venue::create([
            'id' => 101,
            'external_venue_id' => 'venue-101',
            'venue_name' => 'Estadi Test',
            'city_name' => 'Barcelona',
            'country_code' => 'ES',
            'raw_payload' => [],
        ]);

        $this->artisan('xs2:resolve-stadiums', ['--venue-id' => '101', '--force' => true])
            ->expectsOutput('Resolved 1 XS2 stadium mapping(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('xs2_stadium_mappings', [
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
    }

    private function createLocalVenueMasterData(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('sortname', 3)->nullable();
            $table->string('name');
        });
        Schema::create('states', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('country_id');
            $table->string('name');
        });
        Schema::create('cities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('state_id');
            $table->string('name');
        });
        Schema::create('stadium', function (Blueprint $table): void {
            $table->increments('s_id');
            $table->string('stadium_name');
            $table->unsignedInteger('country')->nullable();
            $table->unsignedInteger('city')->nullable();
        });

        DB::table('countries')->insert(['id' => 1, 'sortname' => 'ES', 'name' => 'Spain']);
        DB::table('states')->insert(['id' => 10, 'country_id' => 1, 'name' => 'Catalonia']);
        DB::table('cities')->insert(['id' => 100, 'state_id' => 10, 'name' => 'Barcelona']);
        DB::table('stadium')->insert([
            's_id' => 500,
            'stadium_name' => 'Estadi Test',
            'country' => 1,
            'city' => 100,
        ]);
    }
}
