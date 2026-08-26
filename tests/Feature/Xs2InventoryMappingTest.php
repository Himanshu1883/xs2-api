<?php

namespace Tests\Feature;

use App\Jobs\DisableSellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\ResolvePendingXs2Listings;
use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\User;
use App\Models\Xs2Category;
use App\Models\Xs2CategoryContext;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2CategoryMappingDetail;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Models\Xs2Venue;
use App\Services\Mapping\CityResolver;
use App\Services\Mapping\CountryResolver;
use App\Services\Mapping\MappingTextNormalizer;
use App\Services\Mapping\StadiumCategoryMappingService;
use App\Services\Mapping\StadiumMappingService;
use App\Services\SellerApi\SellerApiClient;
use App\Services\Xs2\ListingPublishValidator;
use App\Services\Xs2\Xs2SellerListingTransformer;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class Xs2InventoryMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        config()->set('xs2.mapping.stadium_pending_threshold', 80);
        config()->set('xs2.mapping.category_auto_map_threshold', 95);
        config()->set('xs2.mapping.category_pending_threshold', 80);
        config()->set('xs2.mapping.category_hospitality_keyword_score', 20);
    }

    public function test_country_and_city_resolution_follow_the_country_state_city_hierarchy(): void
    {
        DB::table('countries')->insert([
            ['id' => 1, 'sortname' => 'US', 'name' => 'United States'],
            ['id' => 2, 'sortname' => 'CA', 'name' => 'Canada'],
        ]);
        DB::table('states')->insert([
            ['id' => 10, 'country_id' => 1, 'name' => 'Illinois'],
            ['id' => 20, 'country_id' => 2, 'name' => 'Ontario'],
        ]);
        DB::table('cities')->insert([
            ['id' => 100, 'state_id' => 10, 'name' => 'Springfield'],
            ['id' => 200, 'state_id' => 20, 'name' => 'Springfield'],
        ]);
        $venue = $this->venue(['country_code' => 'US', 'country_name' => 'United States', 'city_name' => 'Springfield']);

        $country = app(CountryResolver::class)->resolve($venue);
        $city = app(CityResolver::class)->resolve($venue, $country->country);

        $this->assertTrue($country->resolved);
        $this->assertSame(1, $country->countryId);
        $this->assertTrue($city->resolved);
        $this->assertSame(100, $city->cityId);
        $this->assertSame(0, Xs2StadiumMapping::count());
    }

    public function test_stadium_mapping_only_considers_stadiums_in_the_resolved_city(): void
    {
        $this->masterLocation();
        DB::table('stadium')->insert([
            ['s_id' => 500, 'stadium_name' => 'Old Ground', 'country' => 1, 'city' => 100],
            ['s_id' => 600, 'stadium_name' => 'Old Ground', 'country' => 2, 'city' => 200],
        ]);
        $venue = $this->venue(['venue_name' => 'Old Ground']);

        $mapping = app(StadiumMappingService::class)->resolve($venue);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame(500, (int) $mapping->stadium_id);
        $this->assertSame(100, (int) $mapping->resolved_city_id);
    }

    public function test_manual_stadium_mapping_is_never_overwritten_automatically(): void
    {
        $this->masterLocation();
        DB::table('stadium')->insert(['s_id' => 500, 'stadium_name' => 'A Different Stadium', 'country' => 1, 'city' => 100]);
        $venue = $this->venue(['venue_name' => 'Old Ground']);
        $mapping = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 999,
            'status' => 'mapped',
            'mapping_method' => 'manual',
            'manually_confirmed' => true,
        ]);

        $resolved = app(StadiumMappingService::class)->resolve($venue);

        $this->assertSame($mapping->id, $resolved->id);
        $this->assertSame(999, (int) $resolved->stadium_id);
        $this->assertTrue($resolved->manually_confirmed);
    }

    public function test_admin_can_filter_venues_by_league_and_list_distinct_leagues(): void
    {
        $matchingVenue = $this->venue(['external_venue_id' => 'venue-league-match']);
        $otherVenue = $this->venue(['external_venue_id' => 'venue-league-other']);
        $matchingStadium = Xs2StadiumMapping::create(['xs2_venue_id' => $matchingVenue->id, 'status' => 'unmatched']);
        Xs2StadiumMapping::create(['xs2_venue_id' => $otherVenue->id, 'status' => 'unmatched']);
        Xs2Event::create(['external_event_id' => 'event-league-match', 'venue_id' => $matchingVenue->external_venue_id, 'tournament_name' => 'Premier League']);
        Xs2Event::create(['external_event_id' => 'event-league-other', 'venue_id' => $otherVenue->external_venue_id, 'tournament_name' => 'La Liga']);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/stadium-mappings?'.http_build_query(['tournament' => 'Premier League']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingStadium->id);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/stadium-mappings/tournaments')
            ->assertOk()
            ->assertJsonPath('data', ['La Liga', 'Premier League']);
    }

    public function test_stadium_mapping_seatsbroker_events_count_includes_future_events_only(): void
    {
        DB::table('stadium')->insert(['s_id' => 500, 'stadium_name' => 'Metropolitano Stadium', 'country' => 1, 'city' => 100]);
        $venue = $this->venue(['external_venue_id' => 'venue-metropolitano', 'venue_name' => 'Metropolitano Stadium']);
        $mapping = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);

        DB::table('match_info')->insert([
            [
                'm_id' => 601,
                'match_name' => 'Future fixture A',
                'match_date' => now()->addDays(10),
                'venue' => 500,
            ],
            [
                'm_id' => 602,
                'match_name' => 'Future fixture B',
                'match_date' => now()->addDay(),
                'venue' => 500,
            ],
            [
                'm_id' => 603,
                'match_name' => 'Past fixture',
                'match_date' => now()->subDays(30),
                'venue' => 500,
            ],
            [
                'm_id' => 604,
                'match_name' => 'Other stadium future',
                'match_date' => now()->addDays(5),
                'venue' => 999,
            ],
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/stadium-mappings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $mapping->id)
            ->assertJsonPath('data.0.seatsbroker_events_count', 2);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/stadium-mappings/{$mapping->id}")
            ->assertOk()
            ->assertJsonPath('data.seatsbroker_events_count', 2);
    }

    public function test_category_mapping_uses_the_category_before_the_first_underscore(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert(['id' => 77, 'seat_category' => 'Longside']);
        DB::table('stadium_details')->insert([
            'id' => 900,
            'stadium_id' => 500,
            'full_block_name' => 'Longside Upper Block 102',
            'block_id' => '102',
            'category' => 77,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-1', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-102',
            'external_event_id' => 'event-1',
            'category_name' => 'Longside Upper Block 102_Section A_Overflow',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $category->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $mapping = app(StadiumCategoryMappingService::class)->resolve($category, $stadium);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame([900], $mapping->details->pluck('stadium_detail_id')->all());
        $this->assertSame([77], $mapping->details->pluck('stadium_seat_id')->all());
        $this->assertSame('exact_name', $mapping->mapping_method);
    }

    public function test_category_mapping_api_separates_the_category_name_and_section_at_the_first_underscore(): void
    {
        $event = Xs2Event::create(['external_event_id' => 'event-category-parts']);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-category-parts',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside Upper Block 102_Section A_Overflow',
            'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'status' => 'pending_category_mapping',
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/category-mappings/{$mapping->id}")
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Longside Upper Block 102')
            ->assertJsonPath('data.category.section', 'Section A_Overflow')
            ->assertJsonPath('data.category.raw_name', 'Longside Upper Block 102_Section A_Overflow');
    }

    public function test_category_mapping_rejects_details_from_another_stadium(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped']);
        DB::table('stadium_seats')->insert(['id' => 77, 'seat_category' => 'Longside']);
        DB::table('stadium_details')->insert(['id' => 901, 'stadium_id' => 600, 'full_block_name' => 'Block 102', 'block_id' => '102', 'category' => 77]);
        $event = Xs2Event::create(['external_event_id' => 'event-1', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create(['xs2_event_id' => $event->id, 'external_category_id' => 'cat-102', 'external_event_id' => 'event-1', 'category_name' => 'Block 102', 'raw_payload' => []]);
        Xs2CategoryContext::create(['xs2_category_id' => $category->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $mapping = app(StadiumCategoryMappingService::class)->resolve($category, $stadium);

        $this->assertSame('unmatched', $mapping->status);
        $this->assertCount(0, $mapping->details);
    }

    public function test_pending_mapping_state_prevents_listing_publication(): void
    {
        $event = Xs2Event::create(['external_event_id' => 'event-1']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 99, 'status' => 'mapped']);
        $ticket = Xs2Ticket::create(['xs2_event_id' => $event->id, 'external_ticket_id' => 'ticket-1', 'ticket_status' => 'available', 'stock' => 2, 'sync_status' => 'pending']);
        Xs2TicketMappingState::create(['xs2_ticket_id' => $ticket->id, 'event_mapping_id' => $eventMapping->id, 'mapping_status' => 'pending_stadium_mapping']);
        $client = Mockery::mock(SellerApiClient::class);
        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldNotReceive('transform');

        (new PushXs2TicketToSellerApi($ticket->id))->handle($client, $transformer, app(ListingPublishValidator::class));

        $this->assertSame(0, ExternalListingMapping::count());
    }

    public function test_admin_ticket_list_exposes_filterable_sanitized_push_status(): void
    {
        $event = Xs2Event::create(['external_event_id' => 'event-push-status']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 99, 'status' => 'mapped']);
        $failedTicket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-push-failed',
            'ticket_status' => 'available',
            'stock' => 2,
            'flags' => ['no_max_minus_1'],
            'sync_status' => 'failed',
            'sync_error' => 'seller-api-secret was rejected by the upstream API',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $failedTicket->id,
            'event_mapping_id' => $eventMapping->id,
            'mapping_status' => 'published',
        ]);
        $this->activeListing($failedTicket, $eventMapping, 'seller-push-failed')->update([
            'status' => 'failed',
            'last_pushed_at' => now(),
        ]);
        Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-push-synced',
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/events/{$eventMapping->id}/tickets?push_status=failed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_ticket_id', 'ticket-push-failed')
            ->assertJsonPath('data.0.push_status', 'failed')
            ->assertJsonPath('data.0.flags', ['no_max_minus_1'])
            ->assertJsonPath('data.0.push_error', 'The most recent ticket push failed. Retry the listing or review the application logs.')
            ->assertJsonPath('data.0.listing_status', 'failed')
            ->assertJsonStructure(['data' => [['last_pushed_at']]])
            ->assertDontSee('seller-api-secret');
    }

    public function test_admin_ticket_detail_exposes_normalized_fields_and_raw_payload(): void
    {
        $event = Xs2Event::create(['external_event_id' => 'event-ticket-detail']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 77, 'status' => 'mapped']);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-detail-1',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside Upper Block 102',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => 'venue-1',
            'category_type' => 'grandstand',
        ]);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-detail-1',
            'category_id' => $category->external_category_id,
            'category_name' => $category->category_name,
            'sub_category' => 'Adult',
            'ticket_title' => 'Matchday e-ticket',
            'ticket_type' => 'eticket',
            'ticket_status' => 'available',
            'stock' => 4,
            'min_order' => 2,
            'net_rate' => 12345,
            'face_value' => 15000,
            'currency_code' => 'EUR',
            'ticket_valid_from' => '2026-09-01 10:00:00',
            'ticket_valid_until' => '2026-09-01 22:00:00',
            'flags' => ['pairs_only'],
            'options' => ['restricted_view'],
            'sales_periods' => [['starts_at' => '2026-08-01', 'ends_at' => '2026-09-01']],
            'raw_payload' => ['ticket_id' => 'ticket-detail-1', 'note' => 'raw-payload-marker'],
            'external_created_at' => '2026-07-01 09:00:00',
            'external_updated_at' => '2026-07-15 09:00:00',
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $eventMapping->id,
            'mapping_status' => 'published',
        ]);
        $this->activeListing($ticket, $eventMapping, 'seller-detail-1');

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.external_ticket_id', 'ticket-detail-1')
            ->assertJsonPath('data.category_type', 'grandstand')
            ->assertJsonPath('data.sub_category', 'Adult')
            ->assertJsonPath('data.ticket_title', 'Matchday e-ticket')
            ->assertJsonPath('data.ticket_type', 'eticket')
            ->assertJsonPath('data.min_order', 2)
            ->assertJsonPath('data.net_rate', 123.45)
            ->assertJsonPath('data.face_value', 150)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.flags', ['pairs_only'])
            ->assertJsonPath('data.options', ['restricted_view'])
            ->assertJsonPath('data.mapping_status', 'published')
            ->assertJsonPath('data.listing_status', 'active')
            ->assertJsonPath('data.seller_listing_id', 'seller-detail-1')
            ->assertJsonPath('data.raw_payload.note', 'raw-payload-marker')
            ->assertJsonStructure(['data' => ['ticket_valid_from', 'ticket_valid_until', 'sales_periods', 'external_created_at', 'external_updated_at']]);
    }

    public function test_admin_all_tickets_lists_published_tickets_across_events_with_event_details(): void
    {
        $futureStart = now()->addDays(45)->format('Y-m-d H:i:s');
        $eventA = Xs2Event::create([
            'external_event_id' => 'event-all-tickets-a',
            'event_name' => 'Arsenal vs Chelsea',
            'venue_id' => 'venue-emirates',
            'venue_name' => 'Emirates Stadium',
            'city' => 'London',
            'date_start_local' => $futureStart,
        ]);
        $venueA = $this->venue([
            'external_venue_id' => 'venue-emirates',
            'venue_name' => 'Emirates Stadium',
            'city_name' => 'London',
        ]);
        Xs2StadiumMapping::create([
            'xs2_venue_id' => $venueA->id,
            'status' => 'pending_stadium_mapping',
        ]);
        $mappingA = EventMapping::create(['xs2_event_id' => $eventA->id, 'm_id' => 501, 'status' => 'mapped']);
        \DB::table('match_info')->insert([
            'm_id' => 501,
            'match_name' => 'Arsenal vs Chelsea (SB)',
            'team_1' => 'Arsenal',
            'team_2' => 'Chelsea',
            'match_date' => $futureStart,
        ]);
        $publishedTicketA = Xs2Ticket::create([
            'xs2_event_id' => $eventA->id,
            'external_ticket_id' => 'ticket-all-a-published',
            'category_name' => 'Category 1',
            'ticket_status' => 'available',
            'stock' => 4,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $publishedTicketA->id,
            'event_mapping_id' => $mappingA->id,
            'mapping_status' => 'published',
        ]);
        $pendingTicketA = Xs2Ticket::create([
            'xs2_event_id' => $eventA->id,
            'external_ticket_id' => 'ticket-all-a-pending',
            'category_name' => 'Pending Category',
            'ticket_status' => 'available',
            'stock' => 1,
            'sync_status' => 'pending',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $pendingTicketA->id,
            'event_mapping_id' => $mappingA->id,
            'mapping_status' => 'pending_stadium_mapping',
        ]);

        $eventB = Xs2Event::create([
            'external_event_id' => 'event-all-tickets-b',
            'event_name' => 'Real Madrid vs Barcelona',
            'venue_id' => 'venue-bernabeu',
            'venue_name' => 'Santiago Bernabeu',
            'city' => 'Madrid',
            'date_start_local' => $futureStart,
        ]);
        DB::table('stadium')->insert(['s_id' => 826, 'stadium_name' => 'Santiago Bernabeu', 'country' => 1, 'city' => 100]);
        $venueB = $this->venue([
            'external_venue_id' => 'venue-bernabeu',
            'venue_name' => 'Santiago Bernabeu',
            'city_name' => 'Madrid',
        ]);
        $stadiumMappingB = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venueB->id,
            'stadium_id' => 826,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        $mappingB = EventMapping::create(['xs2_event_id' => $eventB->id, 'm_id' => 502, 'status' => 'mapped']);
        \DB::table('match_info')->insert([
            'm_id' => 502,
            'match_name' => 'Real Madrid vs Barcelona (SB)',
            'team_1' => 'Real Madrid',
            'team_2' => 'Barcelona',
            'match_date' => $futureStart,
        ]);
        $publishedTicketB = Xs2Ticket::create([
            'xs2_event_id' => $eventB->id,
            'external_ticket_id' => 'ticket-all-b-published',
            'category_name' => 'VIP Box',
            'ticket_status' => 'available',
            'stock' => 2,
            'flags' => ['pairs_only', 'package_rate'],
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $publishedTicketB->id,
            'event_mapping_id' => $mappingB->id,
            'mapping_status' => 'published',
        ]);

        $pastEvent = Xs2Event::create([
            'external_event_id' => 'event-all-tickets-past',
            'event_name' => 'Past Fixture',
            'venue_name' => 'Old Ground',
            'city' => 'London',
            'date_start_local' => now()->subDays(7)->format('Y-m-d H:i:s'),
        ]);
        $pastMapping = EventMapping::create(['xs2_event_id' => $pastEvent->id, 'm_id' => 503, 'status' => 'mapped']);
        $pastTicket = Xs2Ticket::create([
            'xs2_event_id' => $pastEvent->id,
            'external_ticket_id' => 'ticket-all-past-published',
            'category_name' => 'Past Category',
            'ticket_status' => 'available',
            'stock' => 9,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $pastTicket->id,
            'event_mapping_id' => $pastMapping->id,
            'mapping_status' => 'published',
        ]);

        $response = $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?mapping_status=published')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ticketIds = collect($response->json('data'))->pluck('external_ticket_id')->all();
        $this->assertEqualsCanonicalizing(['ticket-all-a-published', 'ticket-all-b-published'], $ticketIds);
        $this->assertNotContains('ticket-all-past-published', $ticketIds);

        $rowB = collect($response->json('data'))->firstWhere('external_ticket_id', 'ticket-all-b-published');
        $this->assertSame('Real Madrid vs Barcelona', $rowB['event']['name']);
        $this->assertSame('Santiago Bernabeu', $rowB['event']['venue_name']);
        $this->assertSame('Madrid', $rowB['event']['city']);
        $this->assertSame($mappingB->id, $rowB['event']['mapping_id']);
        $this->assertTrue($rowB['event']['is_mapped']);
        $this->assertSame('mapped', $rowB['event']['mapping_status']);
        $this->assertSame(502, $rowB['event']['local_event']['id']);
        $this->assertSame('Real Madrid vs Barcelona (SB)', $rowB['event']['local_event']['name']);
        $this->assertSame('venue-bernabeu', $rowB['event']['venue_id']);
        $this->assertSame($stadiumMappingB->id, $rowB['event']['venue_mapping']['id']);
        $this->assertSame('mapped', $rowB['event']['venue_mapping']['status']);
        $this->assertSame(826, $rowB['event']['venue_mapping']['stadium']['id']);
        $this->assertSame('VIP Box', $rowB['category_name']);
        $this->assertSame('published', $rowB['mapping_status']);
        $this->assertSame(['pairs_only', 'package_rate'], $rowB['flags']);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?'.http_build_query(['mapping_status' => 'published', 'search' => 'Bernabeu']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_ticket_id', 'ticket-all-b-published');

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $unpublished = $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?mapping_status=unpublished')
            ->assertOk();

        $unpublishedIds = collect($unpublished->json('data'))->pluck('external_ticket_id')->all();
        $this->assertNotContains('ticket-all-a-published', $unpublishedIds);
        $this->assertNotContains('ticket-all-b-published', $unpublishedIds);
        $this->assertSame(1, count($unpublishedIds));
        $unpublishedRow = collect($unpublished->json('data'))->firstWhere('external_ticket_id', 'ticket-all-a-pending');
        $this->assertSame('venue-emirates', $unpublishedRow['event']['venue_id']);
        $this->assertSame('pending_stadium_mapping', $unpublishedRow['event']['venue_mapping']['status']);
        $this->assertNull($unpublishedRow['event']['venue_mapping']['stadium']);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.published', 2)
            ->assertJsonPath('data.published_ticket_qty', 6)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonStructure([
                'data' => [
                    'total',
                    'published',
                    'published_ticket_qty',
                    'pending',
                    'no_stock',
                    'low_stock',
                    'errors',
                    'low_stock_max',
                ],
            ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets/summary?mapping_status=unpublished')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.published', 0)
            ->assertJsonPath('data.published_ticket_qty', 0)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonStructure([
                'data' => [
                    'total',
                    'published',
                    'published_ticket_qty',
                    'pending',
                    'no_stock',
                    'low_stock',
                    'errors',
                    'low_stock_max',
                ],
            ]);
    }

    public function test_admin_all_tickets_group_by_event_paginates_by_parent_event(): void
    {
        $futureStart = now()->addDays(30)->format('Y-m-d H:i:s');
        $laterStart = now()->addDays(60)->format('Y-m-d H:i:s');

        foreach ([
            ['name' => 'Event Alpha', 'date' => $futureStart, 'ticket' => 'ticket-alpha'],
            ['name' => 'Event Beta', 'date' => $laterStart, 'ticket' => 'ticket-beta'],
        ] as $fixture) {
            $event = Xs2Event::create([
                'external_event_id' => 'event-'.$fixture['ticket'],
                'event_name' => $fixture['name'],
                'venue_name' => 'Test Ground',
                'city' => 'London',
                'date_start_local' => $fixture['date'],
            ]);
            $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'status' => 'pending']);
            $ticket = Xs2Ticket::create([
                'xs2_event_id' => $event->id,
                'external_ticket_id' => $fixture['ticket'],
                'category_name' => 'General',
                'ticket_status' => 'available',
                'stock' => 2,
                'sync_status' => 'pending',
            ]);
            Xs2TicketMappingState::create([
                'xs2_ticket_id' => $ticket->id,
                'event_mapping_id' => $mapping->id,
                'mapping_status' => 'pending_event_mapping',
            ]);
        }

        $pageOne = $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?'.http_build_query([
                'mapping_status' => 'unpublished',
                'group_by_event' => 1,
                'page' => 1,
                'per_page' => 1,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertSame('ticket-alpha', $pageOne->json('data.0.external_ticket_id'));

        $pageTwo = $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?'.http_build_query([
                'mapping_status' => 'unpublished',
                'group_by_event' => 1,
                'page' => 2,
                'per_page' => 1,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->assertSame('ticket-beta', $pageTwo->json('data.0.external_ticket_id'));
    }

    public function test_admin_all_tickets_can_filter_by_event_mapping_status(): void
    {
        $futureStart = now()->addDays(30)->format('Y-m-d H:i:s');

        $mappedEvent = Xs2Event::create([
            'external_event_id' => 'event-filter-mapped',
            'event_name' => 'Mapped Fixture',
            'venue_name' => 'Mapped Ground',
            'city' => 'London',
            'date_start_local' => $futureStart,
        ]);
        $mappedMapping = EventMapping::create(['xs2_event_id' => $mappedEvent->id, 'm_id' => 601, 'status' => 'mapped']);
        $mappedTicket = Xs2Ticket::create([
            'xs2_event_id' => $mappedEvent->id,
            'external_ticket_id' => 'ticket-filter-mapped',
            'category_name' => 'Mapped Category',
            'ticket_status' => 'available',
            'stock' => 3,
            'sync_status' => 'pending',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $mappedTicket->id,
            'event_mapping_id' => $mappedMapping->id,
            'mapping_status' => 'pending_stadium_mapping',
        ]);

        $unmappedEvent = Xs2Event::create([
            'external_event_id' => 'event-filter-unmapped',
            'event_name' => 'Unmapped Fixture',
            'venue_name' => 'Unmapped Ground',
            'city' => 'Manchester',
            'date_start_local' => $futureStart,
        ]);
        $unmappedMapping = EventMapping::create(['xs2_event_id' => $unmappedEvent->id, 'status' => 'pending']);
        $unmappedTicket = Xs2Ticket::create([
            'xs2_event_id' => $unmappedEvent->id,
            'external_ticket_id' => 'ticket-filter-unmapped',
            'category_name' => 'Unmapped Category',
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'pending',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $unmappedTicket->id,
            'event_mapping_id' => $unmappedMapping->id,
            'mapping_status' => 'pending_event_mapping',
        ]);

        $mappedResponse = $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?'.http_build_query([
                'mapping_status' => 'unpublished',
                'event_mapping_status' => 'mapped',
            ]))
            ->assertOk();

        $mappedIds = collect($mappedResponse->json('data'))->pluck('external_ticket_id')->all();
        $this->assertSame(['ticket-filter-mapped'], $mappedIds);
        $this->assertTrue($mappedResponse->json('data.0.event.is_mapped'));

        $unmappedResponse = $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?'.http_build_query([
                'mapping_status' => 'unpublished',
                'event_mapping_status' => 'unmapped',
            ]))
            ->assertOk();

        $unmappedIds = collect($unmappedResponse->json('data'))->pluck('external_ticket_id')->all();
        $this->assertSame(['ticket-filter-unmapped'], $unmappedIds);
        $this->assertFalse($unmappedResponse->json('data.0.event.is_mapped'));
    }

    public function test_ignoring_a_category_mapping_disables_an_already_published_listing(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-ignore-category', 'venue_id' => $venue->external_venue_id]);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 99, 'status' => 'mapped']);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-ignore',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => $venue->external_venue_id,
            'category_type' => 'grandstand',
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'status' => 'ignored',
            'mapping_method' => 'manual',
            'manually_confirmed' => true,
        ]);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-ignore-category',
            'category_id' => $category->external_category_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $eventMapping->id,
            'mapping_status' => 'published',
        ]);
        $this->activeListing($ticket, $eventMapping, 'seller-ignore-category');
        Queue::fake();

        (new ResolvePendingXs2Listings('category', $categoryMapping->id))->handle(
            app(Xs2TicketMappingStatusService::class),
            app(StadiumCategoryMappingService::class),
        );

        $this->assertSame('pending_category_mapping', $ticket->fresh()->mappingState->mapping_status);
        Queue::assertPushed(DisableSellerListing::class, fn (DisableSellerListing $job): bool => $job->ticketId === $ticket->id);
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_resolve_preserves_published_status_for_manual_publish_with_pending_category_mapping(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        $event = Xs2Event::create([
            'external_event_id' => 'event-manual-published',
            'event_name' => 'FC Barcelona vs Rayo Vallecano',
            'venue_id' => $venue->external_venue_id,
            'date_start_local' => now()->addWeek(),
        ]);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9967, 'status' => 'mapped']);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-corner',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Corner',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => $venue->external_venue_id,
            'category_type' => 'grandstand',
        ]);
        Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'status' => 'pending_category_mapping',
            'mapping_method' => 'exact_seat_category',
            'mapping_error' => 'One or more matched stadium details are already claimed by another category mapping for this stadium.',
        ]);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-manual-published',
            'category_id' => $category->external_category_id,
            'category_name' => 'Corner',
            'ticket_status' => 'available',
            'stock' => 4,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $eventMapping->id,
            'mapping_status' => 'published',
        ]);
        $this->activeListing($ticket, $eventMapping, '912476');

        app(Xs2TicketMappingStatusService::class)->resolve($ticket);

        $this->assertSame('published', $ticket->fresh()->mappingState->mapping_status);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/tickets?mapping_status=published&search=Barcelona')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_ticket_id', 'ticket-manual-published')
            ->assertJsonPath('data.0.seller_listing_id', '912476');
    }

    public function test_ignoring_a_stadium_mapping_disables_an_already_published_listing(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-ignore-stadium', 'venue_id' => $venue->external_venue_id]);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 99, 'status' => 'mapped']);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-ignore-stadium',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => $venue->external_venue_id,
            'category_type' => 'grandstand',
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'mapping_method' => 'manual',
            'manually_confirmed' => true,
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id,
            'stadium_detail_id' => 900,
            'stadium_seat_id' => 77,
        ]);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-ignore-stadium',
            'category_id' => $category->external_category_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $eventMapping->id,
            'mapping_status' => 'published',
        ]);
        $this->activeListing($ticket, $eventMapping, 'seller-ignore-stadium');
        $stadium->update(['stadium_id' => null, 'status' => 'ignored']);
        Queue::fake();

        (new ResolvePendingXs2Listings('stadium', $stadium->id))->handle(
            app(Xs2TicketMappingStatusService::class),
            app(StadiumCategoryMappingService::class),
        );

        $categoryMapping = $categoryMapping->fresh();
        $this->assertSame('pending_stadium_mapping', $categoryMapping->status);
        $this->assertCount(0, $categoryMapping->details);
        $this->assertFalse($categoryMapping->manually_confirmed);
        $this->assertSame('pending_stadium_mapping', $ticket->fresh()->mappingState->mapping_status);
        Queue::assertPushed(DisableSellerListing::class, fn (DisableSellerListing $job): bool => $job->ticketId === $ticket->id);
    }

    public function test_changing_a_stadium_invalidates_stale_manual_category_details_before_reconciling_listings(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert(['id' => 77, 'seat_category' => 'Longside']);
        DB::table('stadium_details')->insert([
            'id' => 900,
            'stadium_id' => 500,
            'full_block_name' => 'Longside Upper Block 102',
            'block_id' => '102',
            'category' => 77,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-change-stadium', 'venue_id' => $venue->external_venue_id]);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 99, 'status' => 'mapped']);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-change-stadium',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside Upper Block 102',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => $venue->external_venue_id,
            'category_type' => 'grandstand',
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'mapping_method' => 'manual',
            'manually_confirmed' => true,
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id,
            'stadium_detail_id' => 900,
            'stadium_seat_id' => 77,
        ]);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-change-stadium',
            'category_id' => $category->external_category_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $eventMapping->id,
            'mapping_status' => 'published',
        ]);
        $this->activeListing($ticket, $eventMapping, 'seller-change-stadium');
        $stadium->update(['stadium_id' => 600]);
        Queue::fake();

        (new ResolvePendingXs2Listings('stadium', $stadium->id))->handle(
            app(Xs2TicketMappingStatusService::class),
            app(StadiumCategoryMappingService::class),
        );

        $categoryMapping = $categoryMapping->fresh();
        $this->assertSame('unmatched', $categoryMapping->status);
        $this->assertSame(600, (int) $categoryMapping->stadium_id);
        $this->assertCount(0, $categoryMapping->details);
        $this->assertFalse($categoryMapping->manually_confirmed);
        $this->assertSame('pending_category_mapping', $ticket->fresh()->mappingState->mapping_status);
        Queue::assertPushed(DisableSellerListing::class, fn (DisableSellerListing $job): bool => $job->ticketId === $ticket->id);
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_admin_can_load_city_restricted_stadium_options_for_a_pending_venue(): void
    {
        $this->masterLocation();
        DB::table('stadium')->insert([
            ['s_id' => 500, 'stadium_name' => 'Old Ground', 'country' => 1, 'city' => 100],
            ['s_id' => 600, 'stadium_name' => 'Other Ground', 'country' => 2, 'city' => 200],
        ]);
        $venue = $this->venue();
        $mapping = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'resolved_country_id' => 1,
            'resolved_city_id' => 100,
            'status' => 'pending_stadium_mapping',
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/stadium-mappings/{$mapping->id}/stadium-options")
            ->assertOk()
            ->assertJsonPath('data.0.id', 500)
            ->assertJsonPath('data.0.name', 'Old Ground')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.resolved_city_id', 100)
            ->assertJsonPath('meta.selection_available', true);
    }

    public function test_admin_can_confirm_stadium_when_resolved_city_aliases_differ(): void
    {
        DB::table('countries')->insert(['id' => 107, 'sortname' => 'IT', 'name' => 'Italy']);
        DB::table('states')->insert(['id' => 1, 'country_id' => 107, 'name' => 'Piemonte']);
        DB::table('cities')->insert([
            ['id' => 23402, 'state_id' => 1, 'name' => 'Torino'],
            ['id' => 23746, 'state_id' => 1, 'name' => 'Turin'],
        ]);
        DB::table('stadium')->insert([
            ['s_id' => 832, 'stadium_name' => 'Allianz Stadium', 'country' => 107, 'city' => 23746],
        ]);
        $venue = $this->venue([
            'external_venue_id' => 'venue-juventus',
            'venue_name' => 'Juventus Stadium',
            'city_name' => 'Torino',
            'country_code' => 'IT',
            'country_name' => 'Italy',
        ]);
        $mapping = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'resolved_country_id' => 107,
            'resolved_city_id' => 23402,
            'status' => 'unmatched',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/stadium-mappings/{$mapping->id}/confirm", ['stadium_id' => 832])
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.stadium.id', 832)
            ->assertJsonPath('data.resolved_city.id', 23746);
    }

    public function test_confirm_stadium_returns_specific_validation_message(): void
    {
        $venue = $this->venue(['external_venue_id' => 'venue-missing-stadium']);
        $mapping = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'status' => 'unmatched',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/stadium-mappings/{$mapping->id}/confirm", ['stadium_id' => 99999])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select an existing local stadium.')
            ->assertJsonPath('errors.stadium_id.0', 'Select an existing local stadium.');
    }

    public function test_admin_can_load_category_options_only_after_the_parent_stadium_is_mapped(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
        ]);
        DB::table('stadium_seats')->insert(['id' => 77, 'seat_category' => 'Longside']);
        DB::table('stadium_details')->insert([
            'id' => 900,
            'stadium_id' => 500,
            'full_block_name' => 'Longside Upper Block 102',
            'block_id' => '102',
            'category' => 77,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-options', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-options',
            'external_event_id' => 'event-options',
            'category_name' => 'Longside Upper',
            'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'status' => 'pending_category_mapping',
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/category-mappings/{$mapping->id}/category-options")
            ->assertOk()
            ->assertJsonPath('data.0.stadium_seat_id', 77)
            ->assertJsonPath('data.0.stadium_seat_name', 'Longside')
            ->assertJsonPath('data.0.detail_count', 1)
            ->assertJsonPath('meta.stadium_id', 500);

        $stadium->update(['status' => 'pending_stadium_mapping']);
        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/category-mappings/{$mapping->id}/category-options")
            ->assertConflict()
            ->assertJsonPath('data', []);
    }

    public function test_admin_can_filter_category_mappings_by_parent_stadium_name(): void
    {
        DB::table('stadium')->insert([
            ['s_id' => 500, 'stadium_name' => 'Old Ground', 'country' => 1, 'city' => 100],
            ['s_id' => 600, 'stadium_name' => 'Other Ground', 'country' => 2, 'city' => 200],
        ]);
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped']);
        $otherStadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 600, 'status' => 'mapped']);
        $event = Xs2Event::create(['external_event_id' => 'event-filter', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-filter-old',
            'external_event_id' => 'event-filter',
            'category_name' => 'Category Old',
            'raw_payload' => [],
        ]);
        $otherCategory = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-filter-other',
            'external_event_id' => 'event-filter',
            'category_name' => 'Category Other',
            'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'status' => 'pending_category_mapping',
        ]);
        Xs2CategoryMapping::create([
            'xs2_category_id' => $otherCategory->id,
            'xs2_stadium_mapping_id' => $otherStadium->id,
            'stadium_id' => 600,
            'status' => 'pending_category_mapping',
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?'.http_build_query(['stadium_search' => 'Old', 'group' => '0']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mapping->id);
    }

    public function test_stadium_name_search_ignores_accents_and_a_generic_estadio_prefix(): void
    {
        DB::table('stadium')->insert([
            ['s_id' => 826, 'stadium_name' => 'Santiago Bernabeu', 'country' => 1, 'city' => 100],
            ['s_id' => 600, 'stadium_name' => 'Other Ground', 'country' => 2, 'city' => 200],
        ]);
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 826, 'status' => 'mapped']);
        $event = Xs2Event::create(['external_event_id' => 'event-bernabeu', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-bernabeu',
            'external_event_id' => 'event-bernabeu',
            'category_name' => 'European Cup Room',
            'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 826,
            'status' => 'unmatched',
        ]);

        // The XS2-supplied venue name has an accent and an "Estadio" prefix
        // the legacy stadium row doesn't - the search must still find it.
        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?'.http_build_query(['stadium_search' => 'Estadio Santiago Bernabéu', 'group' => '0']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mapping->id);
    }

    public function test_category_mappings_index_groups_repeated_per_event_categories_by_default(): void
    {
        DB::table('stadium')->insert(['s_id' => 826, 'stadium_name' => 'Santiago Bernabeu', 'country' => 1, 'city' => 100]);
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 826, 'status' => 'mapped']);

        // XS2 sends the same physical category once per event. Two events at
        // the same stadium each get their own "European Cup Room" category
        // and mapping row - the default (grouped) index must collapse those
        // into a single entry instead of listing every event's row.
        foreach (['event-a', 'event-b'] as $externalEventId) {
            $event = Xs2Event::create(['external_event_id' => $externalEventId, 'venue_id' => $venue->external_venue_id]);
            $category = Xs2Category::create([
                'xs2_event_id' => $event->id,
                'external_category_id' => "cat-{$externalEventId}",
                'external_event_id' => $externalEventId,
                'category_name' => 'European Cup Room',
                'raw_payload' => [],
            ]);
            Xs2CategoryMapping::create([
                'xs2_category_id' => $category->id,
                'xs2_stadium_mapping_id' => $stadium->id,
                'stadium_id' => 826,
                'status' => 'unmatched',
            ]);
        }

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?stadium_id=826')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category_name', 'European Cup Room')
            ->assertJsonPath('data.0.total_count', 2)
            ->assertJsonPath('data.0.unmatched_count', 2)
            ->assertJsonPath('data.0.stadium_name', 'Santiago Bernabeu');

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?'.http_build_query(['stadium_id' => 826, 'group' => '0']))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_category_mappings_grouped_pending_tickets_ignore_stale_stadium_pending_states(): void
    {
        DB::table('stadium')->insert(['s_id' => 826, 'stadium_name' => 'Santiago Bernabeu', 'country' => 1, 'city' => 100]);
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 826, 'status' => 'mapped']);
        $event = Xs2Event::create(['external_event_id' => 'event-pending-tickets', 'venue_id' => $venue->external_venue_id]);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 55, 'status' => 'mapped']);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-pending-tickets',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create([
            'xs2_category_id' => $category->id,
            'external_venue_id' => $venue->external_venue_id,
            'category_type' => 'grandstand',
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 826,
            'status' => 'mapped',
            'mapping_method' => 'exact_seat_category',
        ]);
        $staleStadiumPending = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-stale-stadium',
            'category_id' => $category->external_category_id,
            'ticket_status' => 'available',
            'stock' => 1,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $staleStadiumPending->id,
            'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $mapping->id,
            'mapping_status' => 'pending_stadium_mapping',
        ]);
        $categoryPending = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-category-pending',
            'category_id' => $category->external_category_id,
            'ticket_status' => 'available',
            'stock' => 1,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $categoryPending->id,
            'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $mapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?stadium_id=826')
            ->assertOk()
            ->assertJsonPath('data.0.category_name', 'Longside')
            ->assertJsonPath('data.0.mapped_count', 1)
            ->assertJsonPath('data.0.pending_category_count', 0)
            ->assertJsonPath('data.0.pending_tickets', 1);
    }

    public function test_category_mappings_index_reports_the_main_mapped_block_and_its_sections(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped']);

        // Two events at the same stadium repeat the "Distinti" category; both
        // mapping rows share the same set of stadium details. The grouped
        // index must report the block those details share, with its sections
        // deduplicated across the repeated per-event rows.
        foreach (['event-a', 'event-b'] as $externalEventId) {
            $event = Xs2Event::create(['external_event_id' => $externalEventId, 'venue_id' => $venue->external_venue_id]);
            $category = Xs2Category::create([
                'xs2_event_id' => $event->id,
                'external_category_id' => "cat-{$externalEventId}",
                'external_event_id' => $externalEventId,
                'category_name' => 'Distinti',
                'raw_payload' => [],
            ]);
            $categoryMapping = Xs2CategoryMapping::create([
                'xs2_category_id' => $category->id,
                'xs2_stadium_mapping_id' => $stadium->id,
                'stadium_id' => 500,
                'status' => 'mapped',
                'mapping_method' => 'automatic',
            ]);
            Xs2CategoryMappingDetail::create([
                'xs2_category_mapping_id' => $categoryMapping->id,
                'stadium_detail_id' => 1001,
                'block' => 'Distinti Nord',
                'section' => 'A',
            ]);
            Xs2CategoryMappingDetail::create([
                'xs2_category_mapping_id' => $categoryMapping->id,
                'stadium_detail_id' => 1002,
                'block' => 'Distinti Nord',
                'section' => 'B',
            ]);
        }

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?stadium_id=500')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mapped_block', 'Distinti Nord')
            ->assertJsonPath('data.0.mapped_sections', ['A', 'B'])
            ->assertJsonPath('data.0.mapped_scope', 'section');
    }

    public function test_category_mappings_index_hides_blocks_for_category_only_scope(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped']);
        $event = Xs2Event::create(['external_event_id' => 'event-category-scope', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-category-scope',
            'external_event_id' => 'event-category-scope',
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'stadium_seat_id' => 77,
            'stadium_detail_id' => null,
            'status' => 'mapped',
            'mapping_method' => 'manual',
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id,
            'stadium_detail_id' => 1001,
            'stadium_seat_id' => 77,
            'stadium_seat_name' => 'LONGSIDE',
            'block' => 'distinti-granata',
            'section' => 'A',
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?stadium_id=500')
            ->assertOk()
            ->assertJsonPath('data.0.mapped_category', 'LONGSIDE')
            ->assertJsonPath('data.0.mapped_block', null)
            ->assertJsonPath('data.0.mapped_sections', [])
            ->assertJsonPath('data.0.mapped_scope', 'category');
    }

    public function test_category_mappings_index_shows_block_for_section_scope(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create(['xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped']);
        $event = Xs2Event::create(['external_event_id' => 'event-section-scope', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-section-scope',
            'external_event_id' => 'event-section-scope',
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'xs2_stadium_mapping_id' => $stadium->id,
            'stadium_id' => 500,
            'stadium_seat_id' => 77,
            'stadium_detail_id' => 1001,
            'status' => 'mapped',
            'mapping_method' => 'manual',
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id,
            'stadium_detail_id' => 1001,
            'stadium_seat_id' => 77,
            'stadium_seat_name' => 'LONGSIDE',
            'block' => 'distinti-granata',
            'section' => 'A',
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/category-mappings?stadium_id=500')
            ->assertOk()
            ->assertJsonPath('data.0.mapped_category', 'LONGSIDE')
            ->assertJsonPath('data.0.mapped_block', 'distinti-granata')
            ->assertJsonPath('data.0.mapped_sections', ['A'])
            ->assertJsonPath('data.0.mapped_scope', 'section');
    }

    public function test_category_mapping_without_an_exact_match_never_sweeps_in_a_family_of_seat_categories(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert([
            ['id' => 335, 'seat_category' => 'Distinti Nord'],
            ['id' => 336, 'seat_category' => 'Distinti Sud'],
            ['id' => 365, 'seat_category' => 'Distinti Sud Est'],
            ['id' => 408, 'seat_category' => 'Distinti Nord Ovest'],
            ['id' => 409, 'seat_category' => 'Distinti Nord Est'],
        ]);
        DB::table('stadium_details')->insert([
            ['id' => 1001, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_1', 'block_id' => '1', 'category' => 335],
            ['id' => 1002, 'stadium_id' => 500, 'full_block_name' => 'distinti-sud_1', 'block_id' => '1', 'category' => 336],
            ['id' => 1003, 'stadium_id' => 500, 'full_block_name' => 'distinti-sud-est_1', 'block_id' => '1', 'category' => 365],
            ['id' => 1004, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord-ovest_1', 'block_id' => '1', 'category' => 408],
            ['id' => 1005, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord-est_1', 'block_id' => '1', 'category' => 409],
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-distinti', 'venue_id' => $venue->external_venue_id]);
        // "Distinti" has no exact stadium_seats match (only more specific
        // siblings like "Distinti Nord" exist). It must never auto-select a
        // swept-in family of several seatsbroker categories -- one XS2
        // category maps to one seatsbroker category, or stays unresolved for
        // an administrator to pick manually among the candidates.
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-distinti',
            'external_event_id' => 'event-distinti',
            'category_name' => 'Distinti',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $category->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $mapping = app(StadiumCategoryMappingService::class)->resolve($category, $stadium);

        $this->assertSame('unmatched', $mapping->status);
        $this->assertSame('fuzzy', $mapping->mapping_method);
        $this->assertCount(0, $mapping->details);
        $candidateSeatIds = collect($mapping->candidate_scores)->pluck('stadium_seat_id')->all();
        $this->assertNotEmpty(array_intersect($candidateSeatIds, [335, 336, 365, 408, 409]));
        foreach ($mapping->candidate_scores as $candidate) {
            $this->assertSame(1, $candidate['detail_count']);
        }
    }

    public function test_exact_seat_category_match_never_sweeps_in_a_prefixed_sibling(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert([
            ['id' => 16, 'seat_category' => 'Category 1'],
            ['id' => 22, 'seat_category' => 'Category 1 Premium'],
            ['id' => 23, 'seat_category' => 'Category 1 Super Premium'],
        ]);
        DB::table('stadium_details')->insert([
            ['id' => 2001, 'stadium_id' => 500, 'full_block_name' => 'block-a', 'block_id' => 'a', 'category' => 16],
            ['id' => 2002, 'stadium_id' => 500, 'full_block_name' => 'block-b', 'block_id' => 'b', 'category' => 22],
            ['id' => 2003, 'stadium_id' => 500, 'full_block_name' => 'block-c', 'block_id' => 'c', 'category' => 23],
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-category1', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-category1',
            'external_event_id' => 'event-category1',
            'category_name' => 'Category 1',
            'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $category->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $mapping = app(StadiumCategoryMappingService::class)->resolve($category, $stadium);

        $this->assertSame('mapped', $mapping->status);
        $this->assertSame('exact_seat_category', $mapping->mapping_method);
        $this->assertSame(100.0, (float) $mapping->confidence_score);
        $this->assertCount(1, $mapping->details);
        $this->assertSame(2001, (int) $mapping->details->first()->stadium_detail_id);
        $this->assertSame(16, (int) $mapping->details->first()->stadium_seat_id);
    }

    public function test_exact_and_fuzzy_matching_coexist_without_cross_contamination(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert([
            ['id' => 16, 'seat_category' => 'Category 1'],
            ['id' => 22, 'seat_category' => 'Category 1 Premium'],
            ['id' => 335, 'seat_category' => 'Distinti Nord'],
            ['id' => 336, 'seat_category' => 'Distinti Sud'],
            ['id' => 77, 'seat_category' => 'Longside'],
        ]);
        DB::table('stadium_details')->insert([
            ['id' => 3001, 'stadium_id' => 500, 'full_block_name' => 'block-a', 'block_id' => 'a', 'category' => 16],
            ['id' => 3002, 'stadium_id' => 500, 'full_block_name' => 'block-b', 'block_id' => 'b', 'category' => 22],
            ['id' => 3003, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_1', 'block_id' => '1', 'category' => 335],
            ['id' => 3004, 'stadium_id' => 500, 'full_block_name' => 'distinti-sud_1', 'block_id' => '1', 'category' => 336],
            ['id' => 3005, 'stadium_id' => 500, 'full_block_name' => 'Longside Upper Block 102', 'block_id' => '102', 'category' => 77],
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-coexist', 'venue_id' => $venue->external_venue_id]);

        $exactCategory = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-exact', 'external_event_id' => 'event-coexist',
            'category_name' => 'Category 1', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $exactCategory->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $ambiguousCategory = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-ambiguous', 'external_event_id' => 'event-coexist',
            'category_name' => 'Distinti', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $ambiguousCategory->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $fuzzyCategory = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-fuzzy', 'external_event_id' => 'event-coexist',
            'category_name' => 'Longside Upper Block 102', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $fuzzyCategory->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $service = app(StadiumCategoryMappingService::class);
        $exactMapping = $service->resolve($exactCategory, $stadium);
        $ambiguousMapping = $service->resolve($ambiguousCategory, $stadium);
        $fuzzyMapping = $service->resolve($fuzzyCategory, $stadium);

        $this->assertSame('exact_seat_category', $exactMapping->mapping_method);
        $this->assertSame([3001], $exactMapping->details->pluck('stadium_detail_id')->all());

        // "Distinti" has no exact stadium_seats match, so it must never
        // sweep in both "Distinti Nord" and "Distinti Sud" as one family; the
        // weak fuzzy signal instead leaves it unmatched for manual review.
        $this->assertSame('unmatched', $ambiguousMapping->status);
        $this->assertSame('fuzzy', $ambiguousMapping->mapping_method);
        $this->assertCount(0, $ambiguousMapping->details);
        $candidateDetailIds = collect($ambiguousMapping->candidate_scores)->pluck('stadium_detail_id')->all();
        $this->assertNotEmpty(array_intersect($candidateDetailIds, [3003, 3004]));

        $this->assertSame('exact_name', $fuzzyMapping->mapping_method);
        $this->assertSame([3005], $fuzzyMapping->details->pluck('stadium_detail_id')->all());
    }

    public function test_hospitality_category_type_gets_keyword_bonus_against_vip_named_stadium_details(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert([
            ['id' => 900, 'seat_category' => 'VIP & Hospitality'],
            ['id' => 901, 'seat_category' => 'Category 1'],
        ]);
        DB::table('stadium_details')->insert([
            ['id' => 5001, 'stadium_id' => 500, 'full_block_name' => 'block-12', 'block_id' => '12', 'category' => 901],
            ['id' => 5002, 'stadium_id' => 500, 'full_block_name' => 'vip-box-lounge', 'block_id' => 'vip', 'category' => 900],
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-hospitality', 'venue_id' => $venue->external_venue_id]);

        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-hospitality', 'external_event_id' => 'event-hospitality',
            'category_name' => 'European Cup Room', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $category->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'hospitality']);

        $mapping = app(StadiumCategoryMappingService::class)->resolve($category, $stadium);

        // Neither candidate is a confident match, so this stays unmatched -
        // the bonus only improves which candidate ranks first for review.
        $this->assertSame('unmatched', $mapping->status);
        $this->assertContains('hospitality_keyword', $mapping->matched_fields);
        $this->assertSame(5002, $mapping->candidate_scores[0]['stadium_detail_id']);
    }

    public function test_hospitality_keyword_detection_is_case_and_separator_insensitive(): void
    {
        $normalizer = app(MappingTextNormalizer::class);

        $this->assertTrue($normalizer->hasHospitalityKeyword('VIP Box'));
        $this->assertTrue($normalizer->hasHospitalityKeyword('vip-suite'));
        $this->assertFalse($normalizer->hasHospitalityKeyword('Category 1'));
    }

    public function test_overlapping_stadium_details_across_categories_stay_pending_for_review(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert(['id' => 335, 'seat_category' => 'Distinti Nord']);
        DB::table('stadium_details')->insert(['id' => 4001, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_1', 'block_id' => '1', 'category' => 335]);
        $event = Xs2Event::create(['external_event_id' => 'event-overlap', 'venue_id' => $venue->external_venue_id]);

        $firstCategory = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-overlap-first', 'external_event_id' => 'event-overlap',
            'category_name' => 'Distinti Nord', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $firstCategory->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $secondCategory = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-overlap-second', 'external_event_id' => 'event-overlap',
            'category_name' => 'Distinti Nord', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $secondCategory->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $service = app(StadiumCategoryMappingService::class);
        $firstMapping = $service->resolve($firstCategory, $stadium);
        $secondMapping = $service->resolve($secondCategory, $stadium);

        $this->assertSame('mapped', $firstMapping->status);
        $this->assertSame([4001], $firstMapping->details->pluck('stadium_detail_id')->all());
        $this->assertSame('pending_category_mapping', $secondMapping->status);
        $this->assertStringContainsString('already claimed', (string) $secondMapping->mapping_error);
        $this->assertSame([4001], $secondMapping->details->pluck('stadium_detail_id')->all());
    }

    public function test_the_same_physical_blocks_may_be_reused_across_different_events(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id,
            'stadium_id' => 500,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert(['id' => 335, 'seat_category' => 'Distinti Nord']);
        DB::table('stadium_details')->insert(['id' => 4002, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_1', 'block_id' => '1', 'category' => 335]);

        $firstEvent = Xs2Event::create(['external_event_id' => 'event-reuse-first', 'venue_id' => $venue->external_venue_id]);
        $firstCategory = Xs2Category::create([
            'xs2_event_id' => $firstEvent->id, 'external_category_id' => 'cat-reuse-first', 'external_event_id' => 'event-reuse-first',
            'category_name' => 'Distinti Nord', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $firstCategory->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $secondEvent = Xs2Event::create(['external_event_id' => 'event-reuse-second', 'venue_id' => $venue->external_venue_id]);
        $secondCategory = Xs2Category::create([
            'xs2_event_id' => $secondEvent->id, 'external_category_id' => 'cat-reuse-second', 'external_event_id' => 'event-reuse-second',
            'category_name' => 'Distinti Nord', 'raw_payload' => [],
        ]);
        Xs2CategoryContext::create(['xs2_category_id' => $secondCategory->id, 'external_venue_id' => $venue->external_venue_id, 'category_type' => 'grandstand']);

        $service = app(StadiumCategoryMappingService::class);
        $firstMapping = $service->resolve($firstCategory, $stadium);
        $secondMapping = $service->resolve($secondCategory, $stadium);

        $this->assertSame('mapped', $firstMapping->status);
        $this->assertSame('mapped', $secondMapping->status);
        $this->assertSame([4002], $firstMapping->details->pluck('stadium_detail_id')->all());
        $this->assertSame([4002], $secondMapping->details->pluck('stadium_detail_id')->all());
    }

    public function test_confirm_endpoint_accepts_the_currently_resolved_details_as_is(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped', 'manually_confirmed' => true,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-confirm', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-confirm', 'external_event_id' => 'event-confirm',
            'category_name' => 'Longside Upper Block 102', 'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'xs2_stadium_mapping_id' => $stadium->id, 'stadium_id' => 500,
            'status' => 'pending_category_mapping',
        ]);
        Xs2CategoryMappingDetail::create(['xs2_category_mapping_id' => $mapping->id, 'stadium_detail_id' => 900, 'stadium_seat_id' => 77]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/category-mappings/{$mapping->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.manually_confirmed', true)
            ->assertJsonCount(1, 'data.details')
            ->assertJsonPath('data.details.0.stadium_detail_id', 900);
    }

    public function test_change_endpoint_replaces_details_with_every_row_under_the_chosen_seat_category(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped', 'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert(['id' => 335, 'seat_category' => 'Distinti Nord']);
        DB::table('stadium_details')->insert([
            ['id' => 1001, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_1', 'block_id' => '1', 'category' => 335],
            ['id' => 1002, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_2', 'block_id' => '2', 'category' => 335],
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-change', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-change', 'external_event_id' => 'event-change',
            'category_name' => 'Distinti', 'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'xs2_stadium_mapping_id' => $stadium->id, 'stadium_id' => 500,
            'status' => 'unmatched',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/category-mappings/{$mapping->id}/change", ['stadium_seat_id' => 335])
            ->assertOk()
            ->assertJsonPath('data.status', 'mapped')
            ->assertJsonPath('data.manually_confirmed', true)
            ->assertJsonCount(2, 'data.details')
            ->assertJsonPath('data.details.0.stadium_seat_id', 335)
            ->assertJsonPath('data.details.1.stadium_seat_id', 335);

        $this->assertDatabaseHas('xs2_category_mappings', [
            'id' => $mapping->id,
            'stadium_seat_id' => 335,
            'stadium_detail_id' => null,
        ]);
    }

    public function test_change_endpoint_pins_section_scope_when_a_stadium_detail_is_chosen(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped', 'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert(['id' => 335, 'seat_category' => 'Distinti Nord']);
        DB::table('stadium_details')->insert([
            ['id' => 1001, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_1', 'block_id' => '1', 'category' => 335],
            ['id' => 1002, 'stadium_id' => 500, 'full_block_name' => 'distinti-nord_2', 'block_id' => '2', 'category' => 335],
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-change-section', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-change-section', 'external_event_id' => 'event-change-section',
            'category_name' => 'Distinti', 'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'xs2_stadium_mapping_id' => $stadium->id, 'stadium_id' => 500,
            'status' => 'unmatched',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/category-mappings/{$mapping->id}/change", ['stadium_detail_id' => 1002])
            ->assertOk()
            ->assertJsonCount(1, 'data.details')
            ->assertJsonPath('data.details.0.stadium_detail_id', 1002);

        $this->assertDatabaseHas('xs2_category_mappings', [
            'id' => $mapping->id,
            'stadium_seat_id' => 335,
            'stadium_detail_id' => 1002,
        ]);
    }

    public function test_confirm_endpoint_rejects_a_seat_category_from_another_stadium(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped', 'manually_confirmed' => true,
        ]);
        DB::table('stadium_seats')->insert(['id' => 950, 'seat_category' => 'Other Stadium Category']);
        DB::table('stadium_details')->insert(['id' => 901, 'stadium_id' => 600, 'full_block_name' => 'Other Stadium Block', 'block_id' => '1', 'category' => 950]);
        $event = Xs2Event::create(['external_event_id' => 'event-confirm-reject', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-confirm-reject', 'external_event_id' => 'event-confirm-reject',
            'category_name' => 'Block', 'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'xs2_stadium_mapping_id' => $stadium->id, 'stadium_id' => 500,
            'status' => 'unmatched',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/category-mappings/{$mapping->id}/confirm", ['stadium_seat_id' => 950])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stadium_seat_id');
    }

    public function test_confirm_endpoint_rejects_an_empty_mapping_with_no_body(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped', 'manually_confirmed' => true,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-confirm-empty', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-confirm-empty', 'external_event_id' => 'event-confirm-empty',
            'category_name' => 'Nothing Here', 'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'xs2_stadium_mapping_id' => $stadium->id, 'stadium_id' => 500,
            'status' => 'unmatched',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/category-mappings/{$mapping->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stadium_seat_id');
    }

    public function test_change_endpoint_requires_a_replacement_seat_category(): void
    {
        $venue = $this->venue();
        $stadium = Xs2StadiumMapping::create([
            'xs2_venue_id' => $venue->id, 'stadium_id' => 500, 'status' => 'mapped', 'manually_confirmed' => true,
        ]);
        $event = Xs2Event::create(['external_event_id' => 'event-change-empty', 'venue_id' => $venue->external_venue_id]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-change-empty', 'external_event_id' => 'event-change-empty',
            'category_name' => 'Nothing Here', 'raw_payload' => [],
        ]);
        $mapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'xs2_stadium_mapping_id' => $stadium->id, 'stadium_id' => 500,
            'status' => 'unmatched',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/category-mappings/{$mapping->id}/change")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stadium_seat_id');
    }

    public function test_transformer_prefers_the_raw_category_name_over_mapped_fallback_candidates(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-category-fallback-1']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-fallback-1',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Category 1',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-fallback-1', 'external_event_id' => $event->external_event_id,
            'category_name' => 'Category 1', 'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'status' => 'mapped', 'manually_confirmed' => true,
            'candidate_scores' => [['stadium_seat_name' => 'Category 1 Premium', 'score' => 90]],
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id, 'stadium_detail_id' => 900, 'stadium_seat_id' => 16,
            'stadium_seat_name' => 'Category 1 Premium',
        ]);
        $mappingState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1001, 'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id, 'mapping_status' => 'ready_to_publish',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [
                    ['id' => 16, 'category_name' => 'Category 1'],
                    ['id' => 22, 'category_name' => 'Category 1 Premium'],
                ],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $payload = (new Xs2SellerListingTransformer($client))->transform(
            $ticket, $eventMapping, $mappingState->fresh('categoryMapping.details')
        );

        $this->assertSame('Category 1', $payload['category_name']);
        $this->assertArrayNotHasKey('ticket_category', $payload);
    }

    public function test_transformer_sends_xs2_category_name_instead_of_mapped_candidate_id(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-category-fallback-2']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-fallback-2',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Matchday Premium',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-fallback-2', 'external_event_id' => $event->external_event_id,
            'category_name' => 'Matchday Premium', 'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'status' => 'mapped', 'manually_confirmed' => true,
            'candidate_scores' => [
                ['stadium_seat_name' => 'Category 1 Premium', 'score' => 59],
                ['stadium_seat_name' => 'Category 1 Super Premium', 'score' => 54.49],
            ],
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id, 'stadium_detail_id' => 901, 'stadium_seat_id' => 22,
            'stadium_seat_name' => 'Category 1 Premium',
        ]);
        $mappingState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1002, 'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id, 'mapping_status' => 'ready_to_publish',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [
                    ['id' => 22, 'category_name' => 'Category 1 Premium'],
                    ['id' => 23, 'category_name' => 'Category 1 Super Premium'],
                ],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $payload = (new Xs2SellerListingTransformer($client))->transform(
            $ticket, $eventMapping, $mappingState->fresh('categoryMapping.details')
        );

        $this->assertSame('Matchday Premium', $payload['category_name']);
        $this->assertArrayNotHasKey('ticket_category', $payload);
    }

    public function test_transformer_sends_xs2_category_name_when_dropdown_does_not_match_pending_mapping(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-category-name-fallback']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $eventMapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-name-fallback',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Longside Upper',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-name-fallback', 'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside Upper', 'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'status' => 'pending_category_mapping',
        ]);
        $mappingState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1005, 'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id, 'mapping_status' => 'pending_category_mapping',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Away']],
            ],
        ]);

        $this->expectException(ListingTransformationException::class);
        $this->expectExceptionMessage('does not match a Seats Broker ticket_category ID');

        (new Xs2SellerListingTransformer($client))->transform(
            $ticket, $eventMapping, $mappingState->fresh('categoryMapping')
        );
    }

    public function test_transformer_sends_xs2_category_name_when_category_mapping_is_pending_and_dropdown_matches(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-category-name-lookup']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $eventMapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-name-lookup',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Longside Upper',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-name-lookup', 'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside Upper', 'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'status' => 'pending_category_mapping',
        ]);
        $mappingState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1008, 'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id, 'mapping_status' => 'pending_category_mapping',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $payload = (new Xs2SellerListingTransformer($client))->transform(
            $ticket, $eventMapping, $mappingState->fresh('categoryMapping')
        );

        $this->assertSame('Longside Upper', $payload['category_name']);
        $this->assertArrayNotHasKey('ticket_category', $payload);
    }

    public function test_transformer_sends_xs2_category_name_not_mapped_seat_id_when_raw_name_differs(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-lateral-candidate-skip']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $eventMapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-lateral-candidate-skip',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Lateral',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-lateral',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Lateral',
            'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'status' => 'unmatched',
            'candidate_scores' => [[
                'stadium_seat_id' => 4,
                'stadium_seat_name' => 'longside upper tier',
                'score' => 37.69,
            ]],
        ]);
        $mappingState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1009,
            'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'longside upper tier']],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $payload = (new Xs2SellerListingTransformer($client))->transform(
            $ticket, $eventMapping, $mappingState->fresh('categoryMapping')
        );

        $this->assertSame('Lateral', $payload['category_name']);
        $this->assertArrayNotHasKey('ticket_category', $payload);
    }

    public function test_can_auto_publish_with_pending_category_mapping_when_category_name_exists(): void
    {
        $service = app(Xs2TicketMappingStatusService::class);
        $ticket = new Xs2Ticket(['category_name' => 'Tribuna']);

        $this->assertTrue($service->canAutoPublish($ticket, 'pending_category_mapping'));
        $this->assertFalse($service->canAutoPublish(new Xs2Ticket(['category_name' => '']), 'pending_category_mapping'));
        $this->assertTrue($service->canAutoPublish($ticket, 'ready_to_publish'));
    }

    public function test_transformer_fallback_payload_matches_mapped_payload_when_dropdown_resolves(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-category-parity']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $eventMapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-parity',
            'ticket_type' => 'eticket',
            'ticket_status' => 'available',
            'stock' => 2,
            'category_name' => 'Longside Upper',
            'currency_code' => 'EUR',
            'net_rate' => 10000,
            'flags' => [],
            'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-parity',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside Upper',
            'raw_payload' => [],
        ]);
        $mappedCategoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id,
            'status' => 'mapped',
            'manually_confirmed' => true,
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $mappedCategoryMapping->id,
            'stadium_detail_id' => 910,
            'stadium_seat_id' => 4,
            'stadium_seat_name' => 'Longside Upper',
        ]);
        $mappedState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1006,
            'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $mappedCategoryMapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);
        $fallbackCategory = Xs2Category::create([
            'xs2_event_id' => $event->id,
            'external_category_id' => 'cat-parity-fallback',
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Longside Upper',
            'raw_payload' => [],
        ]);
        $fallbackCategoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $fallbackCategory->id,
            'status' => 'pending_category_mapping',
        ]);
        $fallbackState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1007,
            'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $fallbackCategoryMapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 4, 'category_name' => 'Longside Upper']],
            ],
        ]);
        $client->shouldReceive('sellerId')->twice()->andReturn(77);

        $transformer = new Xs2SellerListingTransformer($client);
        $mappedPayload = $transformer->transform(
            $ticket,
            $eventMapping,
            $mappedState->fresh('categoryMapping.details'),
        );
        $fallbackPayload = $transformer->transform(
            $ticket,
            $eventMapping,
            $fallbackState->fresh('categoryMapping'),
        );

        $this->assertSame('Longside Upper', $mappedPayload['category_name']);
        $this->assertSame('Longside Upper', $fallbackPayload['category_name']);
        $this->assertArrayNotHasKey('ticket_category', $mappedPayload);
        $this->assertArrayNotHasKey('ticket_category', $fallbackPayload);
        $this->assertSame($mappedPayload, $fallbackPayload);
    }

    public function test_transformer_sends_xs2_category_name_instead_of_confirmed_mapping_detail_id(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-category-fallback-3']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-fallback-3',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Matchday Premium',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-fallback-3', 'external_event_id' => $event->external_event_id,
            'category_name' => 'Matchday Premium', 'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'status' => 'mapped', 'manually_confirmed' => true,
            // Decoy: a higher-scored candidate that must lose to the confirmed detail below.
            'candidate_scores' => [['stadium_seat_name' => 'Category 1 Super Premium', 'score' => 90]],
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id, 'stadium_detail_id' => 900, 'stadium_seat_id' => 22,
            'stadium_seat_name' => 'Category 1 Premium',
        ]);
        $mappingState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1003, 'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id, 'mapping_status' => 'ready_to_publish',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [
                    ['id' => 22, 'category_name' => 'Category 1 Premium'],
                    ['id' => 23, 'category_name' => 'Category 1 Super Premium'],
                ],
            ],
        ]);
        $client->shouldReceive('sellerId')->once()->andReturn(77);

        $payload = (new Xs2SellerListingTransformer($client))->transform(
            $ticket, $eventMapping, $mappingState->fresh('categoryMapping.details')
        );

        $this->assertSame('Matchday Premium', $payload['category_name']);
        $this->assertArrayNotHasKey('ticket_category', $payload);
    }

    public function test_transformer_fails_when_catalog_has_no_matching_category(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $event = Xs2Event::create(['external_event_id' => 'event-category-fallback-4']);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 9947, 'status' => 'mapped']);
        $eventMapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-fallback-4',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Matchday Premium',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $category = Xs2Category::create([
            'xs2_event_id' => $event->id, 'external_category_id' => 'cat-fallback-4', 'external_event_id' => $event->external_event_id,
            'category_name' => 'Matchday Premium', 'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::create([
            'xs2_category_id' => $category->id, 'status' => 'mapped', 'manually_confirmed' => true,
            'candidate_scores' => [['stadium_seat_name' => 'Category 1 Super Premium', 'score' => 40]],
        ]);
        Xs2CategoryMappingDetail::create([
            'xs2_category_mapping_id' => $categoryMapping->id, 'stadium_detail_id' => 902, 'stadium_seat_id' => 23,
            'stadium_seat_name' => 'Category 1 Super Premium',
        ]);
        $mappingState = Xs2TicketMappingState::create([
            'xs2_ticket_id' => 1004, 'event_mapping_id' => $eventMapping->id,
            'xs2_category_mapping_id' => $categoryMapping->id, 'mapping_status' => 'ready_to_publish',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 1, 'category_name' => 'Away']],
            ],
        ]);

        $this->expectException(ListingTransformationException::class);
        $this->expectExceptionMessage('does not match a Seats Broker ticket_category ID');

        (new Xs2SellerListingTransformer($client))->transform(
            $ticket, $eventMapping, $mappingState->fresh('categoryMapping.details')
        );
    }

    public function test_transformer_fails_when_no_mapping_state_and_no_category_match(): void
    {
        Cache::forget('seller-api:ticket-dropdown:9947');
        $ticket = new Xs2Ticket([
            'external_ticket_id' => 'ticket-category-fallback-5', 'ticket_type' => 'eticket',
            'ticket_status' => 'available', 'stock' => 2, 'category_name' => 'Matchday Premium',
            'currency_code' => 'EUR', 'net_rate' => 10000, 'flags' => [], 'options' => [],
        ]);
        $eventMapping = new EventMapping(['m_id' => 9947]);
        $eventMapping->setRelation('xs2Event', new Xs2Event([
            'event_status' => 'notstarted',
            'date_start_local' => '2999-01-01 12:00:00',
        ]));

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('ticketDropdown')->once()->with(9947)->andReturn([
            'result' => [
                'ticket_type' => [['id' => 2, 'ticket_type_name' => 'E-Tickets']],
                'split_type' => [['id' => 3, 'split_name' => 'No Preferences']],
                'category' => [['id' => 1, 'category_name' => 'Away']],
            ],
        ]);

        $this->expectException(ListingTransformationException::class);
        $this->expectExceptionMessage('does not match a Seats Broker ticket_category ID');

        (new Xs2SellerListingTransformer($client))->transform($ticket, $eventMapping);
    }

    private function masterLocation(): void
    {
        DB::table('countries')->insert([
            ['id' => 1, 'sortname' => 'US', 'name' => 'United States'],
            ['id' => 2, 'sortname' => 'CA', 'name' => 'Canada'],
        ]);
        DB::table('states')->insert([
            ['id' => 10, 'country_id' => 1, 'name' => 'Illinois'],
            ['id' => 20, 'country_id' => 2, 'name' => 'Ontario'],
        ]);
        DB::table('cities')->insert([
            ['id' => 100, 'state_id' => 10, 'name' => 'Springfield'],
            ['id' => 200, 'state_id' => 20, 'name' => 'Springfield'],
        ]);
    }

    private function venue(array $attributes = []): Xs2Venue
    {
        return Xs2Venue::create([
            'external_venue_id' => 'venue-1',
            'venue_name' => 'Old Ground',
            'city_name' => 'Springfield',
            'country_name' => 'United States',
            'country_code' => 'US',
            'raw_payload' => [],
            ...$attributes,
        ]);
    }

    private function activeListing(Xs2Ticket $ticket, EventMapping $eventMapping, string $sellerListingId): ExternalListingMapping
    {
        return ExternalListingMapping::create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => $eventMapping->m_id,
            'event_mapping_id' => $eventMapping->id,
            'seller_listing_id' => $sellerListingId,
            'seller_reference' => 'XS2-'.$ticket->external_ticket_id,
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
    }

    private function createTables(): void
    {
        foreach ([
            'listing_splits', 'xs2_ticket_mapping_states', 'xs2_category_mapping_details', 'xs2_category_mappings', 'xs2_category_contexts', 'xs2_stadium_mappings', 'xs2_venues',
            'external_listing_mappings', 'xs2_tickets', 'xs2_categories', 'event_mappings', 'xs2_events', 'match_info', 'personal_access_tokens', 'users',
            'stadium_details', 'stadium_seats', 'stadium', 'cities', 'states', 'countries',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('countries', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('sortname', 3)->nullable();
            $table->string('name');
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
        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('venue_id')->nullable();
            $table->string('event_name')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('city')->nullable();
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
        Schema::create('match_info', function (Blueprint $table): void {
            $table->increments('m_id');
            $table->string('match_name')->nullable();
            $table->string('team_1')->nullable();
            $table->string('team_2')->nullable();
            $table->dateTime('match_date')->nullable();
            $table->unsignedInteger('venue')->nullable();
        });
        Schema::create('xs2_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_category_id');
            $table->string('external_event_id');
            $table->string('category_name')->nullable();
            $table->json('raw_payload');
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('category_id')->nullable();
            $table->string('category_name')->nullable();
            $table->string('sub_category')->nullable();
            $table->string('ticket_title')->nullable();
            $table->string('ticket_type')->nullable();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_order')->nullable();
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->unsignedBigInteger('face_value')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->dateTime('ticket_valid_from')->nullable();
            $table->dateTime('ticket_valid_until')->nullable();
            $table->json('flags')->nullable();
            $table->json('options')->nullable();
            $table->json('sales_periods')->nullable();
            $table->json('raw_payload')->nullable();
            $table->dateTime('external_created_at')->nullable();
            $table->dateTime('external_updated_at')->nullable();
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_venues', function (Blueprint $table): void {
            $table->id();
            $table->string('external_venue_id')->unique();
            $table->string('venue_name')->nullable();
            $table->string('city_name')->nullable();
            $table->string('country_name')->nullable();
            $table->string('country_code')->nullable();
            $table->json('raw_payload');
            $table->timestamps();
        });
        Schema::create('xs2_stadium_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_venue_id');
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('resolved_country_id')->nullable();
            $table->unsignedBigInteger('resolved_city_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('mapping_method')->nullable();
            $table->json('matched_fields')->nullable();
            $table->json('candidate_scores')->nullable();
            $table->boolean('manually_confirmed')->default(false);
            $table->timestamp('mapped_at')->nullable();
            $table->text('mapping_error')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_category_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->unique();
            $table->string('external_venue_id')->nullable();
            $table->string('category_type')->nullable();
            $table->json('options')->nullable();
            $table->boolean('on_svg')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_category_id')->unique();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('stadium_id')->nullable();
            $table->unsignedBigInteger('stadium_detail_id')->nullable();
            $table->unsignedBigInteger('stadium_seat_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('mapping_method')->nullable();
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
            $table->unique(['xs2_category_mapping_id', 'stadium_detail_id'], 'xs2_cat_mapping_detail_unique');
        });
        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_ticket_id')->unique();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_venue_id')->nullable();
            $table->unsignedBigInteger('xs2_category_id')->nullable();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_category_mapping_id')->nullable();
            $table->string('mapping_status')->default('pending_event_mapping');
            $table->text('mapping_error')->nullable();
            $table->timestamp('last_resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('external_listing_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->unsignedBigInteger('xs2_ticket_id');
            $table->unsignedInteger('local_event_id')->nullable();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->string('seller_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->string('status')->default('pending');
            $table->string('last_payload_hash')->nullable();
            $table->unsignedInteger('last_pushed_quantity')->nullable();
            $table->unsignedBigInteger('last_pushed_price')->nullable();
            $table->json('last_request')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    private function adminToken(): string
    {
        return User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;
    }
}
