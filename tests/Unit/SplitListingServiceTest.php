<?php

namespace Tests\Unit;

use App\Contracts\MarketplaceListingPublisher;
use App\Models\ListingSplit;
use App\Models\ListingSplitActivity;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use App\Services\SplitListings\SplitListingService;
use App\Services\Xs2\ListingPublishValidator;
use App\Services\Xs2\Xs2SellerListingTransformer;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SplitListingServiceTest extends TestCase
{
    private SplitListingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('services.xs2.minor_unit_divisor', 100);

        $this->service = new SplitListingService(
            Mockery::mock(MarketplaceListingPublisher::class),
            Mockery::mock(Xs2SellerListingTransformer::class),
            Mockery::mock(SellerApiClient::class),
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
            Mockery::mock(ListingSalesService::class),
        );
    }

    private function publishValidatorMock(): ListingPublishValidator
    {
        $validator = Mockery::mock(ListingPublishValidator::class);
        $validator->shouldReceive('validateForPublish')->byDefault();
        $validator->shouldReceive('validatePayload')->byDefault();

        return $validator;
    }

    public function test_quantity_split_uses_floor_division_with_remainder_on_last(): void
    {
        $this->assertSame([2, 2, 2, 2, 1], $this->service->calculateSplitQuantities(9, 2));
        $this->assertSame([3, 3, 3], $this->service->calculateSplitQuantities(9, 3));
        $this->assertSame([5], $this->service->calculateSplitQuantities(5, 5));
        $this->assertSame([4, 4, 2], $this->service->calculateSplitQuantities(10, 4));
        $this->assertSame([], $this->service->calculateSplitQuantities(0, 2));
        $this->assertSame([], $this->service->calculateSplitQuantities(5, 0));
    }

    public function test_percentage_prices_escalate_from_previous(): void
    {
        $listings = $this->service->calculatePrices([2, 2, 1], 100.0, 'percentage', 10.0);

        $this->assertSame([
            ['split_order' => 1, 'quantity' => 2, 'price' => 100.0],
            ['split_order' => 2, 'quantity' => 2, 'price' => 110.0],
            ['split_order' => 3, 'quantity' => 1, 'price' => 121.0],
        ], $listings);
    }

    public function test_fixed_prices_add_increment_per_listing(): void
    {
        $listings = $this->service->calculatePrices([2, 2, 2], 50.0, 'fixed', 5.0);

        $this->assertSame([
            ['split_order' => 1, 'quantity' => 2, 'price' => 50.0],
            ['split_order' => 2, 'quantity' => 2, 'price' => 55.0],
            ['split_order' => 3, 'quantity' => 2, 'price' => 60.0],
        ], $listings);
    }

    public function test_preview_totals_include_count_remaining_and_price_range(): void
    {
        $ticket = $this->ticket(['stock' => 9, 'net_rate' => 10000]);

        $preview = $this->service->preview($ticket, [
            'split_quantity' => 2,
            'price_increment_type' => 'percentage',
            'price_increment_value' => 10,
            'base_price' => 100,
        ]);

        $this->assertSame(5, $preview['totals']['listings_count']);
        $this->assertSame(9, $preview['totals']['total_quantity']);
        $this->assertSame(0, $preview['totals']['remaining_quantity']);
        $this->assertSame(100.0, $preview['totals']['lowest_price']);
        $this->assertSame(146.41, $preview['totals']['highest_price']);
    }

    public function test_preview_allows_split_quantity_above_stock_when_remainder_fits(): void
    {
        $ticket = $this->ticket(['stock' => 3, 'net_rate' => 10000]);

        $preview = $this->service->preview($ticket, [
            'split_quantity' => 4,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
            'base_price' => 100,
        ]);

        $this->assertSame([['split_order' => 1, 'quantity' => 3, 'price' => 100.0]], $preview['listings']);
        $this->assertSame(3, $preview['totals']['total_quantity']);
    }

    public function test_format_failure_message_includes_validation_errors(): void
    {
        $exception = \Illuminate\Validation\ValidationException::withMessages([
            'split' => ['Split quantity must be at least 1.'],
        ]);

        $message = $this->service->formatFailureMessage($exception);

        $this->assertSame('Split quantity must be at least 1.', $message);
    }

    public function test_log_activity_stores_long_failure_messages(): void
    {
        $ticket = $this->ticket(['stock' => 3, 'net_rate' => 10000]);
        $longMessage = str_repeat('Seller API validation failed. ', 40);

        $this->service->markFailed($ticket, $longMessage);

        $activity = ListingSplitActivity::query()->first();
        $this->assertNotNull($activity);
        $this->assertSame(mb_substr(trim($longMessage), 0, 4000), $activity->message);
    }

    public function test_stock_decrease_to_five_updates_trailing_split_quantity(): void
    {
        $publisher = Mockery::mock(MarketplaceListingPublisher::class);
        $publisher->shouldReceive('delete')->twice()->andReturn(['response' => ['ok' => true]]);
        $publisher->shouldReceive('update')->once()->with('sb-3', Mockery::on(function (array $payload): bool {
            return (int) ($payload['quantity'] ?? 0) === 1;
        }))->andReturnUsing(fn (string $id) => [
            'listing_id' => $id,
            'response' => ['ok' => true],
        ]);

        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldReceive('transform')->andReturn([
            'seller_reference' => 'XS2-t1',
            'match_id' => 1,
            'quantity' => 1,
            'price' => '100.00',
            'status' => '1',
            'seller_id' => 1,
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('sellerId')->andReturn(1);

        $service = new SplitListingService(
            $publisher,
            $transformer,
            $client,
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
        );

        $ticket = $this->ticket([
            'stock' => 10,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);

        foreach ([1, 2, 3, 4, 5] as $order) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'seatsbroker_listing_id' => 'sb-'.$order,
                'seller_reference' => 'XS2-t1-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 5),
                'split_order' => $order,
                'status' => 'active',
                'sync_status' => 'synced',
                'last_payload_hash' => 'stale',
            ]);
        }

        $ticket->update(['stock' => 5]); // 5/2 → [2,2,1]; delete S4,S5; update S3 qty 2→1
        $ticket->xs2Event->forceFill([
            'event_status' => 'available',
            'date_start_local' => now()->addWeek(),
        ])->save();

        $result = $service->syncListings($ticket->fresh());

        $this->assertSame('synced', $result['action']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['deleted']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(
            [2, 2, 1],
            ListingSplit::query()->where('status', 'active')->orderBy('split_order')->pluck('quantity')->all()
        );
    }

    public function test_delete_one_split_listing_cascades_to_all_active_splits(): void
    {
        $publisher = Mockery::mock(MarketplaceListingPublisher::class);
        $publisher->shouldReceive('delete')->times(4)->andReturn(['response' => ['ok' => true]]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('sellerId')->andReturn(1);

        $service = new SplitListingService(
            $publisher,
            Mockery::mock(Xs2SellerListingTransformer::class),
            $client,
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
        );

        $ticket = $this->ticket([
            'stock' => 8,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);

        foreach ([1, 2, 3, 4] as $order) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'seatsbroker_listing_id' => 'sb-'.$order,
                'seller_reference' => 'XS2-t1-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 5),
                'split_order' => $order,
                'status' => 'active',
                'sync_status' => 'synced',
            ]);
        }

        $trigger = ListingSplit::query()->where('master_listing_id', $ticket->id)->where('split_order', 2)->firstOrFail();
        $result = $service->deleteOneSplitListingCascade($ticket->fresh(), $trigger);

        $this->assertSame(4, $result['deleted']);
        $this->assertSame(0, ListingSplit::query()->where('master_listing_id', $ticket->id)->where('status', 'active')->count());
        $this->assertSame(4, ListingSplit::query()->where('master_listing_id', $ticket->id)->where('status', 'deleted')->count());
        $this->assertFalse($ticket->fresh()->split_enabled);
    }

    public function test_stock_decrease_deletes_trailing_listings(): void
    {
        $publisher = Mockery::mock(MarketplaceListingPublisher::class);
        $publisher->shouldReceive('delete')->twice()->andReturn(['response' => ['ok' => true]]);
        // Remaining listings keep same qty/price → no remote update (minimize API calls).

        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldReceive('transform')->never();

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('sellerId')->andReturn(1);

        $service = new SplitListingService(
            $publisher,
            $transformer,
            $client,
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
        );

        $ticket = $this->ticket([
            'stock' => 10,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);

        // 10/2 → 5 listings already published
        foreach ([1, 2, 3, 4, 5] as $order) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'seatsbroker_listing_id' => 'sb-'.$order,
                'seller_reference' => 'XS2-t1-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 5),
                'split_order' => $order,
                'status' => 'active',
                'sync_status' => 'synced',
                'last_payload_hash' => 'stale',
            ]);
        }

        $ticket->update(['stock' => 6]); // 6/2 → 3 listings; delete trailing 2
        $event = $ticket->xs2Event;
        $event->forceFill(['event_status' => 'available', 'date_start_local' => now()->addWeek()])->save();

        // Avoid isSellable complexity — mock via partial: set mapping sellable path
        // Xs2Event::isSellable — check what it needs
        $result = $service->syncListings($ticket->fresh());

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['deleted']);
        $this->assertSame(3, ListingSplit::query()->where('status', 'active')->count());
        $this->assertSame(2, ListingSplit::query()->where('status', 'deleted')->count());
    }

    public function test_stock_increase_creates_only_missing_listings(): void
    {
        $publisher = Mockery::mock(MarketplaceListingPublisher::class);
        $publisher->shouldReceive('create')->twice()->andReturnUsing(function (array $payload, string $key) {
            return ['listing_id' => 'new-'.$key, 'response' => ['ticket_id' => 'new-'.$key]];
        });
        // Existing rows keep same qty/price → no remote update.

        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldReceive('transform')->andReturn([
            'seller_reference' => 'XS2-t1',
            'match_id' => 1,
            'quantity' => 4,
            'price' => '100.00',
            'status' => '1',
            'seller_id' => 1,
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('sellerId')->andReturn(1);

        $service = new SplitListingService(
            $publisher,
            $transformer,
            $client,
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
        );

        $ticket = $this->ticket([
            'stock' => 4,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);
        $ticket->xs2Event->forceFill([
            'event_status' => 'available',
            'date_start_local' => now()->addWeek(),
        ])->save();

        foreach ([1, 2] as $order) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'seatsbroker_listing_id' => 'sb-'.$order,
                'seller_reference' => 'XS2-t1-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 5),
                'split_order' => $order,
                'status' => 'active',
                'sync_status' => 'synced',
                'last_payload_hash' => 'stale',
            ]);
        }

        $ticket->update(['stock' => 8]); // need 4 listings; create 2 missing
        $result = $service->syncListings($ticket->fresh());

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame(4, ListingSplit::query()->where('status', 'active')->count());
    }

    public function test_split_quantity_change_rebuilds_listing_count(): void
    {
        $publisher = Mockery::mock(MarketplaceListingPublisher::class);
        $publisher->shouldReceive('delete')->once()->andReturn(['response' => ['ok' => true]]);
        $publisher->shouldReceive('update')->twice()->andReturnUsing(fn (string $id) => [
            'listing_id' => $id,
            'response' => ['ok' => true],
        ]);

        $transformer = Mockery::mock(Xs2SellerListingTransformer::class);
        $transformer->shouldReceive('transform')->andReturn([
            'seller_reference' => 'XS2-t1',
            'match_id' => 1,
            'quantity' => 6,
            'price' => '100.00',
            'status' => '1',
            'seller_id' => 1,
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('sellerId')->andReturn(1);

        $service = new SplitListingService(
            $publisher,
            $transformer,
            $client,
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
        );

        $ticket = $this->ticket([
            'stock' => 6,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2, // was 3 listings of 2
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);
        $ticket->xs2Event->forceFill([
            'event_status' => 'available',
            'date_start_local' => now()->addWeek(),
        ])->save();

        foreach ([1, 2, 3] as $order) {
            ListingSplit::query()->create([
                'master_listing_id' => $ticket->id,
                'seatsbroker_listing_id' => 'sb-'.$order,
                'seller_reference' => 'XS2-t1-S'.$order,
                'quantity' => 2,
                'price' => 100 + (($order - 1) * 5),
                'split_order' => $order,
                'status' => 'active',
                'sync_status' => 'synced',
                'last_payload_hash' => 'stale',
            ]);
        }

        // Change to split qty 3 → 2 listings of 3; delete 1 extra? Actually 6/3=2 listings.
        // Orders 1,2 desired; order 3 deleted. But quantities change on 1,2 so update.
        // Wait - we need 2 listings, have 3 → delete 1. Orders 1 and 2 update qty 2→3.
        // That's delete 1, create 0, update 2. Let me adjust expectations.

        $ticket->update(['split_quantity' => 3]);
        $result = $service->rebuildListings($ticket->fresh());

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(2, ListingSplit::query()->where('status', 'active')->count());
        $this->assertSame([3, 3], ListingSplit::query()->where('status', 'active')->orderBy('split_order')->pluck('quantity')->all());
    }

    public function test_stock_at_two_syncs_without_deleting_when_unpublish_disabled(): void
    {
        config()->set('xs2.split_listings.unpublish_stock_max', 0);

        $publisher = Mockery::mock(MarketplaceListingPublisher::class);
        $publisher->shouldReceive('disable')->never();
        $publisher->shouldReceive('delete')->never();
        $publisher->shouldReceive('update')->never();
        $publisher->shouldReceive('create')->never();

        $service = new SplitListingService(
            $publisher,
            Mockery::mock(Xs2SellerListingTransformer::class),
            Mockery::mock(SellerApiClient::class),
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
        );

        $ticket = $this->ticket([
            'stock' => 2,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);

        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'seatsbroker_listing_id' => 'sb-1',
            'seller_reference' => 'XS2-t1-S1',
            'quantity' => 2,
            'price' => 100,
            'split_order' => 1,
            'status' => 'active',
            'sync_status' => 'synced',
            'last_payload_hash' => 'stale',
        ]);

        $result = $service->syncListings($ticket->fresh());

        $this->assertSame('synced', $result['action']);
        $this->assertSame(1, ListingSplit::query()->where('status', 'active')->count());
    }

    public function test_unavailable_stock_disables_splits_without_deleting(): void
    {
        $publisher = Mockery::mock(MarketplaceListingPublisher::class);
        $publisher->shouldReceive('disable')->once()->andReturn(['response' => ['ok' => true]]);
        $publisher->shouldReceive('delete')->never();

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('sellerId')->andReturn(1);

        $service = new SplitListingService(
            $publisher,
            Mockery::mock(Xs2SellerListingTransformer::class),
            $client,
            Mockery::mock(Xs2TicketMappingStatusService::class),
            $this->publishValidatorMock(),
        );

        $ticket = $this->ticket([
            'stock' => 0,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);

        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'seatsbroker_listing_id' => 'sb-1',
            'seller_reference' => 'XS2-t1-S1',
            'quantity' => 2,
            'price' => 100,
            'split_order' => 1,
            'status' => 'active',
            'sync_status' => 'synced',
        ]);

        $result = $service->syncListings($ticket->fresh());

        $this->assertSame('disabled_all', $result['action']);
        $this->assertSame(1, $result['disabled']);
        $this->assertTrue($ticket->fresh()->split_enabled);
        $this->assertSame(1, ListingSplit::query()->where('status', 'active')->count());
    }

    /** @param  array<string, mixed>  $attrs */
    private function ticket(array $attrs = []): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'event_status' => 'available',
            'date_start_local' => now()->addWeek(),
        ]);

        // Minimal mapping relation for loadMissing
        \App\Models\EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 99,
            'status' => 'mapped',
        ]);

        return Xs2Ticket::query()->create(array_merge([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 't1-'.uniqid(),
            'external_event_id' => 'e1',
            'ticket_status' => 'available',
            'stock' => 9,
            'net_rate' => 10000,
            'sync_status' => 'pending',
            'raw_payload' => [],
        ], $attrs));
    }

    private function createTables(): void
    {
        foreach ([
            'listing_split_activities',
            'listing_splits',
            'external_listing_mappings',
            'xs2_tickets',
            'event_mappings',
            'xs2_events',
        ] as $table) {
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
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('external_event_id')->nullable();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->unsignedBigInteger('face_value')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->unsignedInteger('split_quantity')->nullable();
            $table->string('price_increment_type', 20)->nullable();
            $table->decimal('price_increment_value', 12, 2)->nullable();
            $table->string('split_sync_status', 30)->default('idle');
            $table->text('split_sync_error')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->string('seatsbroker_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('split_order');
            $table->string('status', 20)->default('active');
            $table->string('sync_status', 30)->default('pending');
            $table->string('last_payload_hash', 64)->nullable();
            $table->json('last_request')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_split_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->unsignedBigInteger('listing_split_id')->nullable();
            $table->string('action', 50);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
