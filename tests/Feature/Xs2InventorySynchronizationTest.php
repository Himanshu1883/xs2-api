<?php

namespace Tests\Feature;

use App\Jobs\DeleteSplitListings;
use App\Jobs\DisableXs2SellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\SyncSplitListings;
use App\Jobs\SyncXs2CategoriesForEvent;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Models\Xs2Venue;
use App\Services\SellerApi\SellerApiClient;
use App\Services\Xs2\Xs2CategorySyncService;
use App\Services\Xs2\Xs2EventInventorySyncService;
use App\Services\Xs2\Xs2VenueSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class Xs2InventorySynchronizationTest extends TestCase
{
    public function test_dedicated_category_sync_establishes_a_missing_venue_mapping_first(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createMasterData();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.categories_endpoint', '/categories');
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        config()->set('xs2.mapping.category_auto_map_threshold', 95);

        $event = Xs2Event::create([
            'external_event_id' => 'event-category-dependency',
            'event_name' => 'Fixture',
            'venue_id' => 'venue-1',
            'raw_payload' => [
                'venue_id' => 'venue-1',
                'venue' => [
                    'venue_id' => 'venue-1',
                    'venue_name' => 'Old Ground',
                    'city' => 'Springfield',
                    'country_name' => 'United States',
                    'country_code' => 'US',
                ],
            ],
        ]);
        Http::fake([
            'https://xs2.test/categories*' => Http::response([
                'categories' => [[
                    'category_id' => 'category-102',
                    'event_id' => $event->external_event_id,
                    'venue_id' => 'venue-1',
                    'category_name' => 'Longside Upper Block 102',
                    'category_type' => 'grandstand',
                ]],
                'pagination' => [],
            ]),
        ]);

        (new SyncXs2CategoriesForEvent($event->id))->handle(
            app(Xs2VenueSyncService::class),
            app(Xs2CategorySyncService::class),
        );

        $venue = Xs2Venue::query()->with('stadiumMapping')->sole();
        $this->assertSame('mapped', $venue->stadiumMapping->status);
        $this->assertDatabaseHas('xs2_categories', [
            'external_event_id' => $event->external_event_id,
            'external_category_id' => 'category-102',
        ]);
        $this->assertDatabaseHas('xs2_category_mappings', [
            'xs2_stadium_mapping_id' => $venue->stadiumMapping->id,
            'status' => 'mapped',
        ]);
    }

    public function test_full_inventory_sync_creates_mapping_aware_ticket_and_queues_one_listing(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createMasterData();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.categories_endpoint', '/categories');
        config()->set('services.xs2.tickets_endpoint', '/tickets');
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        config()->set('xs2.mapping.category_auto_map_threshold', 95);
        Queue::fake();

        $event = Xs2Event::create([
            'external_event_id' => 'event-1',
            'event_name' => 'Fixture',
            'venue_id' => 'venue-1',
            'raw_payload' => [
                'venue_id' => 'venue-1',
                'venue' => [
                    'venue_id' => 'venue-1',
                    'venue_name' => 'Old Ground',
                    'city' => 'Springfield',
                    'country_name' => 'United States',
                    'country_code' => 'US',
                ],
            ],
        ]);
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 123, 'status' => 'mapped']);
        Http::fake([
            'https://xs2.test/categories*' => Http::response([
                'categories' => [[
                    'category_id' => 'category-102',
                    'event_id' => 'event-1',
                    'venue_id' => 'venue-1',
                    'category_name' => 'Longside Upper Block 102',
                    'category_type' => 'grandstand',
                ]],
                'pagination' => [],
            ]),
            'https://xs2.test/tickets*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'ticket-1',
                    'event_id' => 'event-1',
                    'venue_id' => 'venue-1',
                    'category_id' => 'category-102',
                    'category_name' => 'Longside Upper Block 102',
                    'ticket_status' => 'available',
                    'stock' => 2,
                    'min_order' => 1,
                    'net_rate' => 10000,
                    'currency_code' => 'EUR',
                    'type_ticket' => 'eticket',
                ]],
                'pagination' => [],
            ]),
        ]);

        $summary = app(Xs2EventInventorySyncService::class)->sync($mapping, 'full');

        $ticket = Xs2Ticket::query()->sole();
        $this->assertSame(1, $summary['tickets_created']);
        $this->assertSame(1, $summary['tickets_ready']);
        $this->assertSame('ready_to_publish', $ticket->mappingState->mapping_status);
        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_full_inventory_sync_stores_tickets_but_does_not_publish_for_a_pending_mapping(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createMasterData();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.categories_endpoint', '/categories');
        config()->set('services.xs2.tickets_endpoint', '/tickets');
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        config()->set('xs2.mapping.category_auto_map_threshold', 95);
        Queue::fake();

        $event = Xs2Event::create([
            'external_event_id' => 'event-pending-1',
            'event_name' => 'Fixture',
            'venue_id' => 'venue-1',
            'raw_payload' => [
                'venue_id' => 'venue-1',
                'venue' => [
                    'venue_id' => 'venue-1',
                    'venue_name' => 'Old Ground',
                    'city' => 'Springfield',
                    'country_name' => 'United States',
                    'country_code' => 'US',
                ],
            ],
        ]);
        // No local event has been mapped yet: m_id is null and the mapping is pending.
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => null, 'status' => 'pending']);
        Http::fake([
            'https://xs2.test/categories*' => Http::response([
                'categories' => [[
                    'category_id' => 'category-102',
                    'event_id' => 'event-pending-1',
                    'venue_id' => 'venue-1',
                    'category_name' => 'Longside Upper Block 102',
                    'category_type' => 'grandstand',
                ]],
                'pagination' => [],
            ]),
            'https://xs2.test/tickets*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'ticket-pending-1',
                    'event_id' => 'event-pending-1',
                    'venue_id' => 'venue-1',
                    'category_id' => 'category-102',
                    'category_name' => 'Longside Upper Block 102',
                    'ticket_status' => 'available',
                    'stock' => 2,
                    'min_order' => 1,
                    'net_rate' => 10000,
                    'currency_code' => 'EUR',
                    'type_ticket' => 'eticket',
                ]],
                'pagination' => [],
            ]),
        ]);

        $summary = app(Xs2EventInventorySyncService::class)->sync($mapping, 'full');

        $ticket = Xs2Ticket::query()->sole();
        $this->assertSame(1, $summary['tickets_created']);
        $this->assertSame(0, $summary['tickets_ready']);
        $this->assertSame(1, $summary['tickets_pending']);
        $this->assertSame('pending_event_mapping', $ticket->mappingState->mapping_status);
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
        Queue::assertPushed(DisableXs2SellerListing::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_successful_empty_full_inventory_sync_disables_previously_available_tickets(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createMasterData();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.categories_endpoint', '/categories');
        config()->set('services.xs2.tickets_endpoint', '/tickets');
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        Queue::fake();

        $event = Xs2Event::create([
            'external_event_id' => 'event-empty-inventory',
            'event_name' => 'Fixture',
            'venue_id' => 'venue-1',
            'raw_payload' => [
                'venue_id' => 'venue-1',
                'venue' => [
                    'venue_id' => 'venue-1',
                    'venue_name' => 'Old Ground',
                    'city' => 'Springfield',
                    'country_name' => 'United States',
                    'country_code' => 'US',
                ],
            ],
        ]);
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 123, 'status' => 'mapped']);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-no-longer-returned',
            'external_event_id' => $event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'raw_payload' => [],
            'last_seen_at' => now()->subHour(),
            'sync_status' => 'synced',
        ]);
        $listing = ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => $mapping->m_id,
            'event_mapping_id' => $mapping->id,
            'seller_listing_id' => 'seller-empty-inventory',
            'seller_reference' => 'XS2-ticket-no-longer-returned',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        Http::fake([
            'https://xs2.test/categories*' => Http::response(['categories' => [], 'pagination' => []]),
            'https://xs2.test/tickets*' => Http::response(['tickets' => [], 'pagination' => []]),
        ]);

        $summary = app(Xs2EventInventorySyncService::class)->sync($mapping, 'full');

        $this->assertSame(1, $summary['tickets_disabled']);
        $this->assertSame('unavailable', $ticket->fresh()->ticket_status);
        $this->assertSame(0, $ticket->fresh()->stock);
        Queue::assertPushed(DisableXs2SellerListing::class, fn ($job): bool => $job->ticketId === $ticket->id);

        $failingClient = Mockery::mock(SellerApiClient::class);
        $failingClient->shouldReceive('sellerId')->once()->andReturn(77);
        $failingClient->shouldReceive('disableListing')->once()->andThrow(new \RuntimeException('temporary Seller API failure'));
        try {
            (new DisableXs2SellerListing($ticket->id))->handle($failingClient);
            $this->fail('The first Seller listing disable should fail.');
        } catch (\RuntimeException) {
            // The next authoritative full snapshot must enqueue a retry.
        }
        $this->assertSame('failed', $listing->fresh()->status);

        Queue::fake();
        $retrySummary = app(Xs2EventInventorySyncService::class)->sync($mapping, 'full');

        $this->assertSame(1, $retrySummary['tickets_disabled']);
        Queue::assertPushed(DisableXs2SellerListing::class, fn ($job): bool => $job->ticketId === $ticket->id);

        $successfulClient = Mockery::mock(SellerApiClient::class);
        $successfulClient->shouldReceive('sellerId')->once()->andReturn(77);
        $successfulClient->shouldReceive('disableListing')->once()->andReturn(['success' => true]);
        (new DisableXs2SellerListing($ticket->id))->handle($successfulClient);

        $this->assertSame('inactive', $listing->fresh()->status);
        $this->assertSame(0, $listing->fresh()->last_pushed_quantity);
    }

    public function test_inventory_sync_queues_split_reconcile_for_published_split_ticket(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createMasterData();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.categories_endpoint', '/categories');
        config()->set('services.xs2.tickets_endpoint', '/tickets');
        config()->set('xs2.mapping.stadium_auto_map_threshold', 95);
        config()->set('xs2.mapping.category_auto_map_threshold', 95);
        Queue::fake();

        $event = Xs2Event::create([
            'external_event_id' => 'event-split-sync',
            'event_name' => 'Fixture',
            'venue_id' => 'venue-1',
            'event_status' => 'available',
            'date_start_local' => now()->addWeek(),
            'raw_payload' => [
                'venue_id' => 'venue-1',
                'venue' => [
                    'venue_id' => 'venue-1',
                    'venue_name' => 'Old Ground',
                    'city' => 'Springfield',
                    'country_name' => 'United States',
                    'country_code' => 'US',
                ],
            ],
        ]);
        $mapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 123, 'status' => 'mapped']);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-split-sync',
            'external_event_id' => $event->external_event_id,
            'category_id' => 'category-102',
            'ticket_status' => 'available',
            'stock' => 10,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
            'raw_payload' => [],
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $ticket->id,
            'mapping_status' => 'published',
        ]);
        foreach ([1, 2, 3, 4, 5] as $order) {
            ListingSplit::create([
                'master_listing_id' => $ticket->id,
                'seatsbroker_listing_id' => '90660'.($order + 4),
                'seller_reference' => 'XS2-ticket-split-sync-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 5),
                'split_order' => $order,
                'status' => 'active',
                'sync_status' => 'synced',
            ]);
        }

        Http::fake([
            'https://xs2.test/categories*' => Http::response([
                'categories' => [[
                    'category_id' => 'category-102',
                    'event_id' => 'event-split-sync',
                    'venue_id' => 'venue-1',
                    'category_name' => 'Longside Upper Block 102',
                    'category_type' => 'grandstand',
                ]],
                'pagination' => [],
            ]),
            'https://xs2.test/tickets*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'ticket-split-sync',
                    'event_id' => 'event-split-sync',
                    'venue_id' => 'venue-1',
                    'category_id' => 'category-102',
                    'category_name' => 'Longside Upper Block 102',
                    'ticket_status' => 'available',
                    'stock' => 5,
                    'min_order' => 1,
                    'net_rate' => 10000,
                    'currency_code' => 'EUR',
                    'type_ticket' => 'eticket',
                ]],
                'pagination' => [],
            ]),
        ]);

        $summary = app(Xs2EventInventorySyncService::class)->sync($mapping, 'full');

        $this->assertSame(1, $summary['listing_jobs_dispatched']);
        $this->assertSame(5, $ticket->fresh()->stock);
        Queue::assertPushed(SyncSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
        Queue::assertNotPushed(DeleteSplitListings::class);
        Queue::assertNotPushed(DisableXs2SellerListing::class);
    }

    public function test_tickets_only_scope_skips_venue_and_category_api_calls(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'test-key');
        config()->set('services.xs2.categories_endpoint', '/categories');
        config()->set('services.xs2.tickets_endpoint', '/tickets');
        Queue::fake();

        $event = Xs2Event::create([
            'external_event_id' => 'event-tickets-only',
            'event_name' => 'Fixture',
            'venue_id' => 'venue-1',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => ['venue_id' => 'venue-1'],
        ]);
        Http::fake([
            'https://xs2.test/tickets*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'ticket-only',
                    'event_id' => 'event-tickets-only',
                    'category_id' => 'category-1',
                    'ticket_status' => 'available',
                    'stock' => 1,
                    'net_rate' => 10000,
                    'currency_code' => 'EUR',
                ]],
                'pagination' => [],
            ]),
        ]);

        $summary = app(Xs2EventInventorySyncService::class)->sync($event, 'full', 'tickets');

        $this->assertSame(1, $summary['tickets_created']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/tickets'));
    }

    private function createMasterData(): void
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

        \DB::table('countries')->insert(['id' => 1, 'sortname' => 'US', 'name' => 'United States']);
        \DB::table('states')->insert(['id' => 10, 'country_id' => 1, 'name' => 'Illinois']);
        \DB::table('cities')->insert(['id' => 100, 'state_id' => 10, 'name' => 'Springfield']);
        \DB::table('stadium')->insert(['s_id' => 500, 'stadium_name' => 'Old Ground', 'country' => 1, 'city' => 100]);
        \DB::table('stadium_seats')->insert(['id' => 77, 'seat_category' => 'Longside']);
        \DB::table('stadium_details')->insert([
            'id' => 900,
            'stadium_id' => 500,
            'full_block_name' => 'Longside Upper Block 102',
            'block_id' => '102',
            'category' => 77,
        ]);
    }
}
