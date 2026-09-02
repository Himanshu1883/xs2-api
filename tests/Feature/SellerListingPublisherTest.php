<?php

namespace Tests\Feature;

use App\Exceptions\Integrations\SellerApiRequestException;
use App\Jobs\DisableSellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\SellerApiClient;
use App\Services\Xs2\EventMappingService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use App\Services\Xs2\ListingPublishValidator;
use App\Services\Xs2\Xs2SellerListingTransformer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SellerListingPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        config()->set('services.seller_api.base_url', 'https://seller.test');
        config()->set('services.seller_api.listing_base_url', 'https://seller.test');
        config()->set('services.seller_api.api_key', 'seller-test-key');
        config()->set('services.seller_api.api_key_header', 'apiKey');
        config()->set('services.seller_api.idempotency_key_header', 'Idempotency-Key');
        config()->set('services.seller_api.create_listing_endpoint', '/api/ticket/create');
        config()->set('services.seller_api.disable_listing_endpoint', '/api/ticket/update_status');
        config()->set('services.seller_api.seller_id', 77);
    }

    public function test_create_without_a_listing_id_fails_permanently(): void
    {
        $ticket = $this->ticket();
        $reference = 'XS2-xs2-ticket-1-event-45';
        Http::fake(['https://seller.test/api/ticket/create' => Http::response(['success' => true])]);

        try {
            (new PushXs2TicketToSellerApi($ticket->id))->handle(
                app(SellerApiClient::class),
                $this->transformer($reference),
                app(ListingPublishValidator::class),
            );
            $this->fail('A Seller API create response without an ID must fail the job.');
        } catch (SellerApiRequestException) {
            // Job fails permanently — admin must manually retry from the UI.
        }

        $listing = ExternalListingMapping::query()->sole();
        $this->assertNull($listing->seller_listing_id);
        $this->assertSame('failed', $listing->status);
        $this->assertSame($reference, $listing->last_request['seller_reference']);
        $this->assertSame('failed', $ticket->fresh()->sync_status);
        Http::assertSent(fn ($request): bool => ($request->header('Idempotency-Key')[0] ?? null) === $reference
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'seller_reference'
                && $part['contents'] === $reference));
    }

    public function test_create_persists_the_supplier_listing_id_before_marking_ticket_synced(): void
    {
        $ticket = $this->ticket();
        $reference = 'XS2-xs2-ticket-1-event-45';
        Http::fake(['https://seller.test/api/ticket/create' => Http::response(['ticket_id' => 'seller-123'])]);

        (new PushXs2TicketToSellerApi($ticket->id))->handle(
            app(SellerApiClient::class),
            $this->transformer($reference),
            app(ListingPublishValidator::class),
        );

        $listing = ExternalListingMapping::query()->sole();
        $this->assertSame('seller-123', $listing->seller_listing_id);
        $this->assertSame('active', $listing->status);
        $this->assertSame('synced', $ticket->fresh()->sync_status);
    }

    public function test_remapping_disables_the_previous_listing_before_creating_a_replacement(): void
    {
        $ticket = $this->ticket();
        $ticket->xs2Event->mapping->update(['m_id' => 46]);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => 45,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'seller_listing_id' => 'seller-123',
            'seller_reference' => 'XS2-xs2-ticket-1-event-45',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        $reference = 'XS2-xs2-ticket-1-event-46';
        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('sellerId')->once()->andReturn(77);
        $client->shouldReceive('disableListing')->once()->with('seller-123', Mockery::on(
            fn (array $payload): bool => $payload['match_id'] === 45 && $payload['status'] === '0'
        ))->andReturn(['success' => true]);
        $client->shouldReceive('createListing')->once()->with(Mockery::on(
            fn (array $payload): bool => $payload['match_id'] === 46 && $payload['seller_reference'] === $reference
        ), $reference)->andReturn(['ticket_id' => 'seller-456']);
        $client->shouldReceive('listingId')->once()->with(['ticket_id' => 'seller-456'])->andReturn('seller-456');

        (new PushXs2TicketToSellerApi($ticket->id))->handle($client, $this->transformer($reference, 46), app(ListingPublishValidator::class));

        $listing = ExternalListingMapping::query()->sole();
        $this->assertSame('seller-456', $listing->seller_listing_id);
        $this->assertSame(46, $listing->local_event_id);
        $this->assertSame($reference, $listing->seller_reference);
        $this->assertSame('active', $listing->status);
    }

    public function test_ignoring_a_mapping_enqueues_listing_reconciliation_and_disable_work(): void
    {
        $ticket = $this->ticket();
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => 45,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'seller_listing_id' => 'seller-123',
            'seller_reference' => 'XS2-xs2-ticket-1-event-45',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        Queue::fake();

        app(EventMappingService::class)->ignore($ticket->xs2Event->mapping, 1);

        Queue::assertPushed(ReconcileSellerListingsForMapping::class, fn ($job): bool => $job->mappingId === $ticket->xs2Event->mapping->id);

        (new ReconcileSellerListingsForMapping($ticket->xs2Event->mapping->id))->handle(
            app(Xs2TicketMappingStatusService::class),
        );

        Queue::assertPushed(DisableSellerListing::class, fn ($job): bool => $job->ticketId === $ticket->id);

        Http::fake(['https://seller.test/api/ticket/update_status' => Http::response(['success' => true])]);
        (new DisableSellerListing($ticket->id))->handle(app(SellerApiClient::class));

        $this->assertSame('inactive', ExternalListingMapping::query()->sole()->status);
    }

    public function test_stale_publish_job_queues_disable_without_creating_a_cancelled_event_listing(): void
    {
        $ticket = $this->ticket();
        $ticket->xs2Event->update([
            'date_start_local' => now()->addDay(),
            'event_status' => 'cancelled',
        ]);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => 45,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'seller_listing_id' => 'seller-123',
            'seller_reference' => 'XS2-xs2-ticket-1-event-45',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        Queue::fake();
        $client = Mockery::mock(SellerApiClient::class);
        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldNotReceive('transform');

        (new PushXs2TicketToSellerApi($ticket->id))->handle($client, $transformer, app(ListingPublishValidator::class));

        Queue::assertPushed(
            DisableSellerListing::class,
            fn (DisableSellerListing $job): bool => $job->ticketId === $ticket->id,
        );
        $this->assertSame(1, ExternalListingMapping::query()->count());
    }

    public function test_stale_publish_job_skips_without_disabling_when_event_mapping_is_no_longer_publishable(): void
    {
        $ticket = $this->ticket();
        $ticket->xs2Event->mapping->update(['status' => 'pending', 'm_id' => null]);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => 45,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'seller_listing_id' => 'seller-123',
            'seller_reference' => 'XS2-xs2-ticket-1-event-45',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        Queue::fake();
        $client = Mockery::mock(SellerApiClient::class);
        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldNotReceive('transform');

        (new PushXs2TicketToSellerApi($ticket->id))->handle($client, $transformer, app(ListingPublishValidator::class));

        Queue::assertNotPushed(DisableSellerListing::class);
        $this->assertSame('pending', $ticket->fresh()->sync_status);
        $this->assertSame(
            'A confirmed local event mapping is required before publishing.',
            $ticket->fresh()->sync_error,
        );
    }

    private function ticket(): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
        ]);
        $mapping = EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 45,
            'status' => 'mapped',
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'xs2-ticket-1',
            'ticket_status' => 'available',
            'stock' => 2,
            'category_name' => 'Longside Upper Tier',
            'currency_code' => 'EUR',
            'net_rate' => 11000,
            'sync_status' => 'pending',
        ]);
    }

    private function transformer(string $reference, int $matchId = 45): Xs2SellerListingTransformer
    {
        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldReceive('transform')->once()->andReturn([
            'seller_reference' => $reference,
            'match_id' => $matchId,
            'category_name' => 'Longside Upper Tier',
            'ticket_type' => 2,
            'split_type' => 3,
            'quantity' => 2,
            'price' => 11000,
            'seller_id' => 77,
            'status' => '1',
        ]);

        return $transformer;
    }

    private function createTables(): void
    {
        foreach (['external_listing_mappings', 'xs2_tickets', 'event_mappings', 'xs2_events'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
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
            $table->string('mapping_method')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->string('category_name')->nullable();
            $table->string('currency_code')->nullable();
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
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
