<?php

namespace Tests\Feature;

use App\Jobs\DisableSellerListing;
use App\Jobs\DisableXs2SellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Jobs\ResolvePendingXs2Listings;
use App\Jobs\SyncXs2EventInventory;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Category;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Mapping\StadiumCategoryMappingService;
use App\Services\Xs2\MappedListingPublishService;
use App\Services\Xs2\Xs2EventInventorySyncService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use App\Services\Xs2\Xs2TicketSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class Xs2InventorySellabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        config(['pipeline.strict' => false]);
    }

    public function test_inventory_command_syncs_future_events_and_reconciles_cancelled_or_past_events(): void
    {
        $future = $this->mapping('future', now()->addDay(), 'notstarted', 101);
        $cancelled = $this->mapping('cancelled', now()->addDay(), ' CANCELLED ', 102);
        $past = $this->mapping('past', now()->subMinute(), 'notstarted', 103);
        $undated = $this->mapping('undated', null, 'notstarted', 104);
        $this->activeListing($cancelled, 'cancelled-listing');
        // Historical listing rows can predate event_mapping_id backfilling.
        $this->activeListing($past, 'past-listing')->update(['event_mapping_id' => null]);
        Queue::fake();

        $this->artisan('xs2:sync-inventory')
            ->expectsOutputToContain('Queued 1 XS2 inventory synchronization job(s)')
            ->expectsOutput('Queued 2 Seller listing reconciliation job(s) for unavailable XS2 events.')
            ->assertSuccessful();

        Queue::assertPushed(
            SyncXs2EventInventory::class,
            fn (SyncXs2EventInventory $job): bool => $job->reference === $future->xs2_event_id && $job->referenceIsMappingId === false,
        );
        Queue::assertPushed(
            ReconcileSellerListingsForMapping::class,
            fn (ReconcileSellerListingsForMapping $job): bool => $job->mappingId === $cancelled->id,
        );
        Queue::assertPushed(
            ReconcileSellerListingsForMapping::class,
            fn (ReconcileSellerListingsForMapping $job): bool => $job->mappingId === $past->id,
        );
        Queue::assertNotPushed(
            SyncXs2EventInventory::class,
            fn (SyncXs2EventInventory $job): bool => in_array($job->reference, [$cancelled->xs2_event_id, $past->xs2_event_id, $undated->xs2_event_id], true),
        );
    }

    public function test_inventory_command_syncs_events_without_local_event_mapping(): void
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-unmapped',
            'event_name' => 'Unmapped fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);
        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => null,
            'status' => 'pending',
        ]);
        Queue::fake();

        $this->artisan('xs2:sync-inventory')
            ->expectsOutputToContain('Queued 1 XS2 inventory synchronization job(s)')
            ->assertSuccessful();

        Queue::assertPushed(
            SyncXs2EventInventory::class,
            fn (SyncXs2EventInventory $job): bool => $job->reference === $event->id && $job->referenceIsMappingId === false,
        );
    }

    public function test_inventory_command_also_syncs_pending_events_but_excludes_ignored_events(): void
    {
        $pending = $this->pendingMapping('pending', now()->addDay(), 'notstarted', 'pending');
        $ignored = $this->pendingMapping('ignored', now()->addDay(), 'notstarted', 'ignored');
        Queue::fake();

        $this->artisan('xs2:sync-inventory')
            ->expectsOutputToContain('Queued 1 XS2 inventory synchronization job(s)')
            ->assertSuccessful();

        Queue::assertPushed(
            SyncXs2EventInventory::class,
            fn (SyncXs2EventInventory $job): bool => $job->reference === $pending->id,
        );
        Queue::assertNotPushed(
            SyncXs2EventInventory::class,
            fn (SyncXs2EventInventory $job): bool => $job->reference === $ignored->id,
        );
    }

    public function test_listing_reconciliation_disables_available_tickets_for_unsellable_events(): void
    {
        $cancelled = $this->mapping('cancelled', now()->addDay(), 'cancelled', 201);
        $past = $this->mapping('past', now()->subMinute(), 'notstarted', 202);
        $cancelledTicket = $this->ticket($cancelled, 'cancelled-ticket');
        $pastTicket = $this->ticket($past, 'past-ticket');
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $cancelledTicket->id,
            'local_event_id' => $cancelled->m_id,
            'event_mapping_id' => $cancelled->id,
            'seller_listing_id' => 'seller-cancelled-ticket',
            'seller_reference' => 'XS2-cancelled-ticket',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $pastTicket->id,
            'local_event_id' => $past->m_id,
            'event_mapping_id' => $past->id,
            'seller_listing_id' => 'seller-past-ticket',
            'seller_reference' => 'XS2-past-ticket',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        Queue::fake();

        $mappingStates = app(Xs2TicketMappingStatusService::class);
        $publisher = app(MappedListingPublishService::class);

        (new ReconcileSellerListingsForMapping($cancelled->id))->handle($mappingStates, $publisher);
        (new ReconcileSellerListingsForMapping($past->id))->handle($mappingStates, $publisher);

        Queue::assertPushed(
            DisableSellerListing::class,
            fn (DisableSellerListing $job): bool => $job->ticketId === $cancelledTicket->id,
        );
        Queue::assertPushed(
            DisableSellerListing::class,
            fn (DisableSellerListing $job): bool => $job->ticketId === $pastTicket->id,
        );
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_pending_mapping_resolution_cannot_publish_a_cancelled_event(): void
    {
        $mapping = $this->mapping('cancelled-resolution', now()->addDay(), 'cancelled', 250);
        $category = Xs2Category::query()->create([
            'xs2_event_id' => $mapping->xs2_event_id,
            'external_category_id' => 'category-cancelled',
            'external_event_id' => $mapping->xs2Event->external_event_id,
            'category_name' => 'Longside',
            'raw_payload' => [],
        ]);
        $categoryMapping = Xs2CategoryMapping::query()->create([
            'xs2_category_id' => $category->id,
            'status' => 'mapped',
        ]);
        $ticket = $this->ticket($mapping, 'cancelled-resolution-ticket');
        $ticket->update(['category_id' => $category->external_category_id]);
        $states = Mockery::mock(Xs2TicketMappingStatusService::class);
        $states->shouldReceive('resolve')
            ->once()
            ->withArgs(fn (Xs2Ticket $resolved): bool => $resolved->is($ticket))
            ->andReturn(new Xs2TicketMappingState(['mapping_status' => 'ready_to_publish']));
        $categories = Mockery::mock(StadiumCategoryMappingService::class);
        Queue::fake();

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => $mapping->m_id,
            'event_mapping_id' => $mapping->id,
            'seller_listing_id' => 'seller-cancelled',
            'seller_reference' => 'XS2-cancelled-resolution-ticket',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);

        (new ResolvePendingXs2Listings('category', $categoryMapping->id))->handle(
            $states,
            $categories,
            app(MappedListingPublishService::class),
        );

        Queue::assertPushed(
            DisableSellerListing::class,
            fn (DisableSellerListing $job): bool => $job->ticketId === $ticket->id,
        );
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_legacy_ticket_command_reconciles_unsellable_events_instead_of_syncing_them(): void
    {
        $future = $this->mapping('legacy-future', now()->addDay(), 'notstarted', 301);
        $cancelled = $this->mapping('legacy-cancelled', now()->addDay(), 'cancelled', 302);
        $past = $this->mapping('legacy-past', now()->subMinute(), 'notstarted', 303);
        Queue::fake();

        $this->artisan('xs2:sync-tickets')
            ->expectsOutputToContain('Queued 1 XS2 ticket synchronization job(s)')
            ->assertSuccessful();

        Queue::assertPushed(
            SyncXs2EventInventory::class,
            fn (SyncXs2EventInventory $job): bool => $job->reference === $future->xs2_event_id && $job->referenceIsMappingId === false,
        );
        Queue::assertNotPushed(
            SyncXs2EventInventory::class,
            fn (SyncXs2EventInventory $job): bool => in_array($job->reference, [$cancelled->xs2_event_id, $past->xs2_event_id], true),
        );
    }

    public function test_stale_legacy_ticket_job_disables_all_listings_for_an_unsellable_event(): void
    {
        $cancelled = $this->mapping('legacy-stale-cancelled', now()->addDay(), 'cancelled', 401);
        $ticket = $this->ticket($cancelled, 'legacy-stale-ticket');
        Queue::fake();

        $summary = app(Xs2TicketSyncService::class)->sync($cancelled);

        $this->assertSame(1, $summary['disabled']);
        Queue::assertPushed(
            DisableSellerListing::class,
            fn (DisableSellerListing $job): bool => $job->ticketId === $ticket->id,
        );
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_stale_inventory_job_disables_all_listings_without_fetching_an_unsellable_event(): void
    {
        $cancelled = $this->mapping('inventory-stale-cancelled', now()->addDay(), 'cancelled', 402);
        $ticket = $this->ticket($cancelled, 'inventory-stale-ticket');
        Http::fake();
        Queue::fake();

        $summary = app(Xs2EventInventorySyncService::class)->sync($cancelled);

        $this->assertSame(1, $summary['tickets_disabled']);
        Queue::assertPushed(
            DisableXs2SellerListing::class,
            fn (DisableXs2SellerListing $job): bool => $job->ticketId === $ticket->id,
        );
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
        Http::assertNothingSent();
    }

    private function mapping(string $reference, mixed $startsAt, string $status, int $matchId): EventMapping
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-'.$reference,
            'event_name' => 'Fixture '.$reference,
            'date_start_local' => $startsAt,
            'event_status' => $status,
            'raw_payload' => [],
        ]);

        return EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => $matchId,
            'status' => 'mapped',
        ]);
    }

    private function pendingMapping(string $reference, mixed $startsAt, string $status, string $mappingStatus): EventMapping
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-'.$reference,
            'event_name' => 'Fixture '.$reference,
            'date_start_local' => $startsAt,
            'event_status' => $status,
            'raw_payload' => [],
        ]);

        return EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => null,
            'status' => $mappingStatus,
        ]);
    }

    private function ticket(EventMapping $mapping, string $reference): Xs2Ticket
    {
        return Xs2Ticket::query()->create([
            'xs2_event_id' => $mapping->xs2_event_id,
            'external_ticket_id' => $reference,
            'external_event_id' => $mapping->xs2Event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);
    }

    private function activeListing(EventMapping $mapping, string $reference): ExternalListingMapping
    {
        $ticket = $this->ticket($mapping, $reference);

        return ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => $mapping->m_id,
            'event_mapping_id' => $mapping->id,
            'seller_listing_id' => 'seller-'.$reference,
            'seller_reference' => 'XS2-'.$reference,
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
    }
}
