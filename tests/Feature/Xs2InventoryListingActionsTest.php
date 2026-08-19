<?php

namespace Tests\Feature;

use App\Jobs\DeleteXs2SellerListing;
use App\Jobs\DisableXs2SellerListing;
use App\Jobs\PublishSplitListings;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Xs2InventoryListingActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_admin_can_queue_publish_retry_for_ready_tickets(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing")
            ->assertAccepted()
            ->assertJsonPath('data.ticket_id', $ticket->id);

        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn (PushXs2TicketToSellerApi $job): bool => $job->ticketId === $ticket->id
            && $job->strictPublish === true);
        $this->assertSame('pending', $ticket->fresh()->sync_status);
        $this->assertNull($ticket->fresh()->sync_error);
    }

    public function test_publish_retry_rejects_tickets_blocked_on_event_mapping(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket(mappingStatus: 'pending_event_mapping');

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticket']);

        Queue::assertNothingPushed();
    }

    public function test_publish_retry_allows_tickets_blocked_only_on_category_mapping(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket(mappingStatus: 'pending_category_mapping');
        ExternalListingMapping::query()->where('xs2_ticket_id', $ticket->id)->delete();
        $ticket->update(['sync_status' => 'pending']);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing")
            ->assertAccepted()
            ->assertJsonPath('data.ticket_id', $ticket->id);

        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn (PushXs2TicketToSellerApi $job): bool => $job->ticketId === $ticket->id
            && $job->strictPublish === true);
    }

    public function test_admin_can_queue_disable_listing(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/disable-listing")
            ->assertAccepted()
            ->assertJsonPath('data.ticket_id', $ticket->id);

        Queue::assertPushed(DisableXs2SellerListing::class, fn (DisableXs2SellerListing $job): bool => $job->ticketId === $ticket->id);
    }

    public function test_admin_can_queue_delete_listing(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/delete-listing")
            ->assertAccepted()
            ->assertJsonPath('data.ticket_id', $ticket->id);

        Queue::assertPushed(DeleteXs2SellerListing::class, fn (DeleteXs2SellerListing $job): bool => $job->ticketId === $ticket->id);
    }

    public function test_listing_actions_require_admin_authentication(): void
    {
        [$ticket] = $this->publishedTicket();

        $this->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing")->assertUnauthorized();
        $this->postJson("/api/admin/xs2/tickets/{$ticket->id}/disable-listing")->assertUnauthorized();
        $this->postJson("/api/admin/xs2/tickets/{$ticket->id}/delete-listing")->assertUnauthorized();
    }

    public function test_sync_retry_listing_runs_push_job_inline_and_returns_action_payload(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing?sync=1")
            ->assertOk()
            ->assertJsonPath('data.ticket_id', $ticket->id)
            ->assertJsonPath('data.queued', false);

        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn (PushXs2TicketToSellerApi $job): bool => $job->ticketId === $ticket->id
            && $job->strictPublish === true);
    }

    public function test_sync_retry_listing_returns_failure_when_event_is_not_sellable(): void
    {
        [$ticket] = $this->publishedTicket(mappingStatus: 'ready_to_publish');
        $ticket->xs2Event->update(['event_status' => 'cancelled']);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing?sync=1")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Seller listing publish failed.')
            ->assertJsonPath('data.sync_status', 'pending')
            ->assertJsonPath(
                'data.last_error',
                'This event is no longer sellable, so the listing cannot be published.',
            );
    }

    public function test_publish_retry_rejects_split_enabled_tickets_when_rules_disabled(): void
    {
        Queue::fake();
        config()->set('listing_publish_rules.enabled', false);
        [$ticket] = $this->publishedTicket(mappingStatus: 'ready_to_publish');
        $ticket->update(['split_enabled' => true]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing?sync=1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticket']);

        Queue::assertNothingPushed();
    }

    public function test_publish_retry_applies_listing_publish_rules_for_high_stock(): void
    {
        Queue::fake();
        config()->set('listing_publish_rules.enabled', true);
        [$ticket] = $this->publishedTicket(mappingStatus: 'ready_to_publish');
        $ticket->update(['stock' => 8]);
        ExternalListingMapping::query()->where('xs2_ticket_id', $ticket->id)->delete();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing")
            ->assertAccepted()
            ->assertJsonPath('data.ticket_id', $ticket->id);

        Queue::assertPushed(PublishSplitListings::class, fn (PublishSplitListings $job): bool => $job->ticketId === $ticket->id
            && ($job->config['split_quantity'] ?? 0) === 2
            && ($job->config['pairs_only'] ?? false) === true);
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_publish_retry_applies_listing_publish_rules_for_low_stock(): void
    {
        Queue::fake();
        config()->set('listing_publish_rules.enabled', true);
        [$ticket] = $this->publishedTicket(mappingStatus: 'ready_to_publish');
        $ticket->update(['stock' => 4]);
        ExternalListingMapping::query()->where('xs2_ticket_id', $ticket->id)->delete();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/retry-listing")
            ->assertAccepted();

        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn (PushXs2TicketToSellerApi $job): bool => $job->ticketId === $ticket->id
            && $job->strictPublish === true
            && $job->quantityOverride === 2
            && $job->pairsOnlyOverride === true);
        Queue::assertNotPushed(PublishSplitListings::class);
    }

    public function test_sync_disable_listing_runs_disable_job_inline(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/disable-listing?sync=1")
            ->assertOk()
            ->assertJsonPath('data.ticket_id', $ticket->id)
            ->assertJsonPath('data.queued', false);

        Queue::assertPushed(DisableXs2SellerListing::class, fn (DisableXs2SellerListing $job): bool => $job->ticketId === $ticket->id);
    }

    public function test_sync_delete_listing_runs_delete_job_inline(): void
    {
        Queue::fake();
        [$ticket] = $this->publishedTicket();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/tickets/{$ticket->id}/delete-listing?sync=1")
            ->assertOk()
            ->assertJsonPath('data.ticket_id', $ticket->id)
            ->assertJsonPath('data.queued', false);

        Queue::assertPushed(DeleteXs2SellerListing::class, fn (DeleteXs2SellerListing $job): bool => $job->ticketId === $ticket->id);
    }

    /** @return array{0: Xs2Ticket, 1: EventMapping} */
    private function publishedTicket(string $mappingStatus = 'published'): array
    {
        $event = Xs2Event::create([
            'external_event_id' => 'event-listing-actions',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
        ]);
        $eventMapping = EventMapping::create(['xs2_event_id' => $event->id, 'm_id' => 45, 'status' => 'mapped']);
        $ticket = Xs2Ticket::create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-listing-actions-'.uniqid(),
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
        ]);
        Xs2TicketMappingState::create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $eventMapping->id,
            'mapping_status' => $mappingStatus,
        ]);
        ExternalListingMapping::create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => $eventMapping->m_id,
            'event_mapping_id' => $eventMapping->id,
            'seller_listing_id' => 'seller-999',
            'seller_reference' => 'XS2-'.$ticket->external_ticket_id,
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);

        return [$ticket, $eventMapping];
    }

    private function adminToken(): string
    {
        return User::factory()->create(['user_type' => 6])->createToken('test-token')->plainTextToken;
    }

    private function createTables(): void
    {
        foreach ([
            'xs2_ticket_mapping_states',
            'external_listing_mappings',
            'xs2_tickets',
            'event_mappings',
            'xs2_events',
            'personal_access_tokens',
            'users',
        ] as $table) {
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
        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->nullable();
            $table->dateTime('date_start_local')->nullable();
            $table->string('event_status')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });
        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->unsignedInteger('m_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_ticket_id');
            $table->unsignedBigInteger('event_mapping_id');
            $table->string('mapping_status');
            $table->text('mapping_error')->nullable();
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
    }
}
