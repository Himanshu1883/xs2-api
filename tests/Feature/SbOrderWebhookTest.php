<?php

namespace Tests\Feature;

use App\Jobs\CreateXs2SandboxOrderFromSbOrder;
use App\Models\IntegrationSetting;
use App\Models\SbOrder;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Admin\IntegrationSettingService;
use App\Services\Webhooks\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SbOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-webhook-bearer-token-12345678901234567890123456789012';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();

        config()->set('app.url', 'https://provider.example.com');

        app(IntegrationSettingService::class)->set(
            IntegrationSettingService::SB_WEBHOOK_BEARER_TOKEN,
            $this->token,
            secret: true,
        );
    }

    public function test_webhook_rejects_missing_bearer_token(): void
    {
        $this->postJson('/api/webhooks/sb/orders', [
            'booking_no' => 'SB-WH-401',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid or missing bearer token.');

        $this->assertDatabaseHas('webhook_logs', [
            'booking_no' => 'SB-WH-401',
            'status' => WebhookLog::STATUS_UNAUTHORIZED,
            'http_status' => 401,
        ]);
    }

    public function test_webhook_rejects_invalid_bearer_token(): void
    {
        $this->withToken('wrong-token')
            ->postJson('/api/webhooks/sb/orders', [
                'booking_no' => 'SB-WH-403',
            ])
            ->assertUnauthorized();

        $this->assertDatabaseHas('webhook_logs', [
            'booking_no' => 'SB-WH-403',
            'status' => WebhookLog::STATUS_UNAUTHORIZED,
        ]);
    }

    public function test_webhook_creates_sb_order_from_payload(): void
    {
        Queue::fake();

        $payload = [
            'booking_no' => 'SB-WH-100',
            'booking_status' => SbOrder::STATUS_CONFIRMED,
            'booking_status_text' => 'Confirmed',
            'ticket_id' => 906584,
            'listing_id' => '841765',
            'quantity' => 2,
            'buyer_first_name' => 'Jane',
            'buyer_last_name' => 'Doe',
            'attendee_details' => [
                [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'email' => 'jane@example.com',
                ],
            ],
        ];

        $this->withToken($this->token)
            ->postJson('/api/webhooks/sb/orders', $payload)
            ->assertOk()
            ->assertJsonPath('data.booking_no', 'SB-WH-100')
            ->assertJsonPath('data.created', true);

        $order = SbOrder::query()->where('booking_no', 'SB-WH-100')->first();
        $this->assertNotNull($order);
        $this->assertSame(SbOrder::STATUS_CONFIRMED, $order->booking_status);
        $this->assertSame('Jane', $order->buyer_first_name);
        $this->assertCount(1, $order->attendees);

        $this->assertDatabaseHas('webhook_logs', [
            'booking_no' => 'SB-WH-100',
            'status' => WebhookLog::STATUS_PROCESSED,
            'http_status' => 200,
            'sb_order_id' => $order->id,
        ]);
    }

    public function test_webhook_updates_existing_sb_order(): void
    {
        Queue::fake();

        SbOrder::query()->create([
            'booking_no' => 'SB-WH-200',
            'booking_status' => SbOrder::STATUS_PENDING,
            'booking_status_text' => 'Pending Confirmation',
            'quantity' => 1,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/webhooks/sb/orders', [
                'booking_no' => 'SB-WH-200',
                'booking_status' => SbOrder::STATUS_COMPLETED,
                'booking_status_text' => 'Completed',
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', true)
            ->assertJsonPath('data.created', false);

        $order = SbOrder::query()->where('booking_no', 'SB-WH-200')->firstOrFail();
        $this->assertSame(SbOrder::STATUS_COMPLETED, $order->booking_status);
        $this->assertSame('Completed', $order->booking_status_text);
    }

    public function test_webhook_requires_booking_no(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/webhooks/sb/orders', [
                'booking_status' => SbOrder::STATUS_CONFIRMED,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The booking_no field is required.');

        $this->assertDatabaseHas('webhook_logs', [
            'status' => WebhookLog::STATUS_FAILED,
            'http_status' => 422,
        ]);
    }

    public function test_admin_can_view_webhook_settings_and_auto_generate_token(): void
    {
        IntegrationSetting::query()->where('key', IntegrationSettingService::SB_WEBHOOK_BEARER_TOKEN)->delete();

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('webhook-settings')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/webhooks/settings')
            ->assertOk()
            ->assertJsonPath('data.webhook_url', 'https://provider.example.com/api/webhooks/sb/orders')
            ->assertJsonPath('data.bearer_token_configured', true);

        $plainToken = $response->json('data.bearer_token_plain');
        $this->assertIsString($plainToken);
        $this->assertNotEmpty($plainToken);
        $this->assertTrue(app(WebhookSettingService::class)->isConfigured());
    }

    public function test_admin_can_regenerate_webhook_bearer_token(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $adminToken = $user->createToken('webhook-regenerate')->plainTextToken;

        $response = $this->withToken($adminToken)
            ->patchJson('/api/admin/webhooks/settings', [
                'regenerate_token' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.bearer_token_configured', true);

        $plainToken = $response->json('data.bearer_token_plain');
        $this->assertIsString($plainToken);
        $this->assertNotSame($this->token, $plainToken);

        $this->withToken($plainToken)
            ->postJson('/api/webhooks/sb/orders', [
                'booking_no' => 'SB-WH-300',
                'booking_status' => SbOrder::STATUS_CONFIRMED,
            ])
            ->assertOk();
    }

    public function test_admin_can_list_webhook_logs(): void
    {
        WebhookLog::query()->create([
            'booking_no' => 'SB-WH-LOG',
            'http_status' => 200,
            'status' => WebhookLog::STATUS_PROCESSED,
            'payload' => ['booking_no' => 'SB-WH-LOG'],
            'response' => ['message' => 'ok'],
        ]);

        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('webhook-logs')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/webhooks/logs?booking_no=SB-WH-LOG')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.booking_no', 'SB-WH-LOG')
            ->assertJsonPath('data.0.status', WebhookLog::STATUS_PROCESSED);
    }
}
