<?php

namespace Tests\Feature;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Exceptions\Integrations\Xs2ResponseException;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Jobs\ResolvePendingXs2Listings;
use App\Jobs\SyncXs2CategoriesForEvent;
use App\Jobs\SyncXs2EventsJob;
use App\Jobs\SyncXs2TicketsForEvent;
use App\Jobs\SyncXs2VenueForEvent;
use App\Models\EventMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2SyncState;
use App\Models\Xs2Ticket;
use App\Models\Xs2Venue;
use App\Services\Xs2\EventMappingService;
use App\Services\Xs2\Xs2Client;
use App\Services\Xs2\Xs2EventNormalizer;
use App\Services\Xs2\Xs2EventSyncService;
use App\Services\Xs2\Xs2TicketSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class Xs2EventFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Keep the fixed August 2026 event fixtures future-facing regardless
        // of the calendar date on which the suite is executed.
        $this->travelTo('2026-07-31 00:00:00');
        $this->createEventFlowTables();
        config()->set('services.xs2.base_url', 'https://testapi.xs2event.com');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('xs2.sandbox.api_url', 'https://testapi.xs2event.com');
        config()->set('xs2.sandbox.api_key', 'test-key');
        config()->set('services.xs2.sports', ['soccer']);
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        config()->set('xs2.mapping.stadium_pending_threshold', 80);
        Queue::fake([SyncXs2CategoriesForEvent::class, ResolvePendingXs2Listings::class]);
    }

    public function test_event_and_ticket_jobs_use_the_configured_xs2_queue(): void
    {
        config()->set('services.xs2.queue', 'xs2-sync-test');

        $this->assertSame('xs2-sync-test', (new SyncXs2EventsJob('soccer'))->queue);
        $this->assertSame('xs2-sync-test', (new SyncXs2TicketsForEvent(1))->queue);
    }

    public function test_event_sync_modes_are_deduplicated_separately_but_share_an_execution_lock(): void
    {
        $incremental = new SyncXs2EventsJob('soccer');
        $full = new SyncXs2EventsJob('soccer', true);
        $incrementalLock = $incremental->middleware()[0];
        $fullLock = $full->middleware()[0];

        $this->assertSame('xs2-events:soccer:incremental', $incremental->uniqueId());
        $this->assertSame('xs2-events:soccer:full', $full->uniqueId());
        $this->assertSame($incrementalLock->getLockKey($incremental), $fullLock->getLockKey($full));
        $this->assertSame(60, $fullLock->releaseAfter);
        $this->assertSame(600, $fullLock->expiresAfter);
        $this->assertSame(0, $full->tries);
        $this->assertSame(5, $full->maxExceptions);
    }

    public function test_midnight_schedule_prioritizes_the_full_event_snapshot(): void
    {
        config()->set('xs2.events_sync.schedule_enabled', true);

        $events = collect(app(Schedule::class)->events());
        $incremental = $events->first(fn ($event): bool => str_contains((string) ($event->command ?? ''), 'xs2:sync-events')
            && ! str_contains((string) ($event->command ?? ''), '--full'));
        $full = $events->first(fn ($event): bool => str_contains((string) ($event->command ?? ''), 'xs2:sync-events --full'));

        $this->assertNotNull($incremental);
        $this->assertNotNull($full);

        $this->travelTo('2026-08-01 00:00:00');
        $this->assertTrue($incremental->isDue($this->app));
        $this->assertFalse($incremental->filtersPass($this->app));
        $this->assertTrue($full->isDue($this->app));
        $this->assertTrue($full->filtersPass($this->app));

        $this->travelTo('2026-08-01 01:00:00');
        $this->assertTrue($incremental->isDue($this->app));
        $this->assertTrue($incremental->filtersPass($this->app));
        $this->assertFalse($full->isDue($this->app));
    }

    public function test_client_sends_api_key_and_query_parameters(): void
    {
        Http::fake(['https://testapi.xs2event.com/v1/events*' => Http::response(['events' => [], 'pagination' => []])]);

        app(Xs2Client::class)->getEvents(['sport_type' => 'soccer', 'page' => 2, 'page_size' => 50]);

        Http::assertSent(fn ($request) => ($request->header('X-Api-Key')[0] ?? null) === 'test-key'
            && str_contains($request->url(), 'sport_type=soccer') && str_contains($request->url(), 'page=2'));
    }

    public function test_sync_processes_pages_and_upserts_events(): void
    {
        Http::fake(function ($request) {
            $page = $request['page'];

            return Http::response([
                'events' => [$this->eventPayload("xs2-{$page}")],
                'pagination' => ['next_page' => (int) $page === 1 ? 'https://example.test?page=2' : null],
            ]);
        });

        $summary = app(Xs2EventSyncService::class)->sync('soccer');

        $this->assertSame(2, $summary['pages_processed']);
        $this->assertSame(2, Xs2Event::count());
        $this->assertSame(2, $summary['events_pending']);
        $this->assertSame(0, $summary['local_events_created']);
        $this->assertSame(0, $summary['failed_events']);
        $this->assertSame(0, DB::table('match_info')->count());
        $this->assertDatabaseHas('xs2_sync_states', ['resource' => 'events:soccer', 'status' => 'completed']);
    }

    public function test_sync_persists_events_with_blank_coordinates_as_null(): void
    {
        $payload = [
            ...$this->eventPayload('xs2-blank-coordinates'),
            'latitude' => '',
            'longitude' => '   ',
        ];
        Http::fake([
            'https://testapi.xs2event.com/v1/events*' => Http::response([
                'events' => [$payload],
                'pagination' => [],
            ]),
        ]);

        $summary = app(Xs2EventSyncService::class)->sync('soccer');
        $event = Xs2Event::query()->sole();

        $this->assertSame(1, $summary['events_created']);
        $this->assertSame(0, $summary['failed_events']);
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);
    }

    public function test_repeated_sync_updates_without_duplicate_xs2_events(): void
    {
        Http::fake(['*' => Http::response(['events' => [$this->eventPayload('xs2-123')], 'pagination' => []])]);

        app(Xs2EventSyncService::class)->sync('soccer');
        app(Xs2EventSyncService::class)->sync('soccer');

        $this->assertSame(1, Xs2Event::count());
    }

    public function test_successful_full_event_snapshot_marks_absent_events_missing_and_reconciles_them(): void
    {
        $missingEvent = $this->xs2Event('xs2-missing-from-full-snapshot');
        $missingEvent->update(['sport_type' => 'soccer']);
        $mapping = EventMapping::create([
            'xs2_event_id' => $missingEvent->id,
            'm_id' => 4308,
            'status' => 'mapped',
        ]);
        $otherSport = $this->xs2Event('xs2-other-sport');
        $otherSport->update(['sport_type' => 'tennis']);
        Queue::fake();
        Http::fake(['*' => Http::response(['events' => [], 'pagination' => []])]);

        $summary = app(Xs2EventSyncService::class)->sync('soccer', true);

        $this->assertSame(1, $summary['events_missing']);
        $this->assertNotNull($missingEvent->fresh()->missing_since);
        $this->assertNull($otherSport->fresh()->missing_since);
        Queue::assertPushed(
            ReconcileSellerListingsForMapping::class,
            fn (ReconcileSellerListingsForMapping $job): bool => $job->mappingId === $mapping->id,
        );
    }

    public function test_event_status_change_queues_immediate_listing_reconciliation(): void
    {
        $this->travelTo('2026-07-31 12:00:00');
        try {
            DB::table('match_info')->insert($this->localEvent(4309, 'xs2-status-change'));
            $payload = $this->eventPayload('xs2-status-change');
            Http::fake(function () use (&$payload) {
                return Http::response(['events' => [$payload], 'pagination' => []]);
            });
            app(Xs2EventSyncService::class)->sync('soccer');
            $mapping = EventMapping::query()->sole();
            Queue::fake();
            $payload['event_status'] = 'cancelled';

            app(Xs2EventSyncService::class)->sync('soccer');

            Queue::assertPushed(
                ReconcileSellerListingsForMapping::class,
                fn (ReconcileSellerListingsForMapping $job): bool => $job->mappingId === $mapping->id,
            );
        } finally {
            $this->travelBack();
        }
    }

    public function test_stable_mappings_are_preserved_until_mapping_inputs_change(): void
    {
        DB::table('match_info')->insert($this->localEvent(4310));
        $payload = $this->eventPayload('xs2-source-change');
        $changedStart = $this->fixtureStart()->addDays(10);
        $changedPayload = [
            ...$payload,
            'date_start' => $changedStart->format('Y-m-d\\TH:i:s'),
            'date_stop' => $changedStart->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
        ];
        $currentPayload = $payload;
        Http::fake(function () use (&$currentPayload) {
            return Http::response(['events' => [$currentPayload], 'pagination' => []]);
        });

        app(Xs2EventSyncService::class)->sync('soccer');
        $mapping = EventMapping::query()->sole();
        $mapping->update(['match_score' => 12.34]);

        app(Xs2EventSyncService::class)->sync('soccer');
        $this->assertSame(12.34, (float) $mapping->fresh()->match_score);

        $currentPayload = $changedPayload;
        app(Xs2EventSyncService::class)->sync('soccer');

        $mapping->refresh();
        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertSame(0.0, (float) $mapping->match_score);
    }

    public function test_incremental_sync_uses_previous_successful_checkpoint(): void
    {
        DB::table('xs2_sync_states')->insert(['resource' => 'events:soccer', 'last_successful_at' => '2026-07-01 10:00:00', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['*' => Http::response(['events' => [], 'pagination' => []])]);

        app(Xs2EventSyncService::class)->sync('soccer');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'updated=ge%3A2026-07-01%2009%3A55%3A00'));
    }

    public function test_full_ticket_sync_requests_event_catalog_with_youth_included(): void
    {
        Http::fake(['https://testapi.xs2event.com/v1/tickets*' => Http::response([
            'tickets' => [[
                'ticket_id' => 'ticket-on-request',
                'event_id' => 'event-123',
                'stock' => 2,
                'ticket_status' => 'on_request',
            ]],
            'pagination' => [],
        ])]);

        $items = app(Xs2Client::class)->getTicketsForEvent('event-123');

        $this->assertCount(1, $items);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['event_id'] ?? null) === 'event-123'
                && ($query['include_youth'] ?? null) === 'true'
                && ! array_key_exists('ticket_status', $query)
                && ! array_key_exists('stock', $query);
        });
    }

    public function test_incremental_ticket_sync_uses_a_utc_date_filter(): void
    {
        Http::fake(['https://testapi.xs2event.com/v1/tickets*' => Http::response(['tickets' => [], 'pagination' => []])]);

        app(Xs2Client::class)->getIncrementalTicketsForEvent(
            'event-123',
            CarbonImmutable::parse('2026-07-01 04:30:00', 'Asia/Kolkata')
        );

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['event_id'] ?? null) === 'event-123'
                && ($query['updated'] ?? null) === 'ge:2026-06-30';
        });
    }

    public function test_ticket_pagination_rejects_an_empty_nonterminal_page(): void
    {
        Http::fake(['https://testapi.xs2event.com/v1/tickets*' => Http::response([
            'tickets' => [],
            'pagination' => ['next_page' => 2, 'total_pages' => 2],
        ])]);

        $this->expectException(Xs2ResponseException::class);
        $this->expectExceptionMessage('empty page before the collection was complete');

        app(Xs2Client::class)->getTicketsForEvent('event-123');
    }

    public function test_rate_limited_ticket_sync_job_is_released_for_retry(): void
    {
        $event = $this->xs2Event('xs2-ticket-rate-limit');
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'm_id' => 1,
            'status' => 'mapped',
        ]);
        $service = \Mockery::mock(Xs2TicketSyncService::class);
        $service->shouldReceive('sync')
            ->once()
            ->andThrow(new Xs2RateLimitException(42));
        $job = (new SyncXs2TicketsForEvent($mapping->id))->withFakeQueueInteractions();

        $job->handle($service);

        $job->assertReleased(42);
    }

    public function test_failed_sync_does_not_advance_successful_checkpoint(): void
    {
        DB::table('xs2_sync_states')->insert(['resource' => 'events:soccer', 'last_successful_at' => '2026-07-01 10:00:00', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['*' => Http::response(['message' => 'temporary error'], 500)]);

        try {
            app(Xs2EventSyncService::class)->sync('soccer');
            $this->fail('The XS2 HTTP error should fail the complete sync.');
        } catch (\Throwable) {
            $this->assertDatabaseHas('xs2_sync_states', ['resource' => 'events:soccer', 'last_successful_at' => '2026-07-01 10:00:00', 'status' => 'failed']);
        }
    }

    public function test_partial_full_snapshot_rolls_back_failed_items_and_is_not_authoritative(): void
    {
        DB::table('xs2_sync_states')->insert([
            'resource' => 'events:soccer',
            'last_successful_at' => '2026-07-01 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unseenEvent = $this->xs2Event('xs2-unseen-during-partial-snapshot');
        Http::fake(['*' => Http::response([
            'events' => [
                $this->eventPayload('xs2-mapper-failure'),
                $this->eventPayload('xs2-valid-after-failure'),
            ],
            'pagination' => [],
        ])]);
        $mappingService = \Mockery::mock(EventMappingService::class);
        $mappingService->shouldReceive('map')->twice()->andReturnUsing(function (Xs2Event $event): EventMapping {
            if ($event->external_event_id === 'xs2-mapper-failure') {
                throw new RuntimeException('mapping failed');
            }

            return EventMapping::create([
                'xs2_event_id' => $event->id,
                'status' => 'pending',
                'mapping_method' => 'automatic',
            ]);
        });
        $service = new Xs2EventSyncService(
            app(Xs2Client::class),
            app(Xs2EventNormalizer::class),
            $mappingService,
        );

        try {
            $service->sync('soccer', true);
            $this->fail('An item failure must fail the complete snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mapping failed', $exception->getMessage());
        }

        $state = Xs2SyncState::query()->where('resource', 'events:soccer')->sole();
        $this->assertSame('failed', $state->status);
        $this->assertSame('2026-07-01 10:00:00', $state->last_successful_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $state->metadata['failed_events']);
        $this->assertDatabaseMissing('xs2_events', ['external_event_id' => 'xs2-mapper-failure']);
        $this->assertDatabaseHas('xs2_events', ['external_event_id' => 'xs2-valid-after-failure']);
        $this->assertNull($unseenEvent->fresh()->missing_since);

        $summary = app(Xs2EventSyncService::class)->sync('soccer', true);

        $this->assertSame(0, $summary['failed_events']);
        $this->assertDatabaseHas('xs2_events', ['external_event_id' => 'xs2-mapper-failure']);
        $this->assertNotNull($unseenEvent->fresh()->missing_since);
        $this->assertSame('completed', $state->fresh()->status);
    }

    public function test_exact_local_event_is_mapped(): void
    {
        DB::table('match_info')->insert($this->localEvent(42, 'xs2-123'));
        $mapping = app(EventMappingService::class)->map($this->xs2Event());

        $this->assertSame(42, $mapping->m_id);
        $this->assertSame('exact', $mapping->mapping_method);
    }

    public function test_missing_start_date_stays_pending_even_when_an_external_id_matches(): void
    {
        DB::table('match_info')->insert($this->localEvent(420, 'xs2-missing-start'));
        $payload = $this->eventPayload('xs2-missing-start');
        $payload['date_start'] = null;
        $event = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($payload));

        $mapping = app(EventMappingService::class)->map($event);

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertSame('missing_start_date', $mapping->match_details['reason']);
    }

    public function test_exact_text_fallback_requires_within_one_calendar_day(): void
    {
        $candidate = $this->localEvent(421);
        $candidate['match_date'] = '2026-08-02 12:00:00';
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($this->xs2Event('xs2-next-day-fixture'));

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertNotSame('exact', $mapping->mapping_method);
    }

    public function test_exact_text_fallback_maps_when_local_date_is_one_day_off(): void
    {
        $candidate = $this->localEvent(421);
        $candidate['match_date'] = $this->fixtureStart()->copy()->subDay()->format('Y-m-d H:i:s');
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($this->xs2Event('xs2-one-day-off-exact'));

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(421, $mapping->m_id);
        $this->assertSame('exact', $mapping->mapping_method);
    }

    public function test_valencia_like_fixture_one_day_off_stays_pending_below_auto_map_threshold(): void
    {
        $start = $this->fixtureStart();
        $payload = $this->eventPayload('xs2-valencia-celta');
        $payload['event_name'] = 'Valencia CF vs Celta de Vigo';
        $payload['hometeam_name'] = 'Valencia CF';
        $payload['visiting_name'] = 'Celta de Vigo';
        $payload['tournament_name'] = 'La Liga';
        $payload['date_start'] = $start->format('Y-m-d\\TH:i:s');
        $payload['date_stop'] = $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s');
        $event = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($payload));

        $candidate = [
            'm_id' => 9001,
            'match_name' => 'Valencia Vs Celta Vigo',
            'team_1' => 'Valencia',
            'team_2' => 'Celta Vigo',
            'city' => 'Valencia',
            'tournament' => 'La Liga',
            'match_date' => $start->copy()->addDay()->setTime(18, 0)->format('Y-m-d H:i:s'),
        ];
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($event);

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertGreaterThanOrEqual(65, (float) $mapping->match_score);
        $this->assertLessThan(100, (float) $mapping->match_score);
        $this->assertSame(9001, $mapping->match_details['best_match']['candidate_event_id'] ?? $mapping->match_details['candidates'][0]['event_id'] ?? null);
        $this->assertSame(1, $mapping->match_details['best_match']['days_apart'] ?? $mapping->match_details['days_apart'] ?? null);
        $this->assertSame(100.0, (float) ($mapping->match_details['best_match']['date_score'] ?? $mapping->match_details['date_score'] ?? 0));
    }

    public function test_candidate_lookup_includes_previous_calendar_day_even_when_time_is_earlier(): void
    {
        $start = $this->fixtureStart()->setTime(20, 0);
        $payload = $this->eventPayload('xs2-evening-kickoff');
        $payload['date_start'] = $start->format('Y-m-d\\TH:i:s');
        $payload['date_stop'] = $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s');
        $event = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($payload));

        $candidate = $this->localEvent(9010);
        $candidate['match_date'] = $start->copy()->subDay()->setTime(10, 0)->format('Y-m-d H:i:s');
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($event);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(9010, $mapping->m_id);
    }

    public function test_auto_map_queues_category_sync_and_venue_mapping(): void
    {
        Queue::fake([ResolvePendingXs2Listings::class, SyncXs2CategoriesForEvent::class]);
        $this->seedEventFlowMasterLocation();
        DB::table('stadium')->insert([
            's_id' => 500,
            'stadium_name' => 'Mestalla',
            'country' => 1,
            'city' => 100,
        ]);
        DB::table('match_info')->insert($this->localEvent(45, null, 500));
        $event = $this->xs2Event();

        $mapping = app(EventMappingService::class)->map($event);

        $this->assertSame('mapped', $mapping->status);
        Queue::assertPushed(SyncXs2CategoriesForEvent::class, fn (SyncXs2CategoriesForEvent $job): bool => $job->eventId === $event->id);
        $this->assertDatabaseHas('xs2_stadium_mappings', [
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
    }

    public function test_high_score_candidate_stays_pending_below_auto_map_threshold(): void
    {
        $candidate = $this->localEvent(43);
        $candidate['city'] = 'Manchester';
        DB::table('match_info')->insert($candidate);
        $mapping = app(EventMappingService::class)->map($this->xs2Event());

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertSame('automatic', $mapping->mapping_method);
        $this->assertGreaterThanOrEqual(65, (float) $mapping->match_score);
        $this->assertLessThan(100, (float) $mapping->match_score);
    }

    public function test_candidate_lookup_scores_normalized_values_without_raw_field_equality(): void
    {
        $candidate = $this->localEvent(431);
        $candidate['match_name'] = 'Alpha Football Club v Beta Football Club';
        $candidate['team_1'] = 'Alpha Football Club';
        $candidate['team_2'] = 'Beta Football Club';
        $candidate['city'] = 'Manchester';
        $candidate['tournament'] = 'English Premier League';
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($this->xs2Event('xs2-normalized-candidate'));

        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertSame('automatic', $mapping->mapping_method);
        $this->assertLessThan(100, (float) $mapping->match_score);
        $this->assertLessThan(100, (float) $mapping->match_details['best_match']['city_score'] ?? $mapping->match_details['city_score'] ?? 0);
    }

    public function test_candidate_scoring_resolves_legacy_reference_names(): void
    {
        DB::table('stadium')->insert(['s_id' => 101, 'stadium_name' => 'Stadium']);
        DB::table('cities')->insert(['id' => 201, 'name' => 'Greater London']);
        DB::table('tournament')->insert(['t_id' => 301, 'tournament_name' => 'Premier League']);
        DB::table('teams')->insert([
            ['id' => 401, 'team_name' => 'Alpha FC'],
            ['id' => 402, 'team_name' => 'Beta FC'],
        ]);
        DB::table('match_info_lang')->insert([
            'match_id' => 432,
            'match_name' => 'Alpha FC vs Beta FC',
            'language' => 'en',
            'store_id' => 13,
        ]);

        $candidate = $this->localEvent(432);
        $candidate['match_name'] = 'This local title must not be scored';
        $candidate['team_1'] = 401;
        $candidate['team_2'] = 402;
        $candidate['city'] = 201;
        $candidate['tournament'] = 301;
        $candidate['venue'] = 101;
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($this->xs2Event('xs2-reference-candidate'));

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame('automatic', $mapping->mapping_method);
        $this->assertSame(432, $mapping->m_id);
        $this->assertSame(100.0, (float) $mapping->match_details['event_name_score']);
        $this->assertSame(100.0, (float) $mapping->match_details['home_team_score']);
        $this->assertSame(100.0, (float) $mapping->match_details['away_team_score']);
        $this->assertSame(100.0, (float) $mapping->match_details['venue_score']);
        $this->assertSame(100.0, (float) $mapping->match_details['tournament_score']);
        $this->assertSame(100.0, (float) $mapping->match_score);
    }

    public function test_perfect_score_auto_map_cascades_category_mappings(): void
    {
        Queue::fake([ResolvePendingXs2Listings::class, SyncXs2CategoriesForEvent::class]);
        $this->seedEventFlowMasterLocation();
        $this->createEventFlowCategoryTables();
        DB::table('stadium')->insert([
            's_id' => 500,
            'stadium_name' => 'Stadium',
            'country' => 1,
            'city' => 100,
        ]);
        DB::table('stadium_seats')->insert([
            ['id' => 16, 'seat_category' => 'Category 1'],
        ]);
        DB::table('stadium_details')->insert([
            ['id' => 2001, 'stadium_id' => 500, 'full_block_name' => 'block-a', 'block_id' => 'a', 'category' => 16],
        ]);
        DB::table('match_info')->insert($this->localEvent(433, null, 500));
        $event = $this->xs2Event('xs2-perfect-cascade');

        $category = \App\Models\Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-perfect',
            'external_event_id' => 'xs2-perfect-cascade',
            'category_name' => 'Category 1',
            'raw_payload' => [],
        ]);
        \App\Models\Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => 'venue-1',
            'category_type' => 'grandstand',
        ]);

        $mapping = app(EventMappingService::class)->map($event);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(433, $mapping->m_id);
        $this->assertSame(100.0, (float) $mapping->match_score);
        $this->assertDatabaseHas('xs2_stadium_mappings', [
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
        $this->assertDatabaseHas('xs2_category_mappings', [
            'xs2_category_id' => $category->id,
            'status' => 'mapped',
            'confidence_score' => 100,
        ]);
        Queue::assertPushed(SyncXs2CategoriesForEvent::class, fn (SyncXs2CategoriesForEvent $job): bool => $job->eventId === $event->id);
    }

    public function test_auto_map_events_command_maps_perfect_matches(): void
    {
        DB::table('match_info')->insert($this->localEvent(434));
        $event = $this->xs2Event('xs2-command-perfect');

        Artisan::call('xs2:auto-map-events', ['--min-score' => 100]);

        $mapping = EventMapping::query()->where('xs2_event_id', $event->id)->first();
        $this->assertNotNull($mapping);
        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(434, $mapping->m_id);
        $this->assertSame(100.0, (float) $mapping->match_score);
    }

    public function test_celta_atletico_accent_and_de_variations_auto_map_at_one_hundred(): void
    {
        $start = $this->fixtureStart();
        $payload = $this->eventPayload('xs2-celta-atletico');
        $payload['event_name'] = 'Celta de Vigo vs Atlético Madrid';
        $payload['hometeam_name'] = 'Celta de Vigo';
        $payload['visiting_name'] = 'Atlético Madrid';
        $payload['tournament_name'] = 'La Liga';
        $payload['city'] = 'Vigo';
        $payload['venue_name'] = 'Estadio de Balaídos';
        $payload['date_start'] = $start->format('Y-m-d\\TH:i:s');
        $payload['date_stop'] = $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s');
        $event = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($payload));

        $candidate = $this->localEvent(436);
        $candidate['match_name'] = 'Celta Vigo vs Atletico Madrid';
        $candidate['team_1'] = 'Celta Vigo';
        $candidate['team_2'] = 'Atletico Madrid';
        $candidate['city'] = 'Vigo';
        $candidate['tournament'] = 'La Liga';
        $candidate['match_date'] = $start->format('Y-m-d H:i:s');
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($event);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(436, $mapping->m_id);
        $this->assertContains($mapping->mapping_method, ['exact', 'automatic']);
        $this->assertSame(100.0, (float) $mapping->match_score);
        if ($mapping->mapping_method === 'automatic') {
            $this->assertSame(100.0, (float) $mapping->match_details['event_name_score']);
            $this->assertSame(100.0, (float) $mapping->match_details['home_team_score']);
            $this->assertSame(100.0, (float) $mapping->match_details['away_team_score']);
        }
    }

    public function test_auto_map_events_command_maps_celta_atletico_accent_variations(): void
    {
        $start = $this->fixtureStart();
        $payload = $this->eventPayload('xs2-command-celta-atletico');
        $payload['event_name'] = 'Celta de Vigo vs Atlético Madrid';
        $payload['hometeam_name'] = 'Celta de Vigo';
        $payload['visiting_name'] = 'Atlético Madrid';
        $payload['tournament_name'] = 'La Liga';
        $payload['city'] = 'Vigo';
        $payload['date_start'] = $start->format('Y-m-d\\TH:i:s');
        $payload['date_stop'] = $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s');
        $event = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($payload));

        $candidate = $this->localEvent(437);
        $candidate['match_name'] = 'Celta Vigo vs Atletico Madrid';
        $candidate['team_1'] = 'Celta Vigo';
        $candidate['team_2'] = 'Atletico Madrid';
        $candidate['city'] = 'Vigo';
        $candidate['tournament'] = 'La Liga';
        $candidate['match_date'] = $start->format('Y-m-d H:i:s');
        DB::table('match_info')->insert($candidate);

        Artisan::call('xs2:auto-map-events', ['--min-score' => 100]);

        $mapping = EventMapping::query()->where('xs2_event_id', $event->id)->first();
        $this->assertNotNull($mapping);
        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(437, $mapping->m_id);
        $this->assertSame(100.0, (float) $mapping->match_score);
    }

    public function test_auto_map_events_command_leaves_imperfect_matches_pending(): void
    {
        $candidate = $this->localEvent(435);
        $candidate['city'] = 'Manchester';
        DB::table('match_info')->insert($candidate);
        $event = $this->xs2Event('xs2-command-pending');

        Artisan::call('xs2:auto-map-events', ['--min-score' => 100]);

        $mapping = EventMapping::query()->where('xs2_event_id', $event->id)->first();
        $this->assertNotNull($mapping);
        $this->assertSame('pending', $mapping->status);
        $this->assertNull($mapping->m_id);
        $this->assertLessThan(100, (float) $mapping->match_score);
    }

    public function test_medium_score_candidate_becomes_pending(): void
    {
        $candidate = $this->localEvent(44);
        $candidate['team_1'] = null;
        $candidate['team_2'] = null;
        DB::table('match_info')->insert($candidate);

        $mapping = app(EventMappingService::class)->map($this->xs2Event());

        $this->assertSame('pending', $mapping->status);
        $this->assertSame(44, $mapping->match_details['candidates'][0]['event_id']);
    }

    public function test_no_match_becomes_pending_without_creating_a_local_event(): void
    {
        $mapping = app(EventMappingService::class)->map($this->xs2Event());

        $this->assertSame('pending', $mapping->status);
        $this->assertSame('automatic', $mapping->mapping_method);
        $this->assertNull($mapping->m_id);
        $this->assertSame(0.0, (float) $mapping->match_score);
        $this->assertSame('no_reliable_local_candidate', $mapping->match_details['reason']);
        $this->assertTrue($mapping->match_details['requires_local_reference_resolution']);
        $this->assertSame('Alpha FC', $mapping->match_details['local_references']['home_team']['name']);
        $this->assertSame('Beta FC', $mapping->match_details['local_references']['away_team']['name']);
        $this->assertDatabaseMissing('match_info', ['xs2event_id' => 'xs2-123']);
    }

    public function test_manual_and_ignored_mappings_are_not_overwritten(): void
    {
        $event = $this->xs2Event();
        $manual = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 999, 'status' => 'mapped', 'mapping_method' => 'manual']);
        $this->assertSame($manual->id, app(EventMappingService::class)->map($event)->id);

        $manual->update(['m_id' => null, 'status' => 'ignored', 'mapping_method' => 'manual']);
        $this->assertSame('ignored', app(EventMappingService::class)->map($event)->status);
    }

    public function test_repeated_unmatched_event_mapping_does_not_create_match_info(): void
    {
        $event = $this->xs2Event();
        app(EventMappingService::class)->map($event);
        app(EventMappingService::class)->map($event);

        $this->assertSame(0, DB::table('match_info')->where('xs2event_id', 'xs2-123')->count());
        $this->assertSame(1, EventMapping::query()->where('xs2_event_id', $event->id)->count());
    }

    public function test_command_queues_a_job_and_admin_api_requires_authentication(): void
    {
        Queue::fake();
        Artisan::call('xs2:sync-events', ['--sport' => 'soccer']);
        Queue::assertPushed(SyncXs2EventsJob::class);
        $this->getJson('/api/admin/xs2/event-mappings')->assertUnauthorized();
    }

    public function test_admin_can_view_sanitized_sync_status_for_each_configured_sport(): void
    {
        config()->set('services.xs2.sports', ['soccer', 'tennis']);
        Xs2SyncState::create([
            'resource' => 'events:soccer',
            'status' => 'failed',
            'last_attempted_at' => '2026-07-24 08:00:00',
            'last_successful_at' => '2026-07-23 08:00:00',
            'last_error' => 'XS2_API_KEY=test-key upstream trace',
            'metadata' => ['events_received' => 350, 'events_mapped' => 40, 'private_token' => 'do-not-return'],
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/xs2/sync-status')
            ->assertOk()
            ->assertJsonPath('data.0.resource', 'events:soccer')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.metadata.events_received', 350)
            ->assertJsonPath('data.0.metadata.events_created', 0)
            ->assertJsonPath('data.0.last_error', 'The most recent synchronization failed. Review the application logs.')
            ->assertJsonPath('data.1.resource', 'events:tennis')
            ->assertJsonPath('data.1.status', 'never_run')
            ->assertJsonMissingPath('data.0.metadata.private_token')
            ->assertDontSee('test-key');
    }

    public function test_admin_can_queue_a_configured_sport_without_dispatching_duplicates(): void
    {
        Queue::fake();
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/xs2/sync-events', ['sport' => 'soccer', 'full' => false])
            ->assertAccepted()
            ->assertJsonPath('data.sport', 'soccer')
            ->assertJsonPath('data.full', false);
        $this->withToken($token)->postJson('/api/admin/xs2/sync-events', ['sport' => 'soccer', 'full' => false])
            ->assertOk()
            ->assertJsonPath('message', 'An XS2 event synchronization is already queued or running for this sport.');
        $this->withToken($token)->postJson('/api/admin/xs2/sync-events', ['sport' => 'soccer', 'full' => true])
            ->assertAccepted()
            ->assertJsonPath('data.sport', 'soccer')
            ->assertJsonPath('data.full', true);
        $this->withToken($token)->postJson('/api/admin/xs2/sync-events', ['sport' => 'cricket'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The selected sport is invalid.')
            ->assertJsonStructure(['errors' => ['sport']]);

        Queue::assertPushed(SyncXs2EventsJob::class, 2);
    }

    public function test_admin_can_queue_dedicated_venue_and_category_synchronizations(): void
    {
        Queue::fake();
        $event = $this->xs2Event('xs2-inventory-mapping-sync');
        EventMapping::create([
            'xs2_event_id' => $event->id,
            'm_id' => 99,
            'status' => 'mapped',
            'mapping_method' => 'manual',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/xs2/sync-venues')
            ->assertAccepted()
            ->assertJsonPath('data.events', 1);
        $this->withToken($token)->postJson('/api/admin/xs2/sync-categories')
            ->assertAccepted()
            ->assertJsonPath('data.events', 1);

        Queue::assertPushed(SyncXs2VenueForEvent::class, fn (SyncXs2VenueForEvent $job): bool => $job->eventId === $event->id);
        Queue::assertPushed(SyncXs2CategoriesForEvent::class, fn (SyncXs2CategoriesForEvent $job): bool => $job->eventId === $event->id);
    }

    public function test_admin_can_get_mapping_summary_without_loading_mapping_records(): void
    {
        foreach (['mapped', 'pending', 'created', 'ignored'] as $status) {
            $event = $this->xs2Event("xs2-summary-{$status}");
            EventMapping::create([
                'xs2_event_id' => $event->id,
                'status' => $status,
                'mapping_method' => 'automatic',
            ]);
        }
        $ticketedEvent = $this->xs2Event('xs2-summary-tickets');
        EventMapping::create(['xs2_event_id' => $ticketedEvent->id, 'status' => 'mapped', 'mapping_method' => 'automatic']);
        Xs2Ticket::create(['xs2_event_id' => $ticketedEvent->id, 'external_ticket_id' => 'summary-available', 'ticket_status' => 'available', 'stock' => 3, 'net_rate' => 10000, 'currency_code' => 'EUR']);
        Xs2Ticket::create(['xs2_event_id' => $ticketedEvent->id, 'external_ticket_id' => 'summary-sold', 'ticket_status' => 'sold', 'stock' => 0, 'net_rate' => 5000, 'currency_code' => 'EUR']);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;
        $day = $this->fixtureStart()->toDateString();

        $this->withToken($token)->getJson("/api/admin/xs2/event-mappings/summary?sport=soccer&date_from={$day}&date_to={$day}")
            ->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.mapped', 2)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.total_listings', 2)
            ->assertJsonPath('data.total_tickets', 3)
            ->assertJsonPath('data.total_inventory_value.by_currency.EUR', 300);
    }

    public function test_synchronous_command_returns_import_summary(): void
    {
        Http::fake(['*' => Http::response(['events' => [], 'pagination' => []])]);

        $this->artisan('xs2:sync-events', ['--sport' => 'soccer', '--sync' => true])
            ->expectsOutputToContain('pages_processed')
            ->assertSuccessful();
    }

    public function test_authenticated_admin_can_map_and_ignore_a_mapping(): void
    {
        DB::table('match_info')->insert($this->localEvent(45));
        $event = $this->xs2Event();
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'status' => 'pending', 'mapping_method' => 'automatic']);
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/map", ['event_id' => 45])
            ->assertOk()
            ->assertJsonPath('message', 'Event mapping updated successfully.')
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.local_event.id', 45)
            ->assertJsonMissingPath('data.xs2_event.id');
        $this->withToken($token)->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/ignore")
            ->assertOk()->assertJsonPath('data.status', 'ignored');
    }

    public function test_manual_map_reassigns_local_event_from_an_existing_xs2_mapping(): void
    {
        DB::table('match_info')->insert($this->localEvent(451));
        $owner = $this->xs2Event('xs2-canonical-owner');
        EventMapping::create([
            'xs2_event_id' => $owner->id,
            'm_id' => 451,
            'status' => 'mapped',
            'mapping_method' => 'manual',
        ]);
        $competingEvent = $this->xs2Event('xs2-competing-event');

        $pending = app(EventMappingService::class)->map($competingEvent);

        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->m_id);
        $this->assertSame('local_event_already_has_public_mapping', $pending->match_details['reason']);
        $this->assertSame(451, $pending->match_details['candidate_event_id']);

        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;
        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$pending->id}/map", ['event_id' => 451])
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.local_event.id', 451);

        $this->assertSame('pending', EventMapping::query()->where('xs2_event_id', $owner->id)->value('status'));
        $this->assertNull(EventMapping::query()->where('xs2_event_id', $owner->id)->value('m_id'));
        $this->assertSame(1, EventMapping::query()
            ->where('m_id', 451)
            ->whereIn('status', ['mapped', 'created'])
            ->count());
    }

    public function test_database_enforces_one_public_mapping_per_local_event(): void
    {
        $migration = require database_path('migrations/2026_07_29_010000_enforce_one_public_mapping_per_local_event.php');
        $migration->up();

        $first = $this->xs2Event('xs2-public-constraint-first');
        EventMapping::create([
            'xs2_event_id' => $first->id,
            'm_id' => 452,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);

        $second = $this->xs2Event('xs2-public-constraint-second');
        try {
            EventMapping::create([
                'xs2_event_id' => $second->id,
                'm_id' => 452,
                'status' => 'created',
                'mapping_method' => 'created',
            ]);
            $this->fail('A second public mapping for the same local event should be rejected by the database.');
        } catch (QueryException) {
            // Expected: the SQLite filtered unique index is the test-time
            // equivalent of MySQL's generated-column unique constraint.
        }

        EventMapping::create([
            'xs2_event_id' => $second->id,
            'm_id' => 452,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $this->assertSame(1, EventMapping::query()
            ->where('m_id', 452)
            ->whereIn('status', ['mapped', 'created'])
            ->count());
    }

    public function test_public_event_routes_use_the_local_event_id(): void
    {
        DB::table('match_info')->insert($this->localEvent(46));

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.0.id', 46)
            ->assertJsonMissingPath('data.0.m_id')
            ->assertJsonMissingPath('data.0.xs2event_id');

        $this->getJson('/api/events/46')
            ->assertOk()
            ->assertJsonPath('message', 'Event retrieved successfully.')
            ->assertJsonPath('data.id', 46)
            ->assertJsonPath('data.name', 'Alpha FC vs Beta FC')
            ->assertJsonMissingPath('data.m_id');
    }

    public function test_public_event_routes_resolve_legacy_reference_names(): void
    {
        DB::table('teams')->insert([
            ['id' => 23, 'team_name' => 'Manchester United'],
            ['id' => 8, 'team_name' => 'Atletico Madrid'],
        ]);
        DB::table('stadium')->insert(['s_id' => 1499, 'stadium_name' => 'Old Trafford']);
        DB::table('cities')->insert(['id' => 48426, 'name' => 'Manchester']);
        DB::table('tournament')->insert(['t_id' => 47, 'tournament_name' => 'Club Friendly']);
        DB::table('match_info')->insert([
            ...$this->localEvent(60),
            'team_1' => '23',
            'team_2' => '8',
            'venue' => 1499,
            'city' => '48426',
            'tournament' => '47',
        ]);

        $this->getJson('/api/events/60')
            ->assertOk()
            ->assertJsonPath('data.venue.id', 1499)
            ->assertJsonPath('data.venue.name', 'Old Trafford')
            ->assertJsonPath('data.venue.city', 'Manchester')
            ->assertJsonPath('data.tournament.name', 'Club Friendly')
            ->assertJsonPath('data.home_team.name', 'Manchester United')
            ->assertJsonPath('data.away_team.name', 'Atletico Madrid')
            ->assertJsonPath('data.xs2_mapped', false)
            ->assertJsonPath('data.xs2_mapping_id', null)
            ->assertJsonPath('data.xs2_event_id', null)
            ->assertJsonPath('data.xs2_event_name', null)
            ->assertJsonPath('data.inventory.currency', 'EUR');
    }

    public function test_public_event_list_enriches_canonical_events_without_exposing_xs2_records(): void
    {
        DB::table('match_info')->insert($this->localEvent(47, 'xs2-public'));
        $xs2Event = $this->xs2Event('xs2-public');
        $xs2Event->update([
            'event_status' => 'active',
            'venue_name' => 'Emirates Stadium',
            'city' => 'London',
            'iso_country' => 'GBR',
            'sport_type' => 'soccer',
            'event_description' => 'A league fixture.',
            'number_of_tickets' => 42,
            'min_ticket_price_eur' => 120,
            'max_ticket_price_eur' => 250,
        ]);
        EventMapping::create([
            'xs2_event_id' => $xs2Event->id,
            'm_id' => 47,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);

        $mappingId = EventMapping::query()->where('m_id', 47)->value('id');

        $this->getJson('/api/events?sport=soccer&country=GBR&venue=Emirates&has_inventory=true')
            ->assertOk()
            ->assertJsonPath('data.0.id', 47)
            ->assertJsonPath('data.0.sport_type', 'soccer')
            ->assertJsonPath('data.0.venue.name', 'Emirates Stadium')
            ->assertJsonPath('data.0.home_team.name', 'Alpha FC')
            ->assertJsonPath('data.0.inventory.has_xs2_inventory', true)
            ->assertJsonPath('data.0.inventory.ticket_count', 42)
            ->assertJsonPath('data.0.inventory.minimum_price', 120)
            ->assertJsonPath('data.0.inventory.maximum_price', 250)
            ->assertJsonPath('data.0.inventory.currency', 'EUR')
            ->assertJsonPath('data.0.xs2_mapped', true)
            ->assertJsonPath('data.0.xs2_mapping_id', $mappingId)
            ->assertJsonPath('data.0.xs2_event_id', 'xs2-public')
            ->assertJsonPath('data.0.xs2_event_name', 'Alpha FC vs Beta FC')
            ->assertJsonMissingPath('data.0.xs2_event')
            ->assertJsonMissingPath('data.0.raw_payload');
    }

    public function test_public_event_details_return_not_found_for_unavailable_mapped_events(): void
    {
        DB::table('match_info')->insert($this->localEvent(48, 'xs2-cancelled'));
        $xs2Event = $this->xs2Event('xs2-cancelled');
        $xs2Event->update(['event_status' => 'cancelled']);
        EventMapping::create([
            'xs2_event_id' => $xs2Event->id,
            'm_id' => 48,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);

        $this->getJson('/api/events/48')
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_public_event_routes_hide_events_missing_from_the_supplier_snapshot(): void
    {
        DB::table('match_info')->insert($this->localEvent(481, 'xs2-missing-public-event'));
        $xs2Event = $this->xs2Event('xs2-missing-public-event');
        $xs2Event->update(['missing_since' => now()]);
        EventMapping::create([
            'xs2_event_id' => $xs2Event->id,
            'm_id' => 481,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/events/481')
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_admin_mapping_list_returns_normalized_suggestions_and_filters(): void
    {
        DB::table('match_info')->insert($this->localEvent(49));
        $event = $this->xs2Event('xs2-mapping-list');
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
            'match_score' => 78.4,
            'match_details' => [
                'event_name_score' => 90,
                'date_score' => 100,
                'final_score' => 78.4,
                'candidates' => [['event_id' => 49, 'score' => 78.4]],
            ],
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?status=pending&mapping_method=automatic&has_local_event=false&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $mapping->id)
            ->assertJsonPath('data.0.xs2_event.name', 'Alpha FC vs Beta FC')
            ->assertJsonPath('data.0.suggested_events.0.event_id', 49)
            ->assertJsonPath('data.0.suggested_events.0.name', 'Alpha FC vs Beta FC')
            ->assertJsonPath('data.0.match_details.final_score', 78.4)
            ->assertJsonMissingPath('data.0.xs2_event.raw_payload')
            ->assertJsonPath('data.0.xs2_event.sync.last_inventory_sync_at', null)
            ->assertJsonPath('data.0.xs2_event.sync.inventory_sync_status', null);
    }

    public function test_admin_mapping_list_exposes_inventory_sync_timestamps(): void
    {
        $event = $this->xs2Event('xs2-inventory-sync-list');
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);
        $fullSyncAt = CarbonImmutable::parse('2026-08-14 09:00:00');
        $incrementalSyncAt = CarbonImmutable::parse('2026-08-14 14:30:00');
        DB::table('xs2_event_inventory_sync_states')->insert([
            'xs2_event_id' => $event->id,
            'tickets_last_full_sync_at' => $fullSyncAt,
            'tickets_last_incremental_sync_at' => $incrementalSyncAt,
            'tickets_sync_status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $mapping->id)
            ->assertJsonPath('data.0.xs2_event.sync.last_full_sync_at', $fullSyncAt->toIso8601String())
            ->assertJsonPath('data.0.xs2_event.sync.last_incremental_sync_at', $incrementalSyncAt->toIso8601String())
            ->assertJsonPath('data.0.xs2_event.sync.last_inventory_sync_at', $incrementalSyncAt->toIso8601String())
            ->assertJsonPath('data.0.xs2_event.sync.inventory_sync_status', 'completed');
    }

    public function test_admin_mapping_list_filters_by_local_event_ids(): void
    {
        DB::table('match_info')->insert($this->localEvent(551));
        DB::table('match_info')->insert($this->localEvent(552));
        $wanted = EventMapping::create([
            'xs2_event_id' => $this->xs2Event('xs2-local-filter-wanted')->id,
            'm_id' => 551,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);
        EventMapping::create([
            'xs2_event_id' => $this->xs2Event('xs2-local-filter-excluded')->id,
            'm_id' => 552,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?'.http_build_query(['local_event_ids' => [551, 9999]]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id)
            ->assertJsonPath('data.0.local_event.id', 551);
    }

    public function test_admin_mapping_list_filters_by_venue_id(): void
    {
        $wantedEvent = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize(
            array_merge($this->eventPayload('xs2-venue-filter-wanted'), ['venue_id' => 'venue-wanted']),
        ));
        $otherEvent = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize(
            array_merge($this->eventPayload('xs2-venue-filter-other'), ['venue_id' => 'venue-other']),
        ));
        $wanted = EventMapping::create([
            'xs2_event_id' => $wantedEvent->id,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);
        EventMapping::create([
            'xs2_event_id' => $otherEvent->id,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?'.http_build_query(['venue_id' => 'venue-wanted']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_admin_can_filter_event_mappings_by_league_and_list_distinct_leagues(): void
    {
        $wantedEvent = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize(
            array_merge($this->eventPayload('xs2-league-filter-wanted'), ['tournament_name' => 'Premier League']),
        ));
        $otherEvent = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize(
            array_merge($this->eventPayload('xs2-league-filter-other'), ['tournament_name' => 'La Liga']),
        ));
        $wanted = EventMapping::create([
            'xs2_event_id' => $wantedEvent->id,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);
        EventMapping::create([
            'xs2_event_id' => $otherEvent->id,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?'.http_build_query(['tournament' => 'Premier League']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings/tournaments')
            ->assertOk()
            ->assertJsonPath('data', ['La Liga', 'Premier League']);
    }

    public function test_admin_mapping_list_filters_by_has_tickets_and_reports_ticket_count(): void
    {
        $withTickets = $this->xs2Event('xs2-has-tickets');
        Xs2Ticket::create(['xs2_event_id' => $withTickets->id, 'external_ticket_id' => 'ticket-1', 'ticket_status' => 'available', 'stock' => 4]);
        Xs2Ticket::create(['xs2_event_id' => $withTickets->id, 'external_ticket_id' => 'ticket-2', 'ticket_status' => 'available', 'stock' => 2]);
        $mappingWithTickets = EventMapping::create([
            'xs2_event_id' => $withTickets->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $withoutTickets = $this->xs2Event('xs2-no-tickets');
        EventMapping::create([
            'xs2_event_id' => $withoutTickets->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?has_tickets=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mappingWithTickets->id)
            ->assertJsonPath('data.0.xs2_event.ticket_count', 2)
            ->assertJsonPath('data.0.xs2_event.listings_count', 2)
            ->assertJsonPath('data.0.xs2_event.ticket_quantity', 6);

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?has_tickets=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.xs2_event.ticket_count', 0)
            ->assertJsonPath('data.0.xs2_event.listings_count', 0)
            ->assertJsonPath('data.0.xs2_event.ticket_quantity', 0);
    }

    public function test_admin_mapping_list_filters_by_currency_and_updates_summary(): void
    {
        $eurEvent = $this->xs2Event('xs2-currency-eur');
        Xs2Ticket::create([
            'xs2_event_id' => $eurEvent->id,
            'external_ticket_id' => 'ticket-eur',
            'ticket_status' => 'available',
            'stock' => 2,
            'net_rate' => 10000,
            'currency_code' => 'EUR',
        ]);
        $mappingEur = EventMapping::create([
            'xs2_event_id' => $eurEvent->id,
            'status' => 'mapped',
            'mapping_method' => 'automatic',
        ]);

        $gbpEvent = $this->xs2Event('xs2-currency-gbp');
        Xs2Ticket::create([
            'xs2_event_id' => $gbpEvent->id,
            'external_ticket_id' => 'ticket-gbp',
            'ticket_status' => 'available',
            'stock' => 4,
            'net_rate' => 5000,
            'currency_code' => 'GBP',
        ]);
        EventMapping::create([
            'xs2_event_id' => $gbpEvent->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $mixedEvent = $this->xs2Event('xs2-currency-mixed');
        Xs2Ticket::create([
            'xs2_event_id' => $mixedEvent->id,
            'external_ticket_id' => 'ticket-mixed-eur',
            'ticket_status' => 'available',
            'stock' => 1,
            'net_rate' => 2000,
            'currency_code' => 'EUR',
        ]);
        Xs2Ticket::create([
            'xs2_event_id' => $mixedEvent->id,
            'external_ticket_id' => 'ticket-mixed-usd',
            'ticket_status' => 'available',
            'stock' => 3,
            'net_rate' => 3000,
            'currency_code' => 'USD',
        ]);
        EventMapping::create([
            'xs2_event_id' => $mixedEvent->id,
            'status' => 'created',
            'mapping_method' => 'automatic',
        ]);

        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?currency_code=EUR')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $mappingEur->id]);

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings/summary?currency_code=EUR')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.mapped', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.total_listings', 2)
            ->assertJsonPath('data.total_tickets', 3)
            ->assertJsonPath('data.total_inventory_value.by_currency.EUR', 220);

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings/currencies')
            ->assertOk()
            ->assertJsonPath('data', ['EUR', 'GBP', 'USD']);
    }

    public function test_admin_mapping_list_filters_by_ticket_flags_and_guest_validation(): void
    {
        $withFlags = $this->xs2Event('xs2-with-flags');
        Xs2Ticket::create([
            'xs2_event_id' => $withFlags->id,
            'external_ticket_id' => 'ticket-flags',
            'ticket_status' => 'available',
            'stock' => 2,
            'flags' => ['pairs_only'],
        ]);
        $mappingWithFlags = EventMapping::create([
            'xs2_event_id' => $withFlags->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $withGuestValidation = $this->xs2Event('xs2-with-guest-validation');
        Xs2Ticket::create([
            'xs2_event_id' => $withGuestValidation->id,
            'external_ticket_id' => 'ticket-guest-flag',
            'ticket_status' => 'available',
            'stock' => 2,
            'flags' => ['no_awayteam_nationality_allowed'],
        ]);
        $mappingWithGuestValidation = EventMapping::create([
            'xs2_event_id' => $withGuestValidation->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $withGuestRequirements = $this->xs2Event('xs2-with-guest-requirements');
        Xs2Ticket::create([
            'xs2_event_id' => $withGuestRequirements->id,
            'external_ticket_id' => 'ticket-guest-reqs',
            'ticket_status' => 'available',
            'stock' => 2,
            'guest_data_requirements' => ['passport_number'],
        ]);
        $mappingWithGuestRequirements = EventMapping::create([
            'xs2_event_id' => $withGuestRequirements->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $plainTickets = $this->xs2Event('xs2-plain-tickets');
        Xs2Ticket::create([
            'xs2_event_id' => $plainTickets->id,
            'external_ticket_id' => 'ticket-plain',
            'ticket_status' => 'available',
            'stock' => 2,
            'flags' => [],
        ]);
        EventMapping::create([
            'xs2_event_id' => $plainTickets->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $ticketFlagsResponse = $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?has_ticket_flags=true')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$mappingWithFlags->id, $mappingWithGuestValidation->id],
            collect($ticketFlagsResponse->json('data'))->pluck('id')->all(),
        );

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?has_ticket_flags=false')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $guestValidationResponse = $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?has_guest_validation=true')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$mappingWithGuestValidation->id, $mappingWithGuestRequirements->id],
            collect($guestValidationResponse->json('data'))->pluck('id')->all(),
        );

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?has_guest_validation=false')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_mapping_list_filters_by_specific_ticket_flags(): void
    {
        $pairsOnly = $this->xs2Event('xs2-pairs-only');
        Xs2Ticket::create([
            'xs2_event_id' => $pairsOnly->id,
            'external_ticket_id' => 'ticket-pairs-only',
            'ticket_status' => 'available',
            'stock' => 4,
            'flags' => ['pairs_only'],
        ]);
        $mappingPairsOnly = EventMapping::create([
            'xs2_event_id' => $pairsOnly->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $packageRate = $this->xs2Event('xs2-package-rate');
        Xs2Ticket::create([
            'xs2_event_id' => $packageRate->id,
            'external_ticket_id' => 'ticket-package-rate',
            'ticket_status' => 'available',
            'stock' => 3,
            'flags' => ['package_rate'],
        ]);
        $mappingPackageRate = EventMapping::create([
            'xs2_event_id' => $packageRate->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $mixedFlags = $this->xs2Event('xs2-mixed-flags');
        Xs2Ticket::create([
            'xs2_event_id' => $mixedFlags->id,
            'external_ticket_id' => 'ticket-mixed',
            'ticket_status' => 'available',
            'stock' => 2,
            'flags' => ['pairs_only', 'no_max_minus_1'],
        ]);
        $mappingMixedFlags = EventMapping::create([
            'xs2_event_id' => $mixedFlags->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);

        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $pairsOnlyResponse = $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?'.http_build_query(['ticket_flags' => ['pairs_only']]))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$mappingPairsOnly->id, $mappingMixedFlags->id],
            collect($pairsOnlyResponse->json('data'))->pluck('id')->all(),
        );

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?'.http_build_query(['ticket_flags' => ['package_rate']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mappingPackageRate->id);

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?'.http_build_query(['ticket_flags' => ['pairs_only', 'package_rate']]))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->withToken($token)
            ->getJson('/api/admin/xs2/event-mappings?'.http_build_query(['ticket_flags' => ['no_max_minus_1']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.xs2_event.external_event_id', 'xs2-mixed-flags');
    }

    public function test_admin_event_search_keeps_unmapped_local_events_when_given_an_xs2_sport(): void
    {
        DB::table('match_info')->insert($this->localEvent(50));
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/events/search?search=Alpha&sport=soccer&limit=20')
            ->assertOk()
            ->assertJsonPath('data.0.id', 50)
            ->assertJsonPath('data.0.name', 'Alpha FC vs Beta FC')
            ->assertJsonMissingPath('data.0.m_id');
    }

    public function test_admin_can_reopen_a_manual_mapping_without_removing_its_local_event(): void
    {
        DB::table('match_info')->insert($this->localEvent(51));
        $event = $this->xs2Event('xs2-reopen');
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'm_id' => 51,
            'status' => 'mapped',
            'mapping_method' => 'manual',
            'reviewed_by' => User::factory()->create(['user_type' => 6])->id,
            'reviewed_at' => now(),
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.mapping_method', 'automatic')
            ->assertJsonPath('data.local_event.id', 51)
            ->assertJsonPath('data.reviewed_by', null);
    }

    public function test_admin_can_create_a_local_event_from_a_pending_mapping(): void
    {
        $event = $this->xs2Event('xs2-create-local');
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/create-event")
            ->assertOk()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.mapping_method', 'created')
            ->assertJsonPath('data.local_event.name', 'Alpha FC vs Beta FC');

        $this->assertDatabaseHas('match_info', ['xs2event_id' => 'xs2-create-local']);
        $this->assertDatabaseHas('match_info', [
            'xs2event_id' => 'xs2-create-local',
            'team_1' => null,
            'team_2' => null,
            'city' => null,
            'tournament' => null,
        ]);
    }

    public function test_admin_create_event_rejects_unknown_local_reference_ids(): void
    {
        $event = $this->xs2Event('xs2-invalid-local-reference');
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/create-event", ['home_team_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('home_team_id');

        $this->assertDatabaseMissing('match_info', ['xs2event_id' => 'xs2-invalid-local-reference']);
        $this->assertSame('pending', $mapping->fresh()->status);
    }

    public function test_admin_cannot_create_a_local_event_without_an_xs2_start_date(): void
    {
        $payload = $this->eventPayload('xs2-create-missing-start');
        $payload['date_start'] = null;
        $event = Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($payload));
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/create-event")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_start_local');

        $this->assertDatabaseMissing('match_info', ['xs2event_id' => 'xs2-create-missing-start']);
        $this->assertSame('pending', $mapping->fresh()->status);
    }

    public function test_created_mapping_cannot_be_reopened_and_manual_recalculation_requires_force(): void
    {
        DB::table('match_info')->insert($this->localEvent(52));
        $event = $this->xs2Event('xs2-created');
        $created = EventMapping::create([
            'xs2_event_id' => $event->id,
            'm_id' => 52,
            'status' => 'created',
            'mapping_method' => 'created',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$created->id}/reopen")
            ->assertConflict();

        DB::table('match_info')->insert($this->localEvent(53));
        $manualEvent = $this->xs2Event('xs2-manual');
        $manual = EventMapping::create([
            'xs2_event_id' => $manualEvent->id,
            'm_id' => 53,
            'status' => 'mapped',
            'mapping_method' => 'manual',
        ]);

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$manual->id}/recalculate")
            ->assertConflict();
        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$manual->id}/recalculate", ['force' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped');
    }

    public function test_mapping_mutations_reject_stale_or_disallowed_transitions(): void
    {
        DB::table('match_info')->insert($this->localEvent(54));
        $event = $this->xs2Event('xs2-transition-conflict');
        $mapping = EventMapping::create([
            'xs2_event_id' => $event->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/create-event")
            ->assertOk()
            ->assertJsonPath('data.status', 'created');

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/create-event")
            ->assertConflict();
        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/map", ['event_id' => 54])
            ->assertConflict();
        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/ignore")
            ->assertConflict();

        $ignoredEvent = $this->xs2Event('xs2-ignored-conflict');
        $ignored = EventMapping::create([
            'xs2_event_id' => $ignoredEvent->id,
            'status' => 'ignored',
            'mapping_method' => 'manual',
        ]);

        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$ignored->id}/recalculate", ['force' => true])
            ->assertConflict();
        $this->withToken($token)
            ->postJson("/api/admin/xs2/event-mappings/{$ignored->id}/map", ['event_id' => 54])
            ->assertConflict();

        $this->assertSame('ignored', app(EventMappingService::class)->map($ignoredEvent)->fresh()->status);
    }

    public function test_mapVenueForLocalEvent_links_stadium_from_local_event(): void
    {
        Queue::fake([ResolvePendingXs2Listings::class, SyncXs2CategoriesForEvent::class]);
        $this->seedEventFlowMasterLocation();
        DB::table('stadium')->insert([
            's_id' => 500,
            'stadium_name' => 'Stadium',
            'country' => 1,
            'city' => 100,
        ]);
        DB::table('match_info')->insert($this->localEvent(45, null, 500));
        $event = $this->xs2Event();
        $localEvent = \App\Models\MatchInfo::query()->findOrFail(45);

        $mapping = app(\App\Services\Mapping\StadiumMappingService::class)
            ->mapVenueForLocalEvent($event, $localEvent);

        $this->assertNotNull($mapping);
        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(500, (int) $mapping->stadium_id);
    }

    public function test_manual_map_also_maps_unmapped_venue_from_local_event_stadium(): void
    {
        Queue::fake([ResolvePendingXs2Listings::class, SyncXs2CategoriesForEvent::class]);
        $this->seedEventFlowMasterLocation();
        DB::table('stadium')->insert([
            's_id' => 500,
            'stadium_name' => 'Stadium',
            'country' => 1,
            'city' => 100,
        ]);
        DB::table('match_info')->insert($this->localEvent(45, null, 500));
        $event = $this->xs2Event();
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'status' => 'pending', 'mapping_method' => 'automatic']);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/map", ['event_id' => 45])
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.venue_mapping.status', 'mapped')
            ->assertJsonPath('data.venue_mapping.stadium.id', 500);

        $venue = Xs2Venue::query()->where('external_venue_id', 'venue-1')->first();
        $this->assertNotNull($venue);
        $this->assertDatabaseHas('xs2_stadium_mappings', [
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
    }

    public function test_manual_map_skips_venue_mapping_when_already_mapped(): void
    {
        Queue::fake([ResolvePendingXs2Listings::class, SyncXs2CategoriesForEvent::class]);
        $this->seedEventFlowMasterLocation();
        DB::table('stadium')->insert([
            ['s_id' => 500, 'stadium_name' => 'Mapped Stadium', 'country' => 1, 'city' => 100],
            ['s_id' => 600, 'stadium_name' => 'Other Stadium', 'country' => 1, 'city' => 100],
        ]);
        DB::table('match_info')->insert($this->localEvent(45, null, 600));
        $venue = Xs2Venue::create([
            'external_venue_id' => 'venue-1',
            'venue_name' => 'Stadium',
            'city_name' => 'London',
            'country_code' => 'GB',
            'raw_payload' => [],
        ]);
        Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'resolved_country_id' => 1,
            'resolved_city_id' => 100,
            'status' => 'mapped',
            'manually_confirmed' => true,
            'mapped_at' => now(),
        ]);
        $event = $this->xs2Event();
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'status' => 'pending', 'mapping_method' => 'automatic']);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/map", ['event_id' => 45])
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.venue_mapping.status', 'mapped')
            ->assertJsonPath('data.venue_mapping.stadium.id', 500);

        $this->assertDatabaseHas('xs2_stadium_mappings', [
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
        $this->assertDatabaseMissing('xs2_stadium_mappings', [
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 600,
        ]);
    }

    public function test_recalculate_auto_map_also_maps_unmapped_venue_from_local_event_stadium(): void
    {
        Queue::fake([ResolvePendingXs2Listings::class, SyncXs2CategoriesForEvent::class]);
        $this->seedEventFlowMasterLocation();
        DB::table('stadium')->insert([
            's_id' => 500,
            'stadium_name' => 'Stadium',
            'country' => 1,
            'city' => 100,
        ]);
        DB::table('match_info')->insert($this->localEvent(45));
        $event = $this->xs2Event();
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'status' => 'pending', 'mapping_method' => 'automatic']);
        $token = User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/xs2/event-mappings/{$mapping->id}/recalculate")
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.local_event.id', 45);

        $venue = Xs2Venue::query()->where('external_venue_id', 'venue-1')->first();
        $this->assertNotNull($venue);
        $this->assertDatabaseHas('xs2_stadium_mappings', [
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
    }

    private function fixtureStart(): \Carbon\Carbon
    {
        return now()->addDays(30)->setTime(12, 0, 0);
    }

    /** @return array<string, mixed> */
    private function eventPayload(string $id = 'xs2-123'): array
    {
        $start = $this->fixtureStart();

        return [
            'event_id' => $id, 'event_name' => 'Alpha FC vs Beta FC',
            'date_start' => $start->format('Y-m-d\\TH:i:s'), 'date_stop' => $start->copy()->addHours(2)->format('Y-m-d\\TH:i:s'),
            'event_status' => 'notstarted', 'tournament_id' => 'tournament-1', 'tournament_name' => 'Premier League',
            'venue_id' => 'venue-1', 'venue_name' => 'Stadium', 'location_id' => 'location-1', 'city' => 'London',
            'iso_country' => 'GBR', 'latitude' => '51.5', 'longitude' => '-0.1', 'sport_type' => 'soccer',
            'season' => '26/27', 'tournament_type' => 'league', 'date_confirmed' => true,
            'hometeam_name' => 'Alpha FC', 'visiting_name' => 'Beta FC',
        ];
    }

    private function xs2Event(string $id = 'xs2-123'): Xs2Event
    {
        return Xs2Event::create(app(Xs2EventNormalizer::class)->normalize($this->eventPayload($id)));
    }

    /** @return array<string, mixed> */
    private function localEvent(int $id, ?string $xs2Id = null, ?int $venueId = null): array
    {
        return [
            'm_id' => $id,
            'match_name' => 'Alpha FC vs Beta FC',
            'team_1' => 'Alpha FC',
            'team_2' => 'Beta FC',
            'city' => 'London',
            'tournament' => 'Premier League',
            'match_date' => $this->fixtureStart()->format('Y-m-d H:i:s'),
            'xs2event_id' => $xs2Id,
            'venue' => $venueId,
        ];
    }

    private function seedEventFlowMasterLocation(): void
    {
        DB::table('countries')->insert([
            ['id' => 1, 'sortname' => 'GB', 'name' => 'United Kingdom'],
        ]);
        DB::table('states')->insert([
            ['id' => 10, 'country_id' => 1, 'name' => 'England'],
        ]);
        DB::table('cities')->insert([
            ['id' => 100, 'state_id' => 10, 'name' => 'London'],
        ]);
    }

    private function createEventFlowTables(): void
    {
        foreach (['personal_access_tokens', 'xs2_tickets', 'xs2_stadium_mappings', 'xs2_venues', 'event_mappings', 'match_info_lang', 'match_info', 'teams', 'tournament', 'cities', 'states', 'countries', 'stadium', 'xs2_event_inventory_sync_states', 'xs2_sync_states', 'xs2_events', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
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
        Schema::create('xs2_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('resource')->unique();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->string('status')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_venues', function (Blueprint $table): void {
            $table->id();
            $table->string('external_venue_id')->unique();
            $table->string('venue_name')->nullable();
            $table->string('city_name')->nullable();
            $table->string('country_name')->nullable();
            $table->string('country_code', 10)->nullable();
            $table->json('raw_payload');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_stadium_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_venue_id');
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('resolved_country_id')->nullable();
            $table->unsignedBigInteger('resolved_city_id')->nullable();
            $table->string('status')->default('pending_country_resolution');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('mapping_method', 50)->nullable();
            $table->json('matched_fields')->nullable();
            $table->json('candidate_scores')->nullable();
            $table->boolean('manually_confirmed')->default(false);
            $table->timestamp('mapped_at')->nullable();
            $table->text('mapping_error')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->unsignedBigInteger('face_value')->nullable();
            $table->unsignedBigInteger('package_price')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->json('flags')->nullable();
            $table->json('guest_data_requirements')->nullable();
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
        Schema::create('stadium', function (Blueprint $table): void {
            $table->increments('s_id');
            $table->string('stadium_name');
            $table->unsignedInteger('country')->nullable();
            $table->unsignedInteger('city')->nullable();
        });
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
            $table->unsignedInteger('state_id')->nullable();
            $table->string('name');
        });
        Schema::create('tournament', function (Blueprint $table): void {
            $table->increments('t_id');
            $table->string('tournament_name');
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('team_name');
        });
        Schema::create('match_info_lang', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('match_id');
            $table->string('match_name');
            $table->string('language', 2);
            $table->unsignedInteger('store_id')->default(13);
        });
        Schema::create('match_info', function (Blueprint $table): void {
            $table->increments('m_id');
            $table->string('match_name');
            $table->string('team_1')->nullable();
            $table->string('team_2')->nullable();
            $table->string('city')->nullable();
            $table->string('tournament')->nullable();
            $table->unsignedInteger('venue')->nullable();
            $table->unsignedInteger('store_id')->default(13);
            $table->boolean('status')->default(true);
            $table->dateTime('match_date');
            $table->string('xs2event_id')->nullable();
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

    private function createEventFlowCategoryTables(): void
    {
        foreach (['xs2_category_mapping_details', 'xs2_category_mappings', 'xs2_category_contexts', 'xs2_categories', 'stadium_details', 'stadium_seats'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('stadium_seats', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('seat_category');
        });
        Schema::create('stadium_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('stadium_id');
            $table->string('full_block_name')->nullable();
            $table->string('block_id')->nullable();
            $table->unsignedInteger('category')->nullable();
        });
        Schema::create('xs2_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_category_id');
            $table->string('external_event_id');
            $table->string('category_name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('party_size_together')->default(false);
            $table->json('raw_payload');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_category_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->unique();
            $table->string('external_venue_id')->nullable();
            $table->string('category_type')->nullable();
            $table->string('slug')->nullable();
            $table->json('options')->nullable();
            $table->boolean('on_svg')->nullable();
            $table->timestamp('external_created_at')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->unique();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->unsignedBigInteger('stadium_detail_id')->nullable();
            $table->string('status');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('mapping_method', 50)->nullable();
            $table->json('matched_fields')->nullable();
            $table->json('candidate_scores')->nullable();
            $table->boolean('manually_confirmed')->default(false);
            $table->timestamp('mapped_at')->nullable();
            $table->text('mapping_error')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_category_mapping_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_mapping_id');
            $table->unsignedBigInteger('stadium_detail_id');
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('block')->nullable();
            $table->string('section')->nullable();
            $table->string('name')->nullable();
            $table->string('stadium_seat_name')->nullable();
            $table->timestamps();
        });
    }
}
