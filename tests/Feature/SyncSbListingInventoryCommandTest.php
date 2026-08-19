<?php

namespace Tests\Feature;

use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\SyncSplitListings;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2SyncState;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\MasterListingQuantitySyncService;
use App\Services\SellerApi\SbListingInventorySyncService;
use App\Services\SplitListings\SplitListingQuantitySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncSbListingInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
        config()->set('xs2.sb_listing_inventory.enabled', true);
        config()->set('services.seller_api.enabled', true);
    }

    public function test_command_queues_master_listing_push_when_quantity_drifted(): void
    {
        Queue::fake();

        $ticket = $this->publishedMasterTicket(stock: 4);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => 12345,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'seller_listing_id' => '906600',
            'seller_reference' => 'XS2-master-cron',
            'status' => 'active',
            'last_pushed_quantity' => 10,
        ]);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'published',
        ]);

        $this->artisan('xs2:sync-sb-listing-inventory')
            ->assertSuccessful();

        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn ($job): bool => $job->ticketId === $ticket->id);

        $state = Xs2SyncState::query()->where('resource', SbListingInventorySyncService::SYNC_RESOURCE)->first();
        $this->assertNotNull($state);
        $this->assertSame('completed', $state->status);
        $this->assertSame(1, (int) ($state->metadata['needs_sync'] ?? 0));
    }

    public function test_command_queues_split_reconcile_when_stock_drops_from_ten_to_four(): void
    {
        Queue::fake();

        $ticket = $this->publishedSplitTicket(stock: 4, splitQuantity: 2);
        foreach ([1, 2, 3, 4, 5] as $order) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'split_order' => $order,
                'seller_reference' => 'XS2-TEST-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 10),
                'seatsbroker_listing_id' => '90661'.$order,
                'status' => 'active',
                'sync_status' => 'synced',
            ]);
        }

        $this->artisan('xs2:sync-sb-listing-inventory')
            ->assertSuccessful();

        Queue::assertPushed(SyncSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_command_skips_when_master_and_split_plans_already_match(): void
    {
        Queue::fake();

        $master = $this->publishedMasterTicket(stock: 4);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $master->id,
            'local_event_id' => 12345,
            'event_mapping_id' => $master->xs2Event->mapping->id,
            'seller_listing_id' => '906600',
            'seller_reference' => 'XS2-master-ok',
            'status' => 'active',
            'last_pushed_quantity' => 4,
        ]);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $master->id,
            'event_mapping_id' => $master->xs2Event->mapping->id,
            'mapping_status' => 'published',
        ]);

        $split = $this->publishedSplitTicket(stock: 4, splitQuantity: 2);
        foreach ([
            [1, 2, 100.0, '906610'],
            [2, 2, 110.0, '906611'],
        ] as [$order, $qty, $price, $listingId]) {
            ListingSplit::query()->create([
                'master_listing_id' => $split->id,
                'split_order' => $order,
                'seller_reference' => 'XS2-SPLIT-S'.$order,
                'quantity' => $qty,
                'price' => $price,
                'seatsbroker_listing_id' => $listingId,
                'status' => 'active',
                'sync_status' => 'synced',
            ]);
        }

        $this->artisan('xs2:sync-sb-listing-inventory')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        $state = Xs2SyncState::query()->where('resource', SbListingInventorySyncService::SYNC_RESOURCE)->first();
        $this->assertSame(2, (int) ($state?->metadata['skipped'] ?? 0));
    }

    public function test_command_ignores_tickets_not_yet_published_on_sb(): void
    {
        Queue::fake();

        $event = Xs2Event::query()->create([
            'external_event_id' => 'evt-unpublished-cron',
            'event_name' => 'Unpublished Cron Test Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addWeek(),
            'raw_payload' => [],
        ]);
        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 12347,
            'status' => 'mapped',
        ]);
        Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-unpublished-cron',
            'ticket_status' => 'available',
            'stock' => 8,
            'net_rate' => 10000,
            'split_enabled' => false,
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        $this->artisan('xs2:sync-sb-listing-inventory')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_cron_config_includes_sb_listing_inventory_task(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('sb-listing-cron-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk();

        $task = collect($response->json('data.tasks'))->firstWhere('id', 'xs2-sb-listing-inventory');
        $this->assertNotNull($task);
        $this->assertSame('xs2:sync-sb-listing-inventory', $task['command']);
        $this->assertSame('sb', $task['category']);
        $this->assertSame('existing_listing_qty_sync', $task['extra']['cron_role'] ?? null);
        $this->assertSame('12,42 * * * *', $task['expression']);
        $this->assertNotNull($task['next_run_at']);
        $this->assertArrayHasKey('what_it_does', $task['extra']);
        $this->assertArrayHasKey('algorithm', $task['extra']);
        $this->assertArrayHasKey('examples', $task['extra']);
    }

    public function test_master_service_detects_quantity_drift(): void
    {
        $ticket = $this->publishedMasterTicket(stock: 4);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => 12345,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'seller_listing_id' => '906600',
            'seller_reference' => 'XS2-master-drift',
            'status' => 'active',
            'last_pushed_quantity' => 10,
        ]);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'mapping_status' => 'published',
        ]);

        $service = app(MasterListingQuantitySyncService::class);
        $ticket->load(['listingMapping', 'xs2Event.mapping']);

        $this->assertTrue($service->ticketNeedsSync($ticket));
    }

    public function test_split_service_detects_five_splits_when_stock_is_four(): void
    {
        $ticket = $this->publishedSplitTicket(stock: 4, splitQuantity: 2);
        foreach ([1, 2, 3, 4, 5] as $order) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'split_order' => $order,
                'seller_reference' => 'XS2-TEST-S'.$order,
                'quantity' => 2,
                'price' => 100,
                'seatsbroker_listing_id' => '90661'.$order,
                'status' => 'active',
                'sync_status' => 'synced',
            ]);
        }

        $service = app(SplitListingQuantitySyncService::class);
        $ticket->load(['listingSplits', 'xs2Event.mapping']);

        $this->assertTrue($service->ticketNeedsSync($ticket));
    }

    private function publishedMasterTicket(int $stock): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'evt-master-cron',
            'event_name' => 'Master Cron Test Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addWeek(),
            'raw_payload' => [],
        ]);

        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 12345,
            'status' => 'mapped',
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-master-cron',
            'ticket_status' => 'available',
            'stock' => $stock,
            'net_rate' => 10000,
            'split_enabled' => false,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);
    }

    private function publishedSplitTicket(int $stock, int $splitQuantity): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'evt-split-cron',
            'event_name' => 'Split Cron Test Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addWeek(),
            'raw_payload' => [],
        ]);

        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 12346,
            'status' => 'mapped',
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-split-cron',
            'ticket_status' => 'available',
            'stock' => $stock,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => $splitQuantity,
            'price_increment_type' => 'percentage',
            'price_increment_value' => 10,
            'split_sync_status' => 'completed',
            'raw_payload' => [],
        ]);
    }
}
