<?php

namespace Tests\Feature;

use App\Jobs\SyncSplitListings;
use App\Models\EventMapping;
use App\Models\ListingSplit;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2SyncState;
use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingQuantitySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncSplitListingQuantitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
        config()->set('xs2.sb_listing_inventory.enabled', true);
        config()->set('services.seller_api.enabled', true);
    }

    public function test_command_queues_sync_for_ticket_with_stock_change(): void
    {
        Queue::fake();

        $ticket = $this->publishedSplitTicket(stock: 5, splitQuantity: 2);
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 1,
            'seller_reference' => 'XS2-TEST-S1',
            'quantity' => 2,
            'price' => 100,
            'seatsbroker_listing_id' => '906610',
            'status' => 'active',
            'sync_status' => 'synced',
        ]);
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 2,
            'seller_reference' => 'XS2-TEST-S2',
            'quantity' => 2,
            'price' => 110,
            'seatsbroker_listing_id' => '906611',
            'status' => 'active',
            'sync_status' => 'synced',
        ]);
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 3,
            'seller_reference' => 'XS2-TEST-S3',
            'quantity' => 2,
            'price' => 121,
            'seatsbroker_listing_id' => '906612',
            'status' => 'active',
            'sync_status' => 'synced',
        ]);

        $this->artisan('xs2:sync-split-listing-quantities')
            ->assertSuccessful();

        Queue::assertPushed(SyncSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);

        $state = Xs2SyncState::query()->where('resource', SplitListingQuantitySyncService::SYNC_RESOURCE)->first();
        $this->assertNotNull($state);
        $this->assertSame('completed', $state->status);
        $this->assertSame(1, (int) ($state->metadata['queued'] ?? 0));
    }

    public function test_command_skips_ticket_when_plan_already_matches(): void
    {
        Queue::fake();

        $ticket = $this->publishedSplitTicket(stock: 5, splitQuantity: 2);
        foreach ([
            [1, 2, 100.0, '906610'],
            [2, 2, 110.0, '906611'],
            [3, 1, 121.0, '906612'],
        ] as [$order, $qty, $price, $listingId]) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'split_order' => $order,
                'seller_reference' => 'XS2-TEST-S'.$order,
                'quantity' => $qty,
                'price' => $price,
                'seatsbroker_listing_id' => $listingId,
                'status' => 'active',
                'sync_status' => 'synced',
            ]);
        }

        $this->artisan('xs2:sync-split-listing-quantities')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        $state = Xs2SyncState::query()->where('resource', SplitListingQuantitySyncService::SYNC_RESOURCE)->first();
        $this->assertSame(1, (int) ($state?->metadata['skipped'] ?? 0));
    }

    public function test_command_queues_sync_when_stock_at_unpublish_threshold(): void
    {
        Queue::fake();
        config()->set('xs2.split_listings.unpublish_stock_max', 2);

        $ticket = $this->publishedSplitTicket(stock: 2, splitQuantity: 2);
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 1,
            'seller_reference' => 'XS2-TEST-S1',
            'quantity' => 2,
            'price' => 100,
            'seatsbroker_listing_id' => '906610',
            'status' => 'active',
            'sync_status' => 'synced',
        ]);

        $this->artisan('xs2:sync-split-listing-quantities')
            ->assertSuccessful();

        Queue::assertPushed(SyncSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_cron_config_includes_split_listing_quantity_task(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('split-listing-cron-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk();

        $task = collect($response->json('data.tasks'))->firstWhere('id', 'xs2-sb-listing-inventory');
        $this->assertNotNull($task);
        $this->assertSame('xs2:sync-sb-listing-inventory', $task['command']);
        $this->assertSame('12,42 * * * *', $task['expression']);
        $this->assertNotNull($task['next_run_at']);
        $this->assertArrayHasKey('what_it_does', $task['extra']);
        $this->assertArrayHasKey('algorithm', $task['extra']);
        $this->assertArrayHasKey('examples', $task['extra']);
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
            'm_id' => 12345,
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
