<?php

namespace Tests\Feature;

use App\Models\ExternalListingMapping;
use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Order;
use App\Models\Xs2OrderAttendee;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AdminOrderAttendeeWorkflowTest extends TestCase
{
    private const BOOKINGORDER_ID = 'xs2-bko-admin-workflow';

    private const TICKET_ID = 'xs2-ticket-admin-workflow_tck';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.sandbox.auto_create_orders_from_sb', true);
        config()->set('xs2.sandbox.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}');
        config()->set('xs2.bookingorder_guestdata_endpoint', '/v1/bookingorders/{bookingorder_id}/guestdata');
        config()->set('xs2.ticket_guestdata_endpoint', '/v1/tickets/{ticket_id}/guestdata');
        config()->set('xs2.eticket_download_endpoint', '/v1/etickets/download/{bookingorder_id}/{orderitem_id}/url/{url}');
        config()->set('xs2.sandbox.retry_times', 1);
    }

    public function test_admin_can_fetch_attendees_from_seats_broker(): void
    {
        $sbOrder = $this->seedLinkedOrder(withAttendees: false);

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('fetchBookings')
            ->once()
            ->with(['booking_no' => $sbOrder->booking_no])
            ->andReturn([
                'result' => [[
                    'booking_no' => $sbOrder->booking_no,
                    'booking_status' => SbOrder::STATUS_CONFIRMED,
                    'ticket_id' => 906584,
                    'listing_id' => '841765',
                    'quantity' => 1,
                    'attendee_details' => [[
                        'first_name' => 'Jane',
                        'last_name' => 'Doe',
                        'email' => 'jane@example.com',
                        'passport' => 'AB1234567',
                    ]],
                ]],
            ]);
        $this->app->instance(SellerApiClient::class, $client);

        $listingSales = Mockery::mock(ListingSalesService::class);
        $listingSales->shouldReceive('queueStockReconcileForListingIds')->once()->andReturn(['queued' => 0]);
        $this->app->instance(ListingSalesService::class, $listingSales);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/sb-orders/{$sbOrder->id}/fetch-attendees")
            ->assertOk()
            ->assertJsonPath('data.booking_no', $sbOrder->booking_no)
            ->assertJsonPath('data.attendees.0.first_name', 'Jane');

        $this->assertNotNull($sbOrder->fresh()->attendee_fetched_at);
    }

    public function test_move_to_xs2_requires_attendees(): void
    {
        $sbOrder = $this->seedLinkedOrder(withAttendees: false);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/sb-orders/{$sbOrder->id}/move-to-xs2")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Fetch attendee details from Seats Broker first.');
    }

    public function test_admin_can_copy_attendees_onto_xs2_order(): void
    {
        $sbOrder = $this->seedLinkedOrder(withAttendees: true);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/sb-orders/{$sbOrder->id}/move-to-xs2")
            ->assertOk()
            ->assertJsonPath('data.xs2_order.id', $sbOrder->xs2Order?->id);

        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        $this->assertNotNull($xs2Order->attendees_copied_from_sb_at);
        $this->assertDatabaseHas('xs2_order_attendees', [
            'xs2_order_id' => $xs2Order->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'passport' => 'AB1234567',
        ]);
    }

    public function test_push_guest_data_requires_xs2_attendees_and_persists_log(): void
    {
        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2-orders/{$xs2Order->id}/push-guest-data")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Move attendee details onto this XS2 order first.');

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/sb-orders/{$sbOrder->id}/move-to-xs2")
            ->assertOk();

        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'items' => [[
                        'ticket_id' => self::TICKET_ID,
                        'guests' => [[
                            'first_name' => ['condition' => 'required'],
                            'last_name' => ['condition' => 'required'],
                            'passport_number' => ['condition' => 'required'],
                        ]],
                    ]],
                ])
                ->push([
                    'guestdata_status' => 'completed',
                    'items' => [[
                        'ticket_id' => self::TICKET_ID,
                        'guests' => [[
                            'first_name' => 'Jane',
                            'last_name' => 'Doe',
                            'passport_number' => 'AB1234567',
                        ]],
                    ]],
                ]),
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2-orders/{$xs2Order->id}/push-guest-data")
            ->assertOk()
            ->assertJsonPath('data.latest_guest_data_log.response_status', 200);

        $this->assertDatabaseHas('xs2_order_guest_data_logs', [
            'xs2_order_id' => $xs2Order->id,
            'response_status' => 200,
        ]);
        $this->assertNotNull($xs2Order->fresh()->guest_data_synced_at);

        Http::assertSent(function ($request): bool {
            $guest = data_get($request->data(), 'items.0.guests.0');

            return $request->method() === 'PUT'
                && str_contains($request->url(), '/v1/bookingorders/'.self::BOOKINGORDER_ID.'/guestdata')
                && data_get($request->data(), 'items.0.ticket_id') === self::TICKET_ID
                && is_array($guest)
                && $guest['first_name'] === 'Jane'
                && $guest['last_name'] === 'Doe'
                && $guest['passport_number'] === 'AB1234567'
                && $guest['contact_email'] === 'jane@example.com'
                && $guest['lead_guest'] === true
                && $guest['street_name'] === 'Not provided'
                && $guest['city'] === 'Barcelona'
                && $guest['zip'] === '00000'
                && ! array_key_exists('guest_id', $guest)
                && ! array_key_exists('reservation_id', $guest)
                && ! array_key_exists('ticket_id', $guest)
                && ! array_key_exists('conditions', $guest);
        });
    }

    public function test_get_ticket_downloads_pdf_from_xs2_eticket_api(): void
    {
        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();
        Xs2OrderAttendee::query()->create([
            'xs2_order_id' => $xs2Order->id,
            'position' => 0,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::BOOKINGORDER_ID => Http::response([
                'bookingorder_id' => self::BOOKINGORDER_ID,
                'logistic_status' => 'completed',
                'items' => [[
                    'ticket_id' => self::TICKET_ID,
                    'orderitem_id' => 'orderitem-1',
                    'distribution_channel' => 'xs2event',
                    'download_link' => 'ticket-abc.pdf',
                ]],
            ]),
            'https://sandbox.xs2.test/v1/etickets/download/'.self::BOOKINGORDER_ID.'/orderitem-1/url/ticket-abc.pdf' => Http::response(
                '%PDF-1.4 workflow-ticket',
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="ticket-abc.pdf"',
                ],
            ),
        ]);

        $this->withToken($this->adminToken())
            ->post("/api/admin/xs2-orders/{$xs2Order->id}/get-ticket")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="ticket-abc.pdf"');

        $xs2Order->refresh();
        $this->assertNotNull($xs2Order->eticket_fetched_at);
        $this->assertTrue((bool) data_get($xs2Order->xs2_eticket_response, 'success'));
    }

    public function test_get_ticket_requires_attendees(): void
    {
        $sbOrder = $this->seedLinkedOrder(withAttendees: true);
        $xs2Order = Xs2Order::query()->where('sb_order_id', $sbOrder->id)->firstOrFail();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2-orders/{$xs2Order->id}/get-ticket")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Move attendee details onto this XS2 order before getting a ticket.');
    }

    private function seedLinkedOrder(bool $withAttendees): SbOrder
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'evt-admin-workflow',
            'event_name' => 'Admin Workflow Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => self::TICKET_ID,
            'external_event_id' => $event->external_event_id,
            'category_name' => 'Tribuna',
            'net_rate' => 10000,
            'currency_code' => 'EUR',
            'is_sandbox' => true,
            'ticket_status' => 'available',
            'stock' => 10,
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'seller_listing_id' => '841765',
            'seller_reference' => 'XS2-ref',
            'status' => 'active',
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-ADMIN-001',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 1,
            'match_name' => 'Admin Workflow Match',
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::BOOKINGORDER_ID,
            'is_sandbox' => true,
            'sb_order_id' => $sbOrder->id,
            'xs2_booking_id' => 'xs2-booking-admin-workflow',
            'xs2_bookingorder_id' => self::BOOKINGORDER_ID,
            'xs2_reservation_id' => 'xs2-reservation-admin-workflow_rsv',
            'external_ticket_id' => self::TICKET_ID,
            'quantity' => 1,
            'order_status' => 'confirmed',
        ]);

        if ($withAttendees) {
            SbOrderAttendee::query()->create([
                'sb_order_id' => $sbOrder->id,
                'position' => 0,
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.com',
                'passport' => 'AB1234567',
            ]);
        }

        return $sbOrder->load('xs2Order');
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);

        return $admin->createToken('admin-order-attendee-workflow')->plainTextToken;
    }
}
