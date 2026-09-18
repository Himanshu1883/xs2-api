<?php

namespace Tests\Feature;

use App\Jobs\GenerateEventListingsJob;
use App\Jobs\PublishSplitListings;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\SbNewListingPublishService;
use App\Services\Xs2\ListingPublishReadinessService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class InstantSbListingPublishTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('xs2.sb_new_listing_publish.enabled', true);
        config()->set('services.seller_api.enabled', true);
        config()->set('listing_publish_rules.enabled', false);
    }

    public function test_mapped_event_with_pending_category_and_xs2_name_is_ready_and_dispatches_seller_api_job(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 10, mappingStatus: 'pending_category_mapping');

        $readiness = app(ListingPublishReadinessService::class)->assess($ticket->fresh(['xs2Event.mapping', 'mappingState']));
        $this->assertTrue($readiness['ready'], (string) $readiness['error']);

        $dispatched = app(SbNewListingPublishService::class)->dispatchIfReady($ticket->fresh());

        $this->assertTrue($dispatched);
        Queue::assertPushed(
            PushXs2TicketToSellerApi::class,
            fn (PushXs2TicketToSellerApi $job): bool => $job->ticketId === $ticket->id
                && $job->queue === config('services.seller_api.queue'),
        );
        Queue::assertNotPushed(PublishSplitListings::class);
    }

    public function test_unmapped_event_is_not_ready_and_does_not_dispatch(): void
    {
        Queue::fake();

        $ticket = $this->unmappedTicket(stock: 8);

        $readiness = app(ListingPublishReadinessService::class)->assess($ticket->fresh(['xs2Event.mapping']));
        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('event mapping', strtolower((string) $readiness['error']));

        $dispatched = app(SbNewListingPublishService::class)->dispatchIfReady($ticket->fresh());

        $this->assertFalse($dispatched);
        Queue::assertNothingPushed();
    }

    public function test_listing_generation_dispatches_immediately_for_mapped_event(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 6, mappingStatus: 'pending_category_mapping');
        $event = $ticket->xs2Event;
        $summary = ['published' => 0, 'disabled' => 0, 'skipped' => 0, 'errors' => []];

        $job = new GenerateEventListingsJob($event->id, 1, 'corr-instant-publish');
        $method = new \ReflectionMethod(GenerateEventListingsJob::class, 'processTicket');
        $args = [$ticket->fresh(), $event->fresh(), app(SbNewListingPublishService::class), &$summary];
        $method->invokeArgs($job, $args);

        $this->assertSame(1, $summary['published']);
        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_event_mapping_reconciliation_dispatches_immediately(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 4, mappingStatus: 'pending_category_mapping');
        $state = $ticket->mappingState;

        $mappingStates = Mockery::mock(Xs2TicketMappingStatusService::class);
        $mappingStates->shouldReceive('resolve')->once()->andReturn($state);

        (new ReconcileSellerListingsForMapping($ticket->xs2Event->mapping->id))->handle($mappingStates);

        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_cron_queues_pending_category_mapping_without_mocked_readiness(): void
    {
        Queue::fake();

        $ticket = $this->mappedTicket(stock: 9, mappingStatus: 'pending_category_mapping');

        $summary = app(SbNewListingPublishService::class)->run();

        $this->assertSame(1, $summary['queued']);
        $this->assertSame(0, $summary['skip_reasons']['mapping_not_ready']);
        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    private function mappedTicket(int $stock, string $mappingStatus = 'pending_category_mapping'): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-instant-'.uniqid(),
            'event_name' => 'Instant Publish Test Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
            'raw_payload' => [],
        ]);
        $mapping = EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9020,
            'status' => 'mapped',
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-instant-'.uniqid(),
            'ticket_status' => 'available',
            'stock' => $stock,
            'category_name' => 'Matchday Premium',
            'ticket_type' => 'eticket',
            'currency_code' => 'EUR',
            'net_rate' => 46500,
            'raw_payload' => [],
        ]);

        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $mapping->id,
            'mapping_status' => $mappingStatus,
        ]);

        return $ticket->fresh(['xs2Event.mapping', 'mappingState']);
    }

    private function unmappedTicket(int $stock): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-unmapped-'.uniqid(),
            'event_name' => 'Unmapped Instant Publish Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
            'raw_payload' => [],
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-unmapped-'.uniqid(),
            'ticket_status' => 'available',
            'stock' => $stock,
            'category_name' => 'Matchday Premium',
            'ticket_type' => 'eticket',
            'currency_code' => 'EUR',
            'net_rate' => 46500,
            'raw_payload' => [],
        ])->fresh(['xs2Event.mapping']);
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
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('split_sync_status')->nullable();
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
