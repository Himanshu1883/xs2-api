<?php

namespace Tests\Unit;

use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\SbOrder;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\ListingSalesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ListingSalesServiceTest extends TestCase
{
    private ListingSalesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->service = new ListingSalesService;
    }

    public function test_sold_matches_ticket_id_to_seller_listing_id_and_excludes_cancelled(): void
    {
        $ticket = $this->ticket(['stock' => 10]);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'seller_listing_id' => '906584',
            'seller_reference' => 'XS2-ref',
            'status' => 'active',
        ]);

        SbOrder::query()->create([
            'booking_no' => 'A1',
            'booking_status' => SbOrder::STATUS_PENDING,
            'booking_status_text' => 'Pending Confirmation',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 3,
        ]);
        SbOrder::query()->create([
            'booking_no' => 'A2',
            'booking_status' => SbOrder::STATUS_PENDING,
            'booking_status_text' => 'Pending Confirmation',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 3,
        ]);
        SbOrder::query()->create([
            'booking_no' => 'A3',
            'booking_status' => SbOrder::STATUS_CANCELLED,
            'booking_status_text' => 'Cancelled',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 2,
        ]);

        $ticket->load(['listingMapping', 'listingSplits']);
        $this->service->attachSalesToTickets(collect([$ticket]));

        $this->assertSame(6, $ticket->sold_quantity);
        $this->assertSame(4, $ticket->remaining_quantity);
    }

    public function test_split_sales_are_attributed_per_seatsbroker_listing_id(): void
    {
        $ticket = $this->ticket(['stock' => 6, 'split_enabled' => true]);
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'seatsbroker_listing_id' => 'split-1',
            'seller_reference' => 'ref-1',
            'quantity' => 2,
            'price' => 10,
            'split_order' => 1,
            'status' => 'active',
        ]);
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'seatsbroker_listing_id' => 'split-2',
            'seller_reference' => 'ref-2',
            'quantity' => 2,
            'price' => 12,
            'split_order' => 2,
            'status' => 'active',
        ]);

        SbOrder::query()->create([
            'booking_no' => 'S1',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => null,
            'listing_id' => 'split-1',
            'quantity' => 2,
        ]);

        $ticket->load(['listingMapping', 'listingSplits']);
        $this->service->attachSalesToTickets(collect([$ticket]));

        $this->assertSame(2, $ticket->sold_quantity);
        $this->assertSame(4, $ticket->remaining_quantity);
        $this->assertSame(2, $ticket->split_sales[0]['sold_quantity']);
        $this->assertSame(0, $ticket->split_sales[0]['remaining_quantity']);
        $this->assertSame(0, $ticket->split_sales[1]['sold_quantity']);
        $this->assertSame(2, $ticket->split_sales[1]['remaining_quantity']);
    }

    private function ticket(array $attrs = []): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'evt-1',
            'event_name' => 'Test Event',
            'date_start_local' => now()->addDays(10),
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'xt-1',
            'category_name' => 'Longside',
            'ticket_status' => 'available',
            'stock' => 10,
            'currency_code' => 'EUR',
            'net_rate' => 1000,
            ...$attrs,
        ]);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('sb_orders');
        Schema::dropIfExists('listing_splits');
        Schema::dropIfExists('external_listing_mappings');
        Schema::dropIfExists('xs2_tickets');
        Schema::dropIfExists('xs2_events');

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->nullable();
            $table->string('event_name')->nullable();
            $table->timestamp('date_start_local')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('xs2_event_id');
            $table->string('external_ticket_id')->nullable();
            $table->string('category_name')->nullable();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->string('currency_code')->nullable();
            $table->unsignedInteger('net_rate')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('external_listing_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->nullable();
            $table->unsignedBigInteger('xs2_ticket_id');
            $table->string('seller_listing_id')->nullable();
            $table->string('seller_reference')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->string('seatsbroker_listing_id')->nullable();
            $table->string('seller_reference');
            $table->unsignedInteger('quantity');
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('split_order');
            $table->string('status')->default('active');
            $table->string('sync_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('sb_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('booking_no')->unique();
            $table->unsignedSmallInteger('booking_status')->nullable();
            $table->string('booking_status_text')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('listing_id')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->timestamps();
        });
    }
}
