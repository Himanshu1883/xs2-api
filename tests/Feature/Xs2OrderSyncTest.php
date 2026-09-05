<?php

namespace Tests\Feature;

use App\Models\SbOrder;
use App\Models\SbOrderAttendee;
use App\Models\User;
use App\Models\Xs2Order;
use App\Services\Admin\ApiEnvironmentService;
use App\Services\Admin\IntegrationSettingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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
            ->assertOk()
            ->assertJsonPath('data.fetched', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 0)
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
            ->assertOk()
            ->assertJsonPath('data.created', 1);

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
            ->assertOk();

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'sb_order_id' => $sbOrder->id,
        ]);
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
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 1);

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::PRODUCTION_BOOKINGORDER_ID,
            'event_name' => 'Updated Event',
            'order_status' => 'completed',
        ]);
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
            ->assertOk()
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

    public function test_sync_maps_upstream_xs2_401_to_502_so_web_client_does_not_log_out(): void
    {
        Http::fake([
            'https://api.xs2.test/v1/bookingorders*' => Http::response([
                'message' => 'Invalid API key.',
            ], 401),
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(502)
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.is_sandbox', false)
            ->assertJsonPath('data.endpoint', '/v1/bookingorders')
            ->assertJson(fn ($json) => $json
                ->where('message', fn (string $message) => str_contains($message, 'HTTP 401')
                    && str_contains($message, 'Invalid API key.')
                    && str_contains($message, '/v1/bookingorders'))
                ->etc());
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['user_type' => User::ADMIN_USER_TYPE]);

        return $admin->createToken('xs2-order-sync-test')->plainTextToken;
    }
}
