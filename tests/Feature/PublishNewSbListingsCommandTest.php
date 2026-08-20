<?php

namespace Tests\Feature;

use App\Jobs\PublishSplitListings;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\SbNewListingPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublishNewSbListingsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
        config()->set('xs2.sb_new_listing_publish.enabled', true);
        config()->set('services.seller_api.enabled', true);
        config()->set('listing_publish_rules.enabled', true);
    }

    public function test_command_queues_publish_for_unpublished_mapped_ticket(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 8);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        $this->artisan('xs2:publish-new-sb-listings')
            ->assertSuccessful();

        Queue::assertPushed(PublishSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_command_skips_ticket_already_on_sb(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 8);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => $ticket->xs2Event->mapping->m_id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'seller_listing_id' => '912999',
            'seller_reference' => 'XS2-already-there',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'published',
        ]);

        $this->artisan('xs2:publish-new-sb-listings')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_cron_config_includes_sb_new_listing_publish_task(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('sb-new-listing-cron-test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/cron-config')
            ->assertOk();

        $task = collect($response->json('data.tasks'))->firstWhere('id', 'xs2-sb-new-listing-publish');
        $this->assertNotNull($task);
        $this->assertSame('xs2:publish-new-sb-listings', $task['command']);
        $this->assertSame('sb', $task['category']);
        $this->assertSame('* * * * *', $task['expression']);
        $this->assertSame('Every minute', $task['schedule']);
        $this->assertArrayHasKey('what_it_does', $task['extra']);
        $this->assertArrayHasKey('does_not_do', $task['extra']);
        $this->assertArrayHasKey('algorithm', $task['extra']);
        $this->assertArrayHasKey('examples', $task['extra']);
    }

    public function test_service_detects_unpublished_ticket(): void
    {
        $ticket = $this->mappedTicket(stock: 4);
        $service = app(SbNewListingPublishService::class);

        $this->assertFalse($service->isPublishedOnSb($ticket->fresh()));
    }

    private function mappedTicket(int $stock): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-new-sb-'.uniqid(),
            'event_name' => 'New SB Publish Test Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
            'raw_payload' => [],
        ]);
        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9020,
            'status' => 'mapped',
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-new-sb-'.uniqid(),
            'ticket_status' => 'available',
            'stock' => $stock,
            'category_name' => 'Tribuna',
            'ticket_type' => 'eticket',
            'currency_code' => 'EUR',
            'net_rate' => 35800,
            'raw_payload' => [],
        ]);
    }
}
