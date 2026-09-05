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
    private const SANDBOX_BOOKING_ID = 'sandbox-booking-sync_bkn';

    private const SANDBOX_BOOKINGORDER_ID = 'sandbox-bookingorder-sync_bko';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();

        config()->set('xs2.sandbox.api_url', 'https://sandbox.xs2.test');
        config()->set('xs2.sandbox.api_key', 'sandbox-key');
        config()->set('xs2.sandbox.bookingorders_endpoint', '/v1/bookingorders');
        config()->set('xs2.sandbox.retry_times', 1);
    }

    public function test_admin_can_sync_sandbox_booking_orders_into_xs2_orders(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'booking_code' => 'SBX-SYNC',
                    'booking_email' => 'buyer@example.com',
                    'reservation_id' => 'sandbox-reservation-sync_rsv',
                    'event_id' => 'sandbox-event-sync',
                    'event_name' => 'Sandbox Derby',
                    'venue_name' => 'Test Arena',
                    'logistic_status' => 'completed',
                    'guestdata_status' => 'completed',
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-sync_tck',
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
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.is_sandbox', true)
            ->assertJsonPath('data.endpoint', '/v1/bookingorders');

        $this->assertDatabaseHas('xs2_orders', [
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'is_sandbox' => true,
            'xs2_booking_id' => self::SANDBOX_BOOKING_ID,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
            'event_name' => 'Sandbox Derby',
            'external_ticket_id' => 'sandbox-ticket-sync_tck',
            'quantity' => 2,
            'order_status' => 'completed',
            'buyer_email' => 'buyer@example.com',
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/bookingorders'));
    }

    public function test_sync_links_sandbox_order_to_sb_order_by_booking_reference(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'booking_reference' => 'SB-LINK-001',
                    'booking_email' => 'linked@example.com',
                    'logistic_status' => 'confirmed',
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-sync_tck',
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
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'sb_order_id' => $sbOrder->id,
            'is_sandbox' => true,
        ]);
    }

    public function test_sync_links_sandbox_order_to_sb_order_by_booking_email(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'booking_email' => 'attendee-link@example.com',
                    'logistic_status' => 'confirmed',
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-sync_tck',
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
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'sb_order_id' => $sbOrder->id,
        ]);
    }

    public function test_sync_updates_existing_sandbox_order(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'bookingorders' => [[
                    'bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
                    'booking_id' => self::SANDBOX_BOOKING_ID,
                    'logistic_status' => 'completed',
                    'event_name' => 'Updated Event',
                    'items' => [[
                        'ticket_id' => 'sandbox-ticket-sync_tck',
                        'quantity' => 1,
                    ]],
                ]],
                'pagination' => ['total_pages' => 1],
            ]),
        ]);

        Xs2Order::query()->create([
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'is_sandbox' => true,
            'xs2_bookingorder_id' => self::SANDBOX_BOOKINGORDER_ID,
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
            'external_order_id' => self::SANDBOX_BOOKINGORDER_ID,
            'event_name' => 'Updated Event',
            'order_status' => 'completed',
        ]);
    }

    public function test_index_includes_create_order_environment_defaulting_to_sandbox(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2-orders')
            ->assertOk()
            ->assertJsonPath('create_order_environment', 'sandbox');
    }

    public function test_index_reflects_persisted_create_order_environment(): void
    {
        app(IntegrationSettingService::class)->set(
            ApiEnvironmentService::XS2_ORDERS_ACTIVE_ENVIRONMENT,
            ApiEnvironmentService::ENV_PRODUCTION,
        );

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/xs2-orders')
            ->assertOk()
            ->assertJsonPath('create_order_environment', 'production');
    }

    public function test_sync_returns_configuration_error_when_sandbox_is_not_configured(): void
    {
        config()->set('xs2.sandbox.api_url', '');
        config()->set('xs2.sandbox.api_key', '');

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(422)
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.endpoint', '/v1/bookingorders');
    }

    public function test_sync_maps_upstream_xs2_401_to_502_so_web_client_does_not_log_out(): void
    {
        Http::fake([
            'https://sandbox.xs2.test/v1/bookingorders*' => Http::response([
                'message' => 'Invalid API key.',
            ], 401),
        ]);

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/xs2-orders/sync')
            ->assertStatus(502)
            ->assertJsonPath('data.environment', 'sandbox')
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
