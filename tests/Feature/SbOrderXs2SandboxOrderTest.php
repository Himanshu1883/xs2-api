<?php

namespace Tests\Feature;

use App\Jobs\CreateXs2SandboxOrderFromSbOrder;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\SbOrder;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Order;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\ListingSalesService;
use App\Services\SellerApi\SellerApiClient;
use App\Services\SellerApi\SellerBookingSyncService;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\IntegrationSettingService;
use App\Services\Xs2\SbOrderXs2SandboxOrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SbOrderXs2SandboxOrderTest extends TestCase
{
    private const SANDBOX_BOOKING_ID = 'sandbox-booking-sb_bkn';

    private const SANDBOX_BOOKINGORDER_ID = 'sandbox-bookingorder-sb_bko';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.sandbox.auto_create_orders_from_sb', true);
        config()->set('xs2.sandbox.bookings_endpoint', '/v1/bookings');
        config()->set('xs2.sandbox.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.sandbox.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}');
        config()->set('xs2.reservations_endpoint', '/v1/reservations');
        config()->set('xs2.sandbox.retry_times', 1);
    }

    public function test_sb_booking_sync_queues_xs2_sandbox_order_for_sandbox_ticket(): void
    {
        Queue::fake();
        $this->seedSandboxTicketMapping('906584');

        $client = Mockery::mock(SellerApiClient::class);
        $client->shouldReceive('resolvedListingBaseUrl')->andReturn('https://seller.test');
        $client->shouldReceive('fetchAllBookings')
            ->once()
            ->andReturn([
                'result' => [[
                    'booking_no' => 'SB-9001',
                    'booking_status' => SbOrder::STATUS_CONFIRMED,
                    'booking_status_text' => 'Confirmed',
                    'ticket_id' => 906584,
                    'listing_id' => '841765',
                    'quantity' => 2,
                    'match_name' => 'FC Barcelona vs Test',
                    'stadium_name' => 'Camp Nou',
                    'match_date' => '2026-10-01',
                    'attendee_details' => [],
                ]],
            ]);

        $listingSales = Mockery::mock(ListingSalesService::class);
        $listingSales->shouldReceive('queueStockReconcileForListingIds')->once()->andReturn(['queued' => 0]);

        $service = app(SellerBookingSyncService::class, [
            'client' => $client,
            'listingSales' => $listingSales,
        ]);
        $service->sync();

        Queue::assertPushed(CreateXs2SandboxOrderFromSbOrder::class, function (CreateXs2SandboxOrderFromSbOrder $job): bool {
            $order = SbOrder::query()->where('booking_no', 'SB-9001')->first();

            return $order !== null && $job->sbOrderId === $order->id;
        });
    }

    public function test_service_creates_xs2_sandbox_order_in_xs2_orders_table(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/reservations' => Http::response([
                'reservation_id' => 'sandbox-reservation-sb_rsv',
            ], 201),
            'https://sandbox.xs2.test/v1/bookings' => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBX9001',
            ], 201),
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
                    'event_name' => 'FC Barcelona vs Test',
                    'booking_status' => 'confirmed',
                ]),
        ]);

        $ticket = $this->seedSandboxTicketMapping('906584');
        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-9001',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 2,
            'match_name' => 'FC Barcelona vs Test',
            'stadium_name' => 'Camp Nou',
            'match_date' => '2026-10-01',
        ]);

        $result = app(SbOrderXs2SandboxOrderService::class)->createFromSbOrder($sbOrder);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['skipped']);
        $this->assertDatabaseHas('xs2_orders', [
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => true,
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'external_ticket_id' => $ticket->external_ticket_id,
        ]);
        $this->assertDatabaseHas('sb_order_xs2_sync_logs', [
            'sb_order_id' => $sbOrder->id,
            'status' => 'success',
        ]);
    }

    public function test_service_creates_xs2_sandbox_order_via_listing_split_with_is_sandbox_zero_ticket(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/reservations' => Http::response([
                'reservation_id' => 'sandbox-reservation-split_rsv',
            ], 201),
            'https://sandbox.xs2.test/v1/bookings' => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBXSPLIT',
            ], 201),
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
                    'event_name' => 'FC Barcelona vs Test',
                    'booking_status' => 'confirmed',
                ]),
        ]);

        $ticket = $this->seedSplitListingMapping('920288', '65e39feec62e49dc8f2e486023c7bd6b_spp');
        $sbOrder = SbOrder::query()->create([
            'booking_no' => '1BX67156',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 920288,
            'listing_id' => '287339',
            'quantity' => 1,
            'match_name' => 'FC Barcelona vs Test',
            'stadium_name' => 'Camp Nou',
            'match_date' => '2026-10-01',
        ]);

        $service = app(SbOrderXs2SandboxOrderService::class);

        $this->assertTrue($service->queueIfEligible($sbOrder));
        $this->assertNull($service->resolveQueueSkipReason($sbOrder));

        $result = $service->createFromSbOrder($sbOrder);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['skipped']);
        $this->assertDatabaseHas('xs2_orders', [
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => true,
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'external_ticket_id' => $ticket->external_ticket_id,
        ]);
    }

    public function test_sb_orders_index_includes_resolved_xs2_listing_ids(): void
    {
        $masterTicket = $this->seedSandboxTicketMapping('906584');
        $splitTicket = $this->seedSplitListingMapping('906999', '65e39feec62e49dc8f2e486023c7bd6b');

        SbOrder::query()->create([
            'booking_no' => 'SB-IDX-MASTER',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 1,
        ]);

        SbOrder::query()->create([
            'booking_no' => 'SB-IDX-SPLIT',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => 906999,
            'listing_id' => '906999',
            'quantity' => 2,
        ]);

        $response = $this->withToken($this->adminToken())
            ->getJson('/api/admin/sb-orders')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('booking_no');

        $this->assertSame($masterTicket->external_ticket_id, $rows['SB-IDX-MASTER']['xs2_listing_id']);
        $this->assertSame($masterTicket->external_ticket_id, $rows['SB-IDX-MASTER']['xs2_external_ticket_id']);
        $this->assertSame(
            '65e39feec62e49dc8f2e486023c7bd6b-S2',
            $rows['SB-IDX-SPLIT']['xs2_listing_id'],
        );
        $this->assertSame($splitTicket->external_ticket_id, $rows['SB-IDX-SPLIT']['xs2_external_ticket_id']);
    }

    public function test_admin_can_manually_create_xs2_order_from_sb_order(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/reservations' => Http::response([
                'reservation_id' => 'sandbox-reservation-manual_rsv',
            ], 201),
            'https://sandbox.xs2.test/v1/bookings' => Http::response([
                'booking_id' => self::SANDBOX_BOOKING_ID,
                'booking_code' => 'SBXMANUAL',
            ], 201),
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
                    'event_name' => 'FC Barcelona vs Test',
                    'booking_status' => 'confirmed',
                ]),
        ]);

        $ticket = $this->seedSandboxTicketMapping('906584');
        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-MANUAL-001',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 2,
            'match_name' => 'FC Barcelona vs Test',
            'stadium_name' => 'Camp Nou',
            'match_date' => '2026-10-01',
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson("/api/admin/sb-orders/{$sbOrder->id}/create-xs2-order")
            ->assertOk()
            ->assertJsonPath('data.xs2_order.xs2_booking_id', self::SANDBOX_BOOKING_ID)
            ->assertJsonPath('data.xs2_order.external_order_id', self::SANDBOX_BOOKINGORDER_ID);

        $this->assertDatabaseHas('xs2_orders', [
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => true,
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'external_ticket_id' => $ticket->external_ticket_id,
        ]);
        $this->assertDatabaseHas('sb_order_xs2_sync_logs', [
            'sb_order_id' => $sbOrder->id,
            'status' => 'success',
        ]);
    }

    public function test_manual_create_xs2_order_rejects_when_booking_already_exists(): void
    {
        $ticket = $this->seedSandboxTicketMapping('906584');
        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-MANUAL-002',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 1,
            'match_name' => 'FC Barcelona vs Test',
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'is_sandbox' => true,
            'sb_order_id' => $sbOrder->id,
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            'external_ticket_id' => $ticket->external_ticket_id,
            'quantity' => 1,
            'order_status' => 'confirmed',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/sb-orders/{$sbOrder->id}/create-xs2-order")
            ->assertStatus(422)
            ->assertJsonPath('message', 'XS2 order already exists for this SB order.');
    }

    public function test_admin_sb_order_xs2_sync_log_endpoint_returns_log(): void
    {
        $token = $this->adminToken();
        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-9010',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => 906584,
            'quantity' => 1,
            'match_name' => 'FC Barcelona vs Test',
        ]);

        \App\Models\SbOrderXs2SyncLog::query()->create([
            'sb_order_id' => $sbOrder->id,
            'status' => 'queued',
            'skip_reason' => null,
            'reservation_request' => ['items' => []],
            'reservation_response' => ['reservation_id' => 'sandbox-reservation-sb_rsv'],
            'reservation_response_status' => 201,
            'reservation_response_headers' => ['content-type' => ['application/json']],
        ]);

        $this->withToken($token)
            ->getJson("/api/admin/sb-orders/{$sbOrder->id}/xs2-sync-log")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.reservation_response_status', 201)
            ->assertJsonPath('data.reservation_response.reservation_id', 'sandbox-reservation-sb_rsv');
    }

    public function test_service_creates_xs2_production_order_in_xs2_orders_table(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_PRODUCTION,
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_BASE_URL,
            'https://api.xs2.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_API_KEY,
            'production-key',
            secret: true,
        );

        config()->set('xs2.bookings_endpoint', '/v1/bookings');
        config()->set('xs2.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}');

        Http::fake([
            'https://api.xs2.test/v1/reservations' => Http::response([
                'reservation_id' => 'production-reservation-sb_rsv',
            ], 201),
            'https://api.xs2.test/v1/bookings' => Http::response([
                'booking_id' => 'production-booking-sb_bkn',
                'booking_code' => 'PRD9001',
            ], 201),
            'https://api.xs2.test/v1/bookingorders*' => Http::sequence()
                ->push([
                    'bookingorders' => [[
                        'booking_id' => 'production-booking-sb_bkn',
                        'bookingorder_id' => 'production-bookingorder-sb_bko',
                    ]],
                ])
                ->push([
                    'bookingorder_id' => 'production-bookingorder-sb_bko',
                    'booking_id' => 'production-booking-sb_bkn',
                    'event_name' => 'Real Madrid vs Test',
                    'booking_status' => 'confirmed',
                ]),
        ]);

        $ticket = $this->seedProductionTicketMapping('906584');
        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-PROD-9001',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 2,
            'match_name' => 'Real Madrid vs Test',
            'stadium_name' => 'Bernabeu',
            'match_date' => '2026-10-01',
        ]);

        $result = app(SbOrderXs2SandboxOrderService::class)->createFromSbOrder($sbOrder);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['skipped']);
        $this->assertDatabaseHas('xs2_orders', [
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => false,
            'external_order_id' => 'production-bookingorder-sb_bko',
            'xs2_booking_id' => 'production-booking-sb_bkn',
            'external_ticket_id' => $ticket->external_ticket_id,
        ]);
        $this->assertDatabaseHas('sb_order_xs2_sync_logs', [
            'sb_order_id' => $sbOrder->id,
            'status' => 'success',
        ]);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/v1/bookings')) {
                return false;
            }

            return ! array_key_exists('is_test_booking', $request->data());
        });
    }

    public function test_service_skips_sandbox_ticket_when_create_order_environment_is_production(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_PRODUCTION,
        );

        $this->seedSandboxTicketMapping('906584');
        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-9003',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 1,
            'match_name' => 'FC Barcelona vs Test',
        ]);

        $result = app(SbOrderXs2SandboxOrderService::class)->createFromSbOrder($sbOrder);

        $this->assertTrue($result['skipped']);
        $this->assertStringContainsString('is marked is_sandbox', strtolower((string) $result['reason']));
        $this->assertDatabaseMissing('xs2_orders', ['sb_order_id' => $sbOrder->id]);
    }

    public function test_create_manual_links_existing_unlinked_production_xs2_order(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_PRODUCTION,
        );

        $sbOrder = SbOrder::query()->create([
            'booking_no' => '1BX67678',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'quantity' => 1,
            'match_name' => 'AS Roma vs Atalanta',
            'match_date' => '2026-09-05',
        ]);

        $xs2Order = Xs2Order::query()->create([
            'external_order_id' => 'production-bookingorder-roma',
            'is_sandbox' => false,
            'sb_order_id' => null,
            'xs2_booking_id' => 'production-booking-roma',
            'xs2_bookingorder_id' => 'production-bookingorder-roma',
            'event_name' => 'AS Roma vs Atalanta',
            'external_ticket_id' => 'production-ticket-roma_tck',
            'quantity' => 1,
            'order_status' => 'confirmed',
            'raw_payload' => [
                'booking_reference' => '1BX67678',
                'booking_id' => 'production-booking-roma',
            ],
            'synced_at' => now(),
        ]);

        $result = app(SbOrderXs2SandboxOrderService::class)->createFromSbOrder($sbOrder);

        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['updated']);
        $this->assertFalse($result['created']);
        $this->assertSame($xs2Order->id, $result['order']?->id);
        $this->assertDatabaseHas('xs2_orders', [
            'id' => $xs2Order->id,
            'sb_order_id' => $sbOrder->id,
        ]);
        $this->assertDatabaseHas('sb_order_xs2_sync_logs', [
            'sb_order_id' => $sbOrder->id,
            'status' => 'success',
        ]);

        Http::fake();
        Http::assertNothingSent();
    }

    public function test_resolve_mapped_ticket_falls_back_to_past_event_by_name_and_date(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_PRODUCTION,
        );

        $event = Xs2Event::query()->create([
            'external_event_id' => 'production-event-roma',
            'event_name' => 'AS Roma vs Atalanta',
            'sport_type' => 'soccer',
            'event_status' => 'closed',
            'date_start_local' => '2026-09-05 20:45:00',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'external_ticket_id' => 'production-ticket-roma_tck',
            'external_event_id' => $event->external_event_id,
            'xs2_event_id' => $event->id,
            'is_sandbox' => false,
            'ticket_status' => 'available',
            'stock' => 0,
            'net_rate' => 18000,
            'currency_code' => 'EUR',
            'category_name' => 'Distinti Laterale',
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => '1BX67678',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => 999999,
            'listing_id' => '888888',
            'quantity' => 1,
            'match_name' => 'AS Roma vs Atalanta',
            'match_date' => '2026-09-05',
            'seat_category' => 'Distinti Laterale',
        ]);

        $resolved = app(SbOrderXs2SandboxOrderService::class)->resolveMappedTicket($sbOrder);

        $this->assertNotNull($resolved);
        $this->assertSame($ticket->id, $resolved->id);
        $this->assertFalse($event->isSellable());
    }

    public function test_admin_can_manually_create_xs2_order_for_past_event_via_event_mapping(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_PRODUCTION,
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_BASE_URL,
            'https://api.xs2.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_API_KEY,
            'production-key',
            secret: true,
        );

        config()->set('xs2.bookings_endpoint', '/v1/bookings');
        config()->set('xs2.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.bookingorder_detail_endpoint', '/v1/bookingorders/{bookingorder_id}');

        Http::fake([
            'https://api.xs2.test/v1/reservations' => Http::response([
                'reservation_id' => 'production-reservation-past_rsv',
            ], 201),
            'https://api.xs2.test/v1/bookings' => Http::response([
                'booking_id' => 'production-booking-past_bkn',
                'booking_code' => 'PRDPAST',
            ], 201),
            'https://api.xs2.test/v1/bookingorders*' => Http::sequence()
                ->push([
                    'bookingorders' => [[
                        'booking_id' => 'production-booking-past_bkn',
                        'bookingorder_id' => 'production-bookingorder-past_bko',
                    ]],
                ])
                ->push([
                    'bookingorder_id' => 'production-bookingorder-past_bko',
                    'booking_id' => 'production-booking-past_bkn',
                    'event_name' => 'AS Roma vs Atalanta',
                    'booking_status' => 'confirmed',
                ]),
        ]);

        $event = Xs2Event::query()->create([
            'external_event_id' => 'production-event-roma-manual',
            'event_name' => 'AS Roma vs Atalanta',
            'sport_type' => 'soccer',
            'event_status' => 'closed',
            'date_start_local' => '2026-09-05 20:45:00',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'external_ticket_id' => 'production-ticket-roma-manual_tck',
            'external_event_id' => $event->external_event_id,
            'xs2_event_id' => $event->id,
            'is_sandbox' => false,
            'ticket_status' => 'available',
            'stock' => 0,
            'net_rate' => 18000,
            'currency_code' => 'EUR',
            'category_name' => 'Distinti Laterale',
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => '1BX67679',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 999999,
            'listing_id' => '888888',
            'quantity' => 1,
            'match_name' => 'AS Roma vs Atalanta',
            'match_date' => '2026-09-05',
            'seat_category' => 'Distinti Laterale',
        ]);

        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson("/api/admin/sb-orders/{$sbOrder->id}/create-xs2-order")
            ->assertOk()
            ->assertJsonPath('data.xs2_order.xs2_booking_id', 'production-booking-past_bkn')
            ->assertJsonPath('data.xs2_order.external_order_id', 'production-bookingorder-past_bko');

        $this->assertDatabaseHas('xs2_orders', [
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => false,
            'external_ticket_id' => $ticket->external_ticket_id,
        ]);
    }

    public function test_admin_xs2_orders_index_includes_sandbox_order_linked_to_sb_order(): void
    {
        $token = $this->adminToken();
        $ticket = $this->seedSandboxTicketMapping('906584');
        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-9002',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'ticket_id' => 906584,
            'quantity' => 1,
            'match_name' => 'FC Barcelona vs Test',
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'is_sandbox' => true,
            'sb_order_id' => $sbOrder->id,
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            'event_name' => 'FC Barcelona vs Test',
            'external_ticket_id' => $ticket->external_ticket_id,
            'quantity' => 1,
            'order_status' => 'confirmed',
            'synced_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson('/api/admin/xs2-orders?search=SB-9002')
            ->assertOk()
            ->assertJsonPath('data.0.is_sandbox', true)
            ->assertJsonPath('data.0.sb_booking_no', 'SB-9002')
            ->assertJsonPath('data.0.xs2_booking_id', self::SANDBOX_BOOKING_ID);
    }

    private function seedSandboxTicketMapping(string $sellerListingId): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'sandbox-event-barcelona',
            'event_name' => 'FC Barcelona vs Test',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'external_ticket_id' => 'sandbox-ticket-barcelona_tck',
            'external_event_id' => $event->external_event_id,
            'xs2_event_id' => $event->id,
            'is_sandbox' => true,
            'ticket_status' => 'available',
            'stock' => 10,
            'net_rate' => 12000,
            'currency_code' => 'EUR',
            'category_name' => 'Category A',
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'seller_listing_id' => $sellerListingId,
            'seller_reference' => 'XS2-ref',
            'status' => 'active',
        ]);

        return $ticket;
    }

    private function seedSplitListingMapping(string $seatsbrokerListingId, string $externalTicketId): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'sandbox-event-split',
            'event_name' => 'FC Barcelona vs Test',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'external_ticket_id' => $externalTicketId,
            'external_event_id' => $event->external_event_id,
            'xs2_event_id' => $event->id,
            'is_sandbox' => false,
            'ticket_status' => 'available',
            'stock' => 10,
            'net_rate' => 12000,
            'currency_code' => 'EUR',
            'category_name' => 'Category A',
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 2,
            'seller_reference' => 'XS2-'.$externalTicketId.'-S2',
            'quantity' => 2,
            'price' => 120.00,
            'seatsbroker_listing_id' => $seatsbrokerListingId,
            'status' => 'active',
            'sync_status' => 'synced',
        ]);

        return $ticket;
    }

    private function seedProductionTicketMapping(string $sellerListingId): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'production-event-madrid',
            'event_name' => 'Real Madrid vs Test',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $ticket = Xs2Ticket::query()->create([
            'external_ticket_id' => 'production-ticket-madrid_tck',
            'external_event_id' => $event->external_event_id,
            'xs2_event_id' => $event->id,
            'is_sandbox' => false,
            'ticket_status' => 'available',
            'stock' => 10,
            'net_rate' => 15000,
            'currency_code' => 'EUR',
            'category_name' => 'Category A',
            'sync_status' => 'pending',
            'raw_payload' => [],
        ]);

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'seller_listing_id' => $sellerListingId,
            'seller_reference' => 'XS2-ref',
            'status' => 'active',
        ]);

        return $ticket;
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);

        return $admin->createToken('sb-xs2-sandbox-test')->plainTextToken;
    }
}
