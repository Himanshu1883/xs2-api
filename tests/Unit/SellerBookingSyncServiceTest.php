<?php

namespace Tests\Unit;

use App\Models\SbOrder;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use App\Services\SellerApi\SellerBookingSyncService;
use App\Services\Xs2\SbOrderXs2SandboxOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SellerBookingSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_sync_order_updates_existing_booking_from_seller_api(): void
    {
        SbOrder::query()->create([
            'booking_no' => 'SB-100',
            'booking_status' => SbOrder::STATUS_PENDING,
            'booking_status_text' => 'Pending Confirmation',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 2,
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('fetchBookings')
            ->once()
            ->with(['booking_no' => 'SB-100'])
            ->andReturn([
                'result' => [
                    [
                        'booking_no' => 'SB-100',
                        'booking_status' => SbOrder::STATUS_CONFIRMED,
                        'booking_status_text' => 'Confirmed',
                        'ticket_id' => 906584,
                        'listing_id' => '841765',
                        'quantity' => 2,
                        'attendee_details' => [],
                    ],
                ],
            ]);

        $listingSales = Mockery::mock(ListingSalesService::class);
        $listingSales->shouldReceive('queueStockReconcileForListingIds')
            ->once()
            ->andReturn(['queued' => 0]);

        $xs2SandboxOrders = Mockery::mock(SbOrderXs2SandboxOrderService::class);
        $xs2SandboxOrders->shouldReceive('queueIfEligible')->once()->andReturn(false);

        $service = new SellerBookingSyncService($client, $listingSales, $xs2SandboxOrders);
        $refreshed = $service->syncOrder(SbOrder::query()->where('booking_no', 'SB-100')->firstOrFail());

        $this->assertSame(SbOrder::STATUS_CONFIRMED, $refreshed->booking_status);
        $this->assertSame('Confirmed', $refreshed->booking_status_text);
        $this->assertNotNull($refreshed->synced_at);
    }

    public function test_sync_order_throws_when_booking_missing_from_seller_api(): void
    {
        $order = SbOrder::query()->create([
            'booking_no' => 'SB-404',
            'booking_status' => SbOrder::STATUS_PENDING,
            'booking_status_text' => 'Pending Confirmation',
        ]);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('fetchBookings')
            ->once()
            ->with(['booking_no' => 'SB-404'])
            ->andReturn(['result' => []]);

        $listingSales = Mockery::mock(ListingSalesService::class);
        $listingSales->shouldIgnoreMissing();

        $xs2SandboxOrders = Mockery::mock(SbOrderXs2SandboxOrderService::class);
        $xs2SandboxOrders->shouldIgnoreMissing();

        $service = new SellerBookingSyncService($client, $listingSales, $xs2SandboxOrders);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Booking SB-404 was not found in Seller API response.');

        $service->syncOrder($order);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('sb_order_attendees');
        Schema::dropIfExists('sb_orders');

        Schema::create('sb_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('booking_no', 64)->unique();
            $table->unsignedSmallInteger('booking_status')->nullable();
            $table->string('booking_status_text', 64)->nullable();
            $table->decimal('ticket_amount', 12, 2)->nullable();
            $table->string('currency_type', 16)->nullable();
            $table->string('match_name')->nullable();
            $table->string('tournament_name')->nullable();
            $table->string('stadium_name')->nullable();
            $table->date('match_date')->nullable();
            $table->string('match_time', 32)->nullable();
            $table->unsignedBigInteger('match_id')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('listing_id', 64)->nullable();
            $table->string('ticketid', 64)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('split')->nullable();
            $table->string('seat_category')->nullable();
            $table->string('ticket_block')->nullable();
            $table->string('row')->nullable();
            $table->string('section')->nullable();
            $table->text('listing_note')->nullable();
            $table->string('ticket_types_name')->nullable();
            $table->string('buyer_first_name')->nullable();
            $table->string('buyer_last_name')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sb_order_attendees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sb_order_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('dob')->nullable();
            $table->string('nationality')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('passport')->nullable();
            $table->string('gender')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }
}
