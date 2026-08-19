<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Xs2SandboxTestOrder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Xs2SandboxTestFlowTest extends TestCase
{
    private const SANDBOX_BOOKING_ID = 'sandbox-booking-1_bkn';

    private const SANDBOX_BOOKINGORDER_ID = 'sandbox-bookingorder-1_bko';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.sandbox.test_event_id', null);
        config()->set('xs2.events_endpoint', '/v1/events');
        config()->set('xs2.event_detail_endpoint', '/v1/events/{event_id}');
        config()->set('xs2.tickets_endpoint', '/v1/tickets');
        config()->set('xs2.reservations_endpoint', '/v1/reservations');
        config()->set('xs2.sandbox.bookings_endpoint', '/v1/bookings');
        config()->set('xs2.sandbox.booking_detail_endpoint', '/v1/bookings/{booking_id}');
        config()->set('xs2.sandbox.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.sandbox.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}');
        config()->set('xs2.ticket_guestdata_endpoint', '/v1/tickets/{ticket_id}/guestdata');
        config()->set('xs2.bookingorder_guestdata_endpoint', '/v1/bookingorders/{bookingorder_id}/guestdata');
        config()->set('xs2.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}');
        config()->set('xs2.eticket_download_endpoint', '/v1/etickets/download/{bookingorder_id}/{orderitem_id}/url/{url}');
        config()->set('xs2.sandbox.retry_times', 1);
        config()->set('xs2.sandbox.max_event_attempts', 15);
    }

    public function test_admin_can_run_isolated_sandbox_test_flow_without_touching_production_tables(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/tickets?*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'event_id' => 'sandbox-event-1',
                    'ticket_name' => 'Category A',
                    'ticket_status' => 'available',
                    'stock' => 5,
                    'net_rate' => 12000,
                    'sales_price' => 12000,
                    'currency_code' => 'EUR',
                ]],
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_size' => 1],
            ]),
            'https://sandbox.xs2.test/v1/events/sandbox-event-1' => Http::response([
                'event_id' => 'sandbox-event-1',
                'event_name' => 'Sandbox Derby',
                'date_start' => '2026-10-01T15:00:00',
                'venue_name' => 'Test Arena',
                'sport_type' => 'soccer',
            ]),
            'https://sandbox.xs2.test/v1/reservations' => Http::response([
                'reservation_id' => 'sandbox-reservation-1_rsv',
                'status' => 'active',
            ], 201),
            'https://sandbox.xs2.test/v1/bookings' => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBX123',
                'reservation_id' => 'sandbox-reservation-1_rsv',
            ], 201),
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                ]],
            ]),
        ]);

        $token = $this->adminToken();

        $eventResponse = $this->withToken($token)
            ->getJson('/api/admin/xs2/sandbox-test/event')
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.is_sandbox', true)
            ->assertJsonPath('data.source', 'first_available_ticket_event')
            ->assertJsonPath('data.event.external_event_id', 'sandbox-event-1')
            ->assertJsonPath('data.listing.ticket_id', 'sandbox-ticket-1_tck')
            ->assertJsonPath('meta.events_tried', 1)
            ->assertJsonPath('meta.skipped_events', []);

        $listingResponse = $this->withToken($token)
            ->getJson('/api/admin/xs2/sandbox-test/listing?event_id=sandbox-event-1')
            ->assertOk()
            ->assertJsonPath('data.listing.ticket_id', 'sandbox-ticket-1_tck')
            ->assertJsonPath('data.is_sandbox', true);

        $orderResponse = $this->withToken($token)
            ->postJson('/api/admin/xs2/sandbox-test/orders', [
                'event' => $eventResponse->json('data.event'),
                'listing' => $eventResponse->json('data.listing'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.is_sandbox', true)
            ->assertJsonPath('data.customer_email', 'xs2-sandbox@example.com')
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.seatsbroker_order_id', fn (string $value): bool => str_starts_with($value, 'SB-SANDBOX-'));

        $orderId = (int) $orderResponse->json('data.id');
        $this->assertSame(1, Xs2SandboxTestOrder::count());

        $xs2OrderResponse = $this->withToken($token)
            ->postJson("/api/admin/xs2/sandbox-test/orders/{$orderId}/xs2-order")
            ->assertCreated()
            ->assertJsonPath('data.xs2_reservation_id', 'sandbox-reservation-1_rsv')
            ->assertJsonPath('data.xs2_booking_id', self::SANDBOX_BOOKING_ID)
            ->assertJsonPath('data.xs2_bookingorder_id', self::SANDBOX_BOOKINGORDER_ID)
            ->assertJsonPath('data.xs2_booking_code', 'SBX123')
            ->assertJsonPath('meta.already_created', false);

        $this->withToken($token)
            ->postJson("/api/admin/xs2/sandbox-test/orders/{$orderId}/xs2-order")
            ->assertOk()
            ->assertJsonPath('message', 'XS2 Order Already Created')
            ->assertJsonPath('meta.already_created', true);

        Http::assertSentCount(7);

        $this->withToken($token)
            ->getJson("/api/admin/xs2/sandbox-test/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'xs2_order_created');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/reservations')
            && ($request->data()['items'][0]['quantity'] ?? null) === 1);
    }

    public function test_admin_can_create_dummy_order_with_custom_quantity(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/tickets?*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'event_id' => 'sandbox-event-1',
                    'ticket_name' => 'Category A',
                    'ticket_status' => 'available',
                    'stock' => 5,
                    'net_rate' => 12000,
                    'sales_price' => 12000,
                    'currency_code' => 'EUR',
                ]],
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_size' => 1],
            ]),
            'https://sandbox.xs2.test/v1/events/sandbox-event-1' => Http::response([
                'event_id' => 'sandbox-event-1',
                'event_name' => 'Sandbox Derby',
            ]),
            'https://sandbox.xs2.test/v1/reservations' => Http::response([
                'reservation_id' => 'sandbox-reservation-qty_rsv',
                'status' => 'active',
            ], 201),
            'https://sandbox.xs2.test/v1/bookings' => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBX456',
                'reservation_id' => 'sandbox-reservation-qty_rsv',
            ], 201),
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                ]],
            ]),
        ]);

        $token = $this->adminToken();

        $eventResponse = $this->withToken($token)
            ->getJson('/api/admin/xs2/sandbox-test/event')
            ->assertOk();

        $orderResponse = $this->withToken($token)
            ->postJson('/api/admin/xs2/sandbox-test/orders', [
                'event' => $eventResponse->json('data.event'),
                'listing' => $eventResponse->json('data.listing'),
                'quantity' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 3);

        $orderId = (int) $orderResponse->json('data.id');

        $this->withToken($token)
            ->postJson("/api/admin/xs2/sandbox-test/orders/{$orderId}/xs2-order")
            ->assertCreated()
            ->assertJsonPath('data.xs2_reservation_id', 'sandbox-reservation-qty_rsv');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/reservations')
            && ($request->data()['items'][0]['quantity'] ?? null) === 3);

        $this->assertSame(3, Xs2SandboxTestOrder::query()->findOrFail($orderId)->quantity);
    }

    public function test_dummy_order_rejects_quantity_above_listing_stock(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/tickets?*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'event_id' => 'sandbox-event-1',
                    'ticket_name' => 'Category A',
                    'ticket_status' => 'available',
                    'stock' => 2,
                    'net_rate' => 12000,
                    'sales_price' => 12000,
                    'currency_code' => 'EUR',
                ]],
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_size' => 1],
            ]),
            'https://sandbox.xs2.test/v1/events/sandbox-event-1' => Http::response([
                'event_id' => 'sandbox-event-1',
                'event_name' => 'Sandbox Derby',
            ]),
        ]);

        $token = $this->adminToken();

        $eventResponse = $this->withToken($token)
            ->getJson('/api/admin/xs2/sandbox-test/event')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/admin/xs2/sandbox-test/orders', [
                'event' => $eventResponse->json('data.event'),
                'listing' => $eventResponse->json('data.listing'),
                'quantity' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Order quantity 5 exceeds the maximum allowed (2) for this sandbox listing.');
    }

    public function test_admin_can_list_and_refresh_saved_sandbox_test_orders(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookings/'.self::SANDBOX_BOOKING_ID => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBX123',
                'status' => 'confirmed',
                'tickets' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'download_url' => 'https://sandbox.xs2.test/tickets/abc.pdf',
                ]],
            ]),
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::sequence()
                ->push([
                    'bookingorders' => [[
                        'booking_id' => self::SANDBOX_BOOKING_ID,
                        'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    ]],
                ])
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'booking_code' => 'SBX123',
                    'logistic_status' => 'completed',
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'orderitem_id' => 'sandbox-item-1_bkd',
                        'download_link' => 'ticket-abc.pdf',
                        'distribution_channel' => 'xs2event',
                    ]],
                ]),
        ]);

        $order = Xs2SandboxTestOrder::query()->create([
            'seatsbroker_order_id' => 'SB-SANDBOX-LIST01',
            'environment' => Xs2SandboxTestOrder::ENVIRONMENT,
            'is_sandbox' => true,
            'status' => Xs2SandboxTestOrder::STATUS_XS2_ORDER_CREATED,
            'customer_name' => 'XS2 Sandbox Test Customer',
            'customer_email' => 'xs2-sandbox@example.com',
            'quantity' => 1,
            'xs2_event_id' => 'sandbox-event-1',
            'xs2_event_payload' => ['external_event_id' => 'sandbox-event-1', 'event_name' => 'Sandbox Derby'],
            'xs2_ticket_id' => 'sandbox-ticket-1_tck',
            'xs2_ticket_payload' => ['ticket_id' => 'sandbox-ticket-1_tck', 'net_rate' => 12000],
            'xs2_reservation_id' => 'sandbox-reservation-1_rsv',
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            'xs2_booking_code' => 'SBX123',
            'xs2_booking_response' => ['booking_id' => self::SANDBOX_BOOKING_ID, 'booking_code' => 'SBX123'],
            'sb_order_created_at' => now(),
            'xs2_order_created_at' => now(),
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/xs2/sandbox-test/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.seatsbroker_order_id', 'SB-SANDBOX-LIST01')
            ->assertJsonPath('data.0.event_name', 'Sandbox Derby')
            ->assertJsonPath('data.0.xs2_ticket_id', 'sandbox-ticket-1_tck')
            ->assertJsonPath('data.0.xs2_booking_code', 'SBX123');

        $this->withToken($token)
            ->postJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/refresh-from-xs2")
            ->assertOk()
            ->assertJsonPath('data.xs2_booking_response.logistic_status', 'completed')
            ->assertJsonPath('data.xs2_booking_response.items.0.download_link', 'ticket-abc.pdf')
            ->assertJsonPath('meta.refreshed_from_xs2', true);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/bookings/'.self::SANDBOX_BOOKING_ID));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID)
            && ! str_contains($request->url(), '/guestdata'));
    }

    public function test_admin_can_fetch_remote_xs2_sandbox_booking_orders(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'booking_code' => 'SBX123',
                    'booking_email' => 'xs2-sandbox@example.com',
                    'event_id' => 'sandbox-event-1',
                    'event_name' => 'Sandbox Derby',
                    'logistic_status' => 'completed',
                    'guestdata_status' => 'completed',
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'orderitem_id' => 'sandbox-item-1_bkd',
                    ]],
                    'created' => '2026-08-11T12:00:00+00:00',
                ]],
                'pagination' => [
                    'total_size' => 1,
                    'page_size' => 25,
                    'page_number' => 1,
                    'next_page' => '',
                    'previous_page' => '',
                ],
            ]),
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/xs2/sandbox-test/xs2-bookingorders?booking_email=xs2-sandbox@example.com')
            ->assertOk()
            ->assertJsonPath('data.bookingorders.0.bookingorder_id', self::SANDBOX_BOOKINGORDER_ID)
            ->assertJsonPath('data.bookingorders.0.booking_code', 'SBX123')
            ->assertJsonPath('data.bookingorders.0.logistic_status', 'completed')
            ->assertJsonPath('data.bookingorders.0.item_count', 1)
            ->assertJsonPath('meta.pagination.total_size', 1)
            ->assertJsonPath('meta.request_query.booking_email', 'xs2-sandbox@example.com');

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/bookingorders')
            && str_contains($request->url(), 'booking_email=xs2-sandbox%40example.com'));
    }

    public function test_admin_can_import_remote_xs2_sandbox_booking_order_into_local_table(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID => Http::response([
                'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBX123',
                'booking_email' => 'xs2-sandbox@example.com',
                'event_id' => 'sandbox-event-1',
                'event_name' => 'Sandbox Derby',
                'logistic_status' => 'completed',
                'guestdata_status' => 'completed',
                'booking_reference' => 'SB-SANDBOX-IMPORT01',
                'items' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'orderitem_id' => 'sandbox-item-1_bkd',
                    'quantity' => 2,
                ]],
                'created' => '2026-08-11T12:00:00+00:00',
            ]),
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/xs2/sandbox-test/orders/import-from-xs2', [
                'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            ])
            ->assertCreated()
            ->assertJsonPath('data.seatsbroker_order_id', 'SB-SANDBOX-IMPORT01')
            ->assertJsonPath('data.status', 'xs2_order_created')
            ->assertJsonPath('data.xs2_booking_id', self::SANDBOX_BOOKING_ID)
            ->assertJsonPath('data.xs2_bookingorder_id', self::SANDBOX_BOOKINGORDER_ID)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('meta.imported_from_xs2', true);

        $this->assertDatabaseHas('xs2_sandbox_test_orders', [
            'seatsbroker_order_id' => 'SB-SANDBOX-IMPORT01',
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            'status' => 'xs2_order_created',
        ]);

        $this->withToken($token)
            ->postJson('/api/admin/xs2/sandbox-test/orders/import-from-xs2', [
                'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            ])
            ->assertOk()
            ->assertJsonPath('meta.already_imported', true);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID));
    }

    public function test_admin_can_load_and_update_sandbox_order_guest_data(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 2,
                        'guests' => [
                            [
                                'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'country_of_residence' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'passport_number' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'lead_guest' => true,
                                'guest_id' => 'guest-1_gst',
                            ],
                            [
                                'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'country_of_residence' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'passport_number' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'lead_guest' => false,
                                'guest_id' => 'guest-2_gst',
                            ],
                        ],
                    ]],
                ])
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 2,
                        'guests' => [
                            [
                                'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'country_of_residence' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'passport_number' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'lead_guest' => true,
                                'guest_id' => 'guest-1_gst',
                            ],
                            [
                                'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'country_of_residence' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'passport_number' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                                'lead_guest' => false,
                                'guest_id' => 'guest-2_gst',
                            ],
                        ],
                    ]],
                ])
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 2,
                        'guests' => [
                            [
                                'first_name' => 'John',
                                'last_name' => 'Doe',
                                'country_of_residence' => 'NLD',
                                'passport_number' => 'AB123456',
                                'lead_guest' => true,
                                'guest_id' => 'guest-1_gst',
                            ],
                            [
                                'first_name' => 'Jane',
                                'last_name' => 'Doe',
                                'country_of_residence' => 'NLD',
                                'passport_number' => 'CD789012',
                                'lead_guest' => false,
                                'guest_id' => 'guest-2_gst',
                            ],
                        ],
                    ]],
                ]),
            'https://sandbox.xs2.test/v1/tickets/sandbox-ticket-1_tck/guestdata' => Http::response([
                'guest_data_requirements' => [
                    'first_name',
                    'last_name',
                    'country_of_residence',
                    'passport_number',
                ],
            ]),
        ]);

        $order = $this->createGuestDataReadyOrder(['quantity' => 2]);
        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data")
            ->assertOk()
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.xs2_booking_id', self::SANDBOX_BOOKING_ID)
            ->assertJsonPath('data.xs2_bookingorder_id', self::SANDBOX_BOOKINGORDER_ID)
            ->assertJsonPath('data.guest_data_requirements.0', 'first_name')
            ->assertJsonPath('data.customer_email', 'xs2-sandbox@example.com');

        $this->withToken($token)
            ->putJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data", [
                'guests' => [
                    [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'country_of_residence' => 'NLD',
                        'passport_number' => 'AB123456',
                    ],
                    [
                        'first_name' => 'Jane',
                        'last_name' => 'Doe',
                        'country_of_residence' => 'NLD',
                        'passport_number' => 'CD789012',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.xs2_guest_data_response.items.0.guests.0.first_name', 'John')
            ->assertJsonPath('data.xs2_guest_data_request.items.0.guests.1.lead_guest', false);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata')
            && ($request->data()['items'][0]['guests'][0]['lead_guest'] ?? null) === true
            && ($request->data()['items'][0]['guests'][0]['guest_id'] ?? null) === 'guest-1_gst');

        $order->refresh();
        $this->assertIsArray($order->xs2_guest_data_request);
        $this->assertIsArray($order->xs2_guest_data_response);
    }

    public function test_guest_data_update_rejects_wrong_guest_count(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/tickets/sandbox-ticket-1_tck/guestdata' => Http::response([
                'guest_data_requirements' => ['first_name', 'last_name'],
            ]),
        ]);

        $order = $this->createGuestDataReadyOrder(['quantity' => 2]);
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data", [
                'guests' => [
                    ['first_name' => 'Only', 'last_name' => 'Guest'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Expected 2 guest(s) for this order but received 1.');
    }

    public function test_guest_data_endpoints_require_xs2_booking_id(): void
    {
        $order = Xs2SandboxTestOrder::query()->create([
            'seatsbroker_order_id' => 'SB-SANDBOX-NOBOOK',
            'environment' => Xs2SandboxTestOrder::ENVIRONMENT,
            'is_sandbox' => true,
            'status' => Xs2SandboxTestOrder::STATUS_SB_ORDER_CREATED,
            'customer_name' => 'XS2 Sandbox Test Customer',
            'customer_email' => 'xs2-sandbox@example.com',
            'quantity' => 1,
            'xs2_event_id' => 'sandbox-event-1',
            'xs2_event_payload' => ['external_event_id' => 'sandbox-event-1'],
            'xs2_ticket_id' => 'sandbox-ticket-1_tck',
            'xs2_ticket_payload' => ['ticket_id' => 'sandbox-ticket-1_tck'],
            'sb_order_created_at' => now(),
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Guest data can only be updated after an XS2 booking has been created.');

        $this->withToken($token)
            ->putJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data", [
                'guests' => [['first_name' => 'John', 'last_name' => 'Doe']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Guest data can only be updated after an XS2 booking has been created.');
    }

    public function test_guest_data_resolves_bookingorder_id_from_api_when_not_stored(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders?*' => Http::response([
                'bookingorders' => [[
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                ]],
            ]),
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 1,
                        'guests' => [[
                            'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                            'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                            'lead_guest' => true,
                        ]],
                    ]],
                ])
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 1,
                        'guests' => [[
                            'first_name' => 'John',
                            'last_name' => 'Doe',
                            'lead_guest' => true,
                        ]],
                    ]],
                ]),
        ]);

        $order = $this->createGuestDataReadyOrder([
            'xs2_bookingorder_id' => null,
        ]);
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data", [
                'guests' => [
                    ['first_name' => 'John', 'last_name' => 'Doe'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.xs2_bookingorder_id', self::SANDBOX_BOOKINGORDER_ID);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/bookingorders')
            && str_contains($request->url(), 'booking_id='.self::SANDBOX_BOOKING_ID));

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata'));
    }

    public function test_guest_data_update_rejects_missing_required_fields_before_xs2_call(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata*' => Http::response([
                'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                'items' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'quantity' => 1,
                    'guests' => [[
                        'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                        'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                        'passport_number' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                        'lead_guest' => true,
                    ]],
                ]],
            ]),
        ]);

        $order = $this->createGuestDataReadyOrder(['quantity' => 1]);
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data", [
                'guests' => [
                    ['first_name' => 'John', 'last_name' => 'Doe', 'country_of_residence' => 'NLD'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'passport_number is required for guest 1.');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/guestdata'));
    }

    public function test_guest_data_re_resolves_bookingorder_id_when_booking_id_was_stored(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders?*' => Http::response([
                'bookingorders' => [[
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                ]],
            ]),
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 1,
                        'guests' => [[
                            'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                            'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                            'lead_guest' => true,
                        ]],
                    ]],
                ])
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 1,
                        'guests' => [[
                            'first_name' => 'John',
                            'last_name' => 'Doe',
                            'lead_guest' => true,
                        ]],
                    ]],
                ]),
        ]);

        $order = $this->createGuestDataReadyOrder([
            'quantity' => 1,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKING_ID,
        ]);
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data", [
                'guests' => [
                    ['first_name' => 'John', 'last_name' => 'Doe'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.xs2_bookingorder_id', self::SANDBOX_BOOKINGORDER_ID);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata'));
    }

    public function test_guest_data_update_surfaces_xs2_validation_errors(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-1_tck',
                        'quantity' => 1,
                        'guests' => [[
                            'first_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                            'last_name' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                            'passport_number' => ['value' => null, 'condition' => 'pre_download', 'error' => 'required'],
                            'lead_guest' => true,
                        ]],
                    ]],
                ])
                ->push([
                    'message' => 'passport_number is required for guest 1',
                ], 422),
        ]);

        $order = $this->createGuestDataReadyOrder(['quantity' => 1]);
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/guest-data", [
                'guests' => [
                    [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'country_of_residence' => 'NLD',
                        'passport_number' => 'AB123456',
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'XS2 sandbox request failed with HTTP 422 (https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID.'/guestdata): passport_number is required for guest 1');
    }

    public function test_admin_can_download_sandbox_eticket_pdf(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/etickets/download/'.self::SANDBOX_BOOKINGORDER_ID.'/sandbox-orderitem-1_bkd/url/ticket-abc.pdf' => Http::response(
                '%PDF-1.4 sandbox-ticket',
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="ticket-abc.pdf"',
                ],
            ),
        ]);

        $order = $this->createEticketReadyOrder();
        $token = $this->adminToken();

        $response = $this->withToken($token)
            ->get("/api/admin/xs2/sandbox-test/orders/{$order->id}/eticket");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="ticket-abc.pdf"');
        $this->assertSame('%PDF-1.4 sandbox-ticket', $response->getContent());

        $order->refresh();
        $this->assertSame(self::SANDBOX_BOOKINGORDER_ID, data_get($order->xs2_eticket_request, 'bookingorder_id'));
        $this->assertSame('sandbox-orderitem-1_bkd', data_get($order->xs2_eticket_request, 'orderitem_id'));
        $this->assertTrue((bool) data_get($order->xs2_eticket_response, 'success'));

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/etickets/download/'.self::SANDBOX_BOOKINGORDER_ID.'/sandbox-orderitem-1_bkd/url/ticket-abc.pdf'));
    }

    public function test_eticket_download_fetches_bookingorder_when_stored_response_has_no_links(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookings/'.self::SANDBOX_BOOKING_ID => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBX123',
                'status' => 'confirmed',
            ]),
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID => Http::response([
                'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                'logistic_status' => 'completed',
                'items' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'orderitem_id' => 'sandbox-orderitem-1_bkd',
                    'distribution_channel' => 'xs2event',
                    'download_link' => 'ticket-abc.pdf',
                ]],
            ]),
            'https://sandbox.xs2.test/v1/etickets/download/'.self::SANDBOX_BOOKINGORDER_ID.'/sandbox-orderitem-1_bkd/url/ticket-abc.pdf' => Http::response(
                '%PDF-1.4 fetched-bookingorder',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $order = $this->createEticketReadyOrder([
            'xs2_booking_response' => [
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBX123',
            ],
        ]);

        $this->withToken($this->adminToken())
            ->get("/api/admin/xs2/sandbox-test/orders/{$order->id}/eticket")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID));
    }

    public function test_eticket_download_requires_xs2_booking_id(): void
    {
        $order = Xs2SandboxTestOrder::query()->create([
            'seatsbroker_order_id' => 'SB-SANDBOX-NOETIX',
            'environment' => Xs2SandboxTestOrder::ENVIRONMENT,
            'is_sandbox' => true,
            'status' => Xs2SandboxTestOrder::STATUS_SB_ORDER_CREATED,
            'customer_name' => 'XS2 Sandbox Test Customer',
            'customer_email' => 'xs2-sandbox@example.com',
            'quantity' => 1,
            'xs2_event_id' => 'sandbox-event-1',
            'xs2_event_payload' => ['external_event_id' => 'sandbox-event-1'],
            'xs2_ticket_id' => 'sandbox-ticket-1_tck',
            'xs2_ticket_payload' => ['ticket_id' => 'sandbox-ticket-1_tck'],
            'sb_order_created_at' => now(),
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/eticket")
            ->assertStatus(422)
            ->assertJsonPath('message', 'E-tickets can only be downloaded after an XS2 booking has been created.');
    }

    public function test_eticket_download_returns_not_ready_when_no_download_links(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookings/'.self::SANDBOX_BOOKING_ID => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'logistic_status' => 'processing',
                'items' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'orderitem_id' => 'sandbox-orderitem-1_bkd',
                    'distribution_channel' => 'xs2event',
                    'download_link' => '',
                ]],
            ]),
            'https://sandbox.xs2.test/v1/bookingorders/'.self::SANDBOX_BOOKINGORDER_ID => Http::response([
                'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                'logistic_status' => 'processing',
                'items' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'orderitem_id' => 'sandbox-orderitem-1_bkd',
                    'distribution_channel' => 'xs2event',
                    'download_link' => '',
                ]],
            ]),
        ]);

        $order = $this->createEticketReadyOrder([
            'xs2_booking_response' => null,
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/sandbox-test/orders/{$order->id}/eticket")
            ->assertStatus(422)
            ->assertJsonPath('message', 'E-ticket is not ready yet (logistic_status=processing). Refresh from XS2 after the booking order is completed.');

        $order->refresh();
        $this->assertNull($order->xs2_eticket_request);
    }

    /** @param array<string, mixed> $overrides */
    private function createGuestDataReadyOrder(array $overrides = []): Xs2SandboxTestOrder
    {
        return Xs2SandboxTestOrder::query()->create(array_merge([
            'seatsbroker_order_id' => 'SB-SANDBOX-GUEST01',
            'environment' => Xs2SandboxTestOrder::ENVIRONMENT,
            'is_sandbox' => true,
            'status' => Xs2SandboxTestOrder::STATUS_XS2_ORDER_CREATED,
            'customer_name' => 'XS2 Sandbox Test Customer',
            'customer_email' => 'xs2-sandbox@example.com',
            'quantity' => 1,
            'xs2_event_id' => 'sandbox-event-1',
            'xs2_event_payload' => ['external_event_id' => 'sandbox-event-1', 'event_name' => 'Sandbox Derby'],
            'xs2_ticket_id' => 'sandbox-ticket-1_tck',
            'xs2_ticket_payload' => ['ticket_id' => 'sandbox-ticket-1_tck', 'net_rate' => 12000],
            'xs2_reservation_id' => 'sandbox-reservation-1_rsv',
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            'xs2_booking_code' => 'SBX123',
            'xs2_booking_response' => ['booking_id' => self::SANDBOX_BOOKING_ID, 'booking_code' => 'SBX123'],
            'sb_order_created_at' => now(),
            'xs2_order_created_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createEticketReadyOrder(array $overrides = []): Xs2SandboxTestOrder
    {
        return Xs2SandboxTestOrder::query()->create(array_merge([
            'seatsbroker_order_id' => 'SB-SANDBOX-ETIX01',
            'environment' => Xs2SandboxTestOrder::ENVIRONMENT,
            'is_sandbox' => true,
            'status' => Xs2SandboxTestOrder::STATUS_XS2_ORDER_CREATED,
            'customer_name' => 'XS2 Sandbox Test Customer',
            'customer_email' => 'xs2-sandbox@example.com',
            'quantity' => 1,
            'xs2_event_id' => 'sandbox-event-1',
            'xs2_event_payload' => ['external_event_id' => 'sandbox-event-1', 'event_name' => 'Sandbox Derby'],
            'xs2_ticket_id' => 'sandbox-ticket-1_tck',
            'xs2_ticket_payload' => ['ticket_id' => 'sandbox-ticket-1_tck', 'net_rate' => 12000],
            'xs2_reservation_id' => 'sandbox-reservation-1_rsv',
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            'xs2_booking_code' => 'SBX123',
            'xs2_booking_response' => [
                'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'logistic_status' => 'completed',
                'items' => [[
                    'ticket_id' => 'sandbox-ticket-1_tck',
                    'orderitem_id' => 'sandbox-orderitem-1_bkd',
                    'distribution_channel' => 'xs2event',
                    'download_link' => 'ticket-abc.pdf',
                ]],
            ],
            'sb_order_created_at' => now(),
            'xs2_order_created_at' => now(),
        ], $overrides));
    }

    public function test_sandbox_listing_error_includes_event_and_ticket_counts(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/tickets?*' => Http::sequence()
                ->push([
                    'tickets' => [],
                    'pagination' => ['total_size' => 0],
                ])
                ->push([
                    'tickets' => [],
                    'pagination' => ['total_size' => 3],
                ]),
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/sandbox-test/listing?event_id=sandbox-event-empty')
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'No available sandbox tickets were found for event sandbox-event-empty. XS2 returned 0 ticket(s) with ticket_status=available and stock>0 (3 total ticket(s) for this event). Set XS2_SANDBOX_TEST_EVENT_ID to an event with inventory, or fetch a sandbox event that includes available tickets.',
            );
    }

    public function test_sandbox_event_endpoint_skips_events_without_available_tickets(): void
    {
        config()->set('xs2.sandbox.max_event_attempts', 5);

        Http::fake([
            'https://sandbox.xs2.test/v1/tickets*event_id=sandbox-event-1*' => Http::response([
                'tickets' => [],
                'pagination' => ['total_size' => 0],
            ]),
            'https://sandbox.xs2.test/v1/tickets*event_id=sandbox-event-2*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'sandbox-ticket-2_tck',
                    'event_id' => 'sandbox-event-2',
                    'ticket_name' => 'Category B',
                    'ticket_status' => 'available',
                    'stock' => 3,
                    'net_rate' => 9000,
                    'sales_price' => 9000,
                    'currency_code' => 'EUR',
                ]],
                'pagination' => ['total_size' => 1],
            ]),
            'https://sandbox.xs2.test/v1/tickets?page_size=1&page=1*' => Http::response([
                'tickets' => [],
                'pagination' => ['total_size' => 0],
            ]),
            'https://sandbox.xs2.test/v1/events?page_size=5&page=1' => Http::response([
                'events' => [
                    [
                        'event_id' => 'sandbox-event-1',
                        'event_name' => 'Empty Event',
                    ],
                    [
                        'event_id' => 'sandbox-event-2',
                        'event_name' => 'Stocked Event',
                    ],
                ],
                'pagination' => ['page' => 1, 'total_pages' => 1],
            ]),
            'https://sandbox.xs2.test/v1/events/sandbox-event-2' => Http::response([
                'event_id' => 'sandbox-event-2',
                'event_name' => 'Stocked Event',
            ]),
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/sandbox-test/event')
            ->assertOk()
            ->assertJsonPath('data.event.external_event_id', 'sandbox-event-2')
            ->assertJsonPath('data.listing.ticket_id', 'sandbox-ticket-2_tck')
            ->assertJsonPath('meta.events_tried', 2)
            ->assertJsonCount(1, 'meta.skipped_events')
            ->assertJsonPath('meta.skipped_events.0.external_event_id', 'sandbox-event-1')
            ->assertJsonPath('meta.skipped_events.0.reason', 'no_available_tickets');
    }

    public function test_sandbox_event_endpoint_prefers_event_name_search_before_catalog_scan(): void
    {
        config()->set('xs2.sandbox.max_event_attempts', 5);

        Http::fake([
            'https://sandbox.xs2.test/v1/tickets?page_size=1&page=1*' => Http::response([
                'tickets' => [],
                'pagination' => ['total_size' => 0],
            ]),
            'https://sandbox.xs2.test/v1/tickets*event_id=barcelona-event*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'barcelona-ticket_tck',
                    'event_id' => 'barcelona-event',
                    'ticket_name' => 'Category 1',
                    'ticket_status' => 'available',
                    'stock' => 4,
                    'net_rate' => 15000,
                    'sales_price' => 15000,
                    'currency_code' => 'EUR',
                ]],
                'pagination' => ['total_size' => 1],
            ]),
            'https://sandbox.xs2.test/v1/events*event_name=*Barcelona*' => Http::response([
                'events' => [[
                    'event_id' => 'barcelona-event',
                    'event_name' => 'FC Barcelona vs Test FC',
                    'venue_name' => 'Camp Nou',
                ]],
                'pagination' => ['page' => 1, 'total_pages' => 1],
            ]),
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/sandbox-test/event?event_name=FC+Barcelona')
            ->assertOk()
            ->assertJsonPath('data.source', 'event_name_search')
            ->assertJsonPath('data.event.external_event_id', 'barcelona-event')
            ->assertJsonPath('data.event.event_name', 'FC Barcelona vs Test FC')
            ->assertJsonPath('data.listing.ticket_id', 'barcelona-ticket_tck')
            ->assertJsonPath('meta.events_tried', 1);
    }

    public function test_sandbox_event_endpoint_returns_configuration_error_when_credentials_missing(): void
    {
        config()->set('xs2.sandbox.api_key', null);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2/sandbox-test/event')
            ->assertStatus(503)
            ->assertJsonPath('meta.environment', 'sandbox');
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => 6]);

        return $admin->createToken('xs2-sandbox-test')->plainTextToken;
    }
}
