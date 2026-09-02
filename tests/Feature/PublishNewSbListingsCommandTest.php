<?php

namespace Tests\Feature;

use App\Jobs\PublishSplitListings;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\SbNewListingPublishService;
use App\Services\Xs2\ListingPublishReadinessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PublishNewSbListingsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('xs2.sb_new_listing_publish.enabled', true);
        config()->set('services.seller_api.enabled', true);
        config()->set('listing_publish_rules.enabled', true);
    }

    public function test_cron_queues_publish_for_ready_to_publish_ticket(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 8);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')
            ->once()
            ->withArgs(fn ($assessedTicket, bool $strictPublish): bool => $assessedTicket->is($ticket) && $strictPublish === false)
            ->andReturn(['ready' => true, 'error' => null]);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $summary = app(SbNewListingPublishService::class)->run();

        $this->assertSame(1, $summary['queued']);
        $this->assertSame(0, $summary['skip_reasons']['mapping_not_ready']);
        Queue::assertPushed(PublishSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
        Queue::assertNotPushed(PushXs2TicketToSellerApi::class);
    }

    public function test_cron_skips_pending_mapping_without_category_name(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 8);
        $ticket->update(['category_name' => '']);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        $summary = app(SbNewListingPublishService::class)->run();

        $this->assertSame(1, $summary['skip_reasons']['mapping_not_ready']);
        Queue::assertNothingPushed();
    }

    public function test_cron_skips_pending_mapping_even_with_category_name(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 8);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldNotReceive('assess');
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $summary = app(SbNewListingPublishService::class)->run();

        $this->assertSame(1, $summary['skip_reasons']['mapping_not_ready']);
        Queue::assertNothingPushed();
    }

    public function test_cron_skips_when_validation_not_ready(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 8);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')
            ->once()
            ->andReturn(['ready' => false, 'error' => 'Category does not match SB dropdown.']);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $summary = app(SbNewListingPublishService::class)->run();

        $this->assertSame(1, $summary['skip_reasons']['validation_failed']);
        Queue::assertNothingPushed();
    }

    public function test_manual_path_allows_pending_mapping_with_category_name(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 8);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'pending_category_mapping',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')
            ->once()
            ->withArgs(fn ($assessedTicket, bool $strictPublish): bool => $assessedTicket->is($ticket) && $strictPublish === true)
            ->andReturn(['ready' => true, 'error' => null]);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $summary = app(SbNewListingPublishService::class)->run(
            ticketId: $ticket->id,
            manualPublish: true,
        );

        $this->assertSame(1, $summary['queued']);
        Queue::assertPushed(PublishSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_cron_skips_ticket_already_on_sb(): void
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

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')->once()->andReturn(['ready' => true, 'error' => null]);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $summary = app(SbNewListingPublishService::class)->run();

        $this->assertSame(1, $summary['skip_reasons']['already_published_on_sb']);
        Queue::assertNothingPushed();
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

    private function createTables(): void
    {
        foreach ([
            'listing_splits',
            'xs2_ticket_mapping_states',
            'external_listing_mappings',
            'xs2_tickets',
            'event_mappings',
            'xs2_events',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->nullable();
            $table->string('event_name')->nullable();
            $table->string('sport_type')->nullable();
            $table->dateTime('date_start_local')->nullable();
            $table->string('event_status')->nullable();
            $table->json('raw_payload')->nullable();
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
            $table->string('external_event_id')->nullable();
            $table->string('external_ticket_id')->unique();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->string('category_name')->nullable();
            $table->string('ticket_type')->nullable();
            $table->string('currency_code')->nullable();
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_ticket_id')->unique();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->string('mapping_status')->nullable();
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
            $table->unsignedInteger('last_pushed_quantity')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->string('status')->default('pending');
            $table->string('seatsbroker_listing_id')->nullable();
            $table->timestamps();
        });
    }
}
