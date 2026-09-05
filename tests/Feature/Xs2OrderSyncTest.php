<?php

namespace Tests\Feature;

use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\User;
use App\Models\Xs2Order;
use App\Models\Xs2OrderAttendee;
use App\Jobs\SyncXs2OrdersJob;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\IntegrationSettingService;
use App\Services\Xs2\Xs2OrderSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Xs2OrderSyncTest extends TestCase
{
    private const PRODUCTION_BOOKING_ID = 'production-booking-sync_bkn';

    private const PRODUCTION_BOOKINGORDER_ID = 'production-bookingorder-sync_bko';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.base_url', 'https://api.xs2.test');
        config()->set('xs2.api_key', 'production-key');
        config()->set('xs2.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.retry_times', 1);

        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_BASE_URL,
            'https://api.xs2.test',
        );
        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::XS2_API_KEY,
            'production-key',
            secret: true,
        );

        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.sandbox.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.sandbox.retry_times', 1);
    }

    public function test_admin_can_sync_production_booking_orders_into_xs2_orders(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
                    'booking_id' => self::PRODUCTION_BOOKING_ID,
                    'booking_code' => 'PRD-SYNC',
                    'booking_email' => 'buyer@example.com',
                    'reservation_id' => 'production-reservation-sync_rsv',
                    'event_id' => 'production-event-sync',
                    'event_name' => 'Production Derby',
                    'venue_name' => 'Main Arena',
                    'logistic_status' => 'completed',
                    'guestdata_status' => 'completed',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'quantity' => 2,
                        'currency_code' => 'EUR',
                        'sales_price' => 24000,
                    ]],
                    'created' => '2026-08-11T12:00:00+00:00',
                ]],
                'pagination' => [
                    'total_size' => 1,
                    'page_size' => 100,
                    'page_number' => 1,
                    'next_page' => '',
                    'previous_page' => '',
                    'total_pages' => 1,
                ],
            ]),
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.is_sandbox', false)
            ->assertJsonPath('data.endpoint', '/v1/bookingorders');

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'is_sandbox' => false,
            'xs2_booking_id' => self::PRODUCTION_BOOKING_ID,
            'xs2_bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'event_name' => 'Production Derby',
            'external_ticket_id' => 'production-ticket-sync_tck',
            'quantity' => 2,
            'order_status' => 'completed',
            'buyer_email' => 'buyer@example.com',
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'https://api.xs2.test/v1/bookingorders'));
    }

    public function test_sync_links_production_order_to_sb_order_by_booking_reference(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
                    'booking_id' => self::PRODUCTION_BOOKING_ID,
                    'booking_reference' => 'SB-LINK-001',
                    'booking_email' => 'linked@example.com',
                    'logistic_status' => 'confirmed',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-LINK-001',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'quantity' => 1,
            'match_name' => 'Linked Match',
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => false,
        ]);
    }

    public function test_sync_links_production_order_to_sb_order_by_case_insensitive_booking_reference(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
                    'booking_id' => self::PRODUCTION_BOOKING_ID,
                    'booking_reference' => '1bx67678',
                    'logistic_status' => 'confirmed',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => '1BX67678',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'quantity' => 1,
            'match_name' => 'AS Roma vs Atalanta',
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => false,
        ]);
    }

    public function test_sync_links_production_order_to_sb_order_by_booking_email(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
                    'booking_id' => self::PRODUCTION_BOOKING_ID,
                    'booking_email' => 'attendee-link@example.com',
                    'logistic_status' => 'confirmed',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-EMAIL-LINK',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'quantity' => 1,
            'match_name' => 'Email Link Match',
        ]);

        SbOrderAttendee::query()->create([
            'sb_order_id' => $sbOrder->id,
            'position' => 0,
            'email' => 'attendee-link@example.com',
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(202);

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'sb_order_id' => $sbOrder->id,
        ]);
    }

    public function test_sync_copies_sb_attendees_when_linking_production_order_without_api_guests(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
                    'booking_id' => self::PRODUCTION_BOOKING_ID,
                    'booking_reference' => 'SB-ATTENDEE-LINK',
                    'booking_email' => 'guest@example.com',
                    'logistic_status' => 'confirmed',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-ATTENDEE-LINK',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'quantity' => 1,
            'match_name' => 'Attendee Link Match',
        ]);

        SbOrderAttendee::query()->create([
            'sb_order_id' => $sbOrder->id,
            'position' => 0,
            'first_name' => 'Sebastian',
            'last_name' => 'Wickremasinghe',
            'dob' => '1999-09-09',
            'nationality' => 'Chile',
        ]);

        app(Xs2OrderSyncService::class)->sync();

        $xs2Order = Xs2Order::query()
            ->where('external_order_id', self::PRODUCTION_BOOKINGORDER_ID)
            ->firstOrFail();

        $this->assertSame($sbOrder->id, $xs2Order->sb_order_id);
        $this->assertDatabaseHas('xs2_order_attendees', [
            'xs2_order_id' => $xs2Order->id,
            'first_name' => 'Sebastian',
            'last_name' => 'Wickremasinghe',
            'dob' => '1999-09-09',
            'nationality' => 'Chile',
        ]);
        $this->assertNotNull($xs2Order->attendees_copied_from_sb_at);
    }

    public function test_push_guest_data_auto_copies_sb_attendees_when_xs2_order_has_none(): void
    {
        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.bookingorder_guestdata_endpoint', '/v1/bookingorders/{bookingorder_id}/guestdata');
        config()->set('xs2.ticket_guestdata_endpoint', '/v1/tickets/{ticket_id}/guestdata');

        Http::fake([
            'https://api.xs2.test/v1/bookingorders/'.self::PRODUCTION_BOOKINGORDER_ID.'/guestdata*' => Http::sequence()
                ->push([
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'guests' => [[
                            'first_name' => ['condition' => 'required'],
                            'last_name' => ['condition' => 'required'],
                            'date_of_birth' => ['condition' => 'required'],
                            'country_of_residence' => ['condition' => 'required'],
                        ]],
                    ]],
                ])
                ->push([
                    'guestdata_status' => 'completed',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'guests' => [[
                            'first_name' => 'Sebastian',
                            'last_name' => 'Wickremasinghe',
                            'date_of_birth' => '1999-09-09',
                            'country_of_residence' => 'Chile',
                        ]],
                    ]],
                ]),
        ]);

        $sbOrder = SbOrder::query()->create([
            'booking_no' => 'SB-PUSH-AUTO',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'quantity' => 1,
            'match_name' => 'Push Auto Copy Match',
            'ticket_id' => 906584,
            'listing_id' => '841765',
        ]);

        SbOrderAttendee::query()->create([
            'sb_order_id' => $sbOrder->id,
            'position' => 0,
            'first_name' => 'Sebastian',
            'last_name' => 'Wickremasinghe',
            'dob' => '1999-09-09',
            'nationality' => 'Chile',
        ]);

        $xs2Order = Xs2Order::query()->create([
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'xs2_bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'xs2_booking_id' => self::PRODUCTION_BOOKING_ID,
            'is_sandbox' => false,
            'sb_order_id' => $sbOrder->id,
            'external_ticket_id' => 'production-ticket-sync_tck',
            'quantity' => 1,
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2-orders/{$xs2Order->id}/push-guest-data")
            ->assertOk()
            ->assertJsonPath('data.attendees_count', 1);

        $this->assertDatabaseHas('xs2_order_attendees', [
            'xs2_order_id' => $xs2Order->id,
            'first_name' => 'Sebastian',
            'last_name' => 'Wickremasinghe',
        ]);
        $this->assertNotNull($xs2Order->fresh()->guest_data_synced_at);
    }

    public function test_sync_updates_existing_production_order(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
                    'booking_id' => self::PRODUCTION_BOOKING_ID,
                    'logistic_status' => 'completed',
                    'event_name' => 'Updated Event',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'is_sandbox' => false,
            'xs2_bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'event_name' => 'Old Event',
            'order_status' => 'pending',
            'synced_at' => now()->subDay(),
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'event_name' => 'Updated Event',
            'order_status' => 'completed',
        ]);
    }

    public function test_admin_sync_queues_background_job_without_blocking_http(): void
    {
        Queue::fake();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.environment', 'production');

        Queue::assertPushed(SyncXs2OrdersJob::class);
    }

    public function test_index_includes_create_order_environment_defaulting_to_production(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2-orders')
            ->assertOk()
            ->assertJsonPath('create_order_environment', 'production');
    }

    public function test_index_reflects_persisted_create_order_environment(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_SANDBOX,
        );

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2-orders')
            ->assertOk()
            ->assertJsonPath('create_order_environment', 'sandbox');
    }

    public function test_sync_uses_sandbox_when_create_order_environment_is_sandbox(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_SANDBOX,
        );

        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => 'sandbox-bookingorder-sync_bko',
                    'booking_id' => 'sandbox-booking-sync_bkn',
                    'logistic_status' => 'confirmed',
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.is_sandbox', true);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'https://sandbox.xs2.test/v1/bookingorders'));
    }

    public function test_sync_returns_configuration_error_when_production_is_not_configured(): void
    {
        config()->set('xs2.base_url', '');
        config()->set('xs2.api_key', '');
        config()->set('services.xs2.base_url', '');
        config()->set('services.xs2.api_key', '');
        app(IntegrationSettingService::class)->set(IntegrationSettingService::XS2_BASE_URL, null);
        app(IntegrationSettingService::class)->set(IntegrationSettingService::XS2_API_KEY, null);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(422)
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.is_sandbox', false)
            ->assertJsonPath('data.endpoint', '/v1/bookingorders');
    }

    public function test_sync_preserves_existing_attendees_when_bookingorders_payload_has_no_guests(): void
    {
        $order = Xs2Order::query()->create([
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'xs2_bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'xs2_booking_id' => self::PRODUCTION_BOOKING_ID,
            'is_sandbox' => false,
            'order_status' => 'completed',
            'quantity' => 1,
        ]);

        Xs2OrderAttendee::query()->create([
            'xs2_order_id' => $order->id,
            'position' => 0,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::PRODUCTION_BOOKINGORDER_ID,
                    'booking_id' => self::PRODUCTION_BOOKING_ID,
                    'logistic_status' => 'completed',
                    'items' => [[
                        'ticket_id' => 'production-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        app(Xs2OrderSyncService::class)->sync();

        $this->assertDatabaseHas('xs2_order_attendees', [
            'xs2_order_id' => $order->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);
    }

    public function test_sync_job_surfaces_upstream_xs2_401_with_actionable_message(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'message' => 'Invalid API key.',
            ], 401),
        ]);

        $this->expectException(\App\Exceptions\Integrations\Xs2RequestException::class);
        $this->expectExceptionMessage('HTTP 401');
        $this->expectExceptionMessage('Invalid API key.');
        $this->expectExceptionMessage('/v1/bookingorders');

        app(Xs2OrderSyncService::class)->sync();
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);

        return $admin->createToken('xs2-order-sync-test')->plainTextToken;
    }
}
