<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminXs2ResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
    }

    public function test_reset_all_requires_confirmation_flag(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/xs2/reset-all', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm']);
    }

    public function test_reset_all_rejects_false_confirmation(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/xs2/reset-all', ['confirm' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm']);
    }

    public function test_reset_all_wipes_catalog_and_preserves_orders_by_default(): void
    {
        $token = $this->adminToken();

        $event = Xs2Event::query()->create([
            'external_event_id' => 'api-reset-event',
            'event_name' => 'API reset fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 91001,
            'status' => 'mapped',
        ]);

        Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'api-reset-ticket',
            'external_event_id' => $event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);

        $this->withToken($token)
            ->postJson('/api/admin/xs2/reset-all', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.preserve_orders', true)
            ->assertJsonPath('data.catalog.events_deleted', 1)
            ->assertJsonPath('data.catalog.tickets_deleted', 1)
            ->assertJsonPath('data.catalog.mappings_deleted', 1)
            ->assertJsonPath('data.remote.tickets_processed', 0);

        $this->assertSame(0, Xs2Event::query()->count());
        $this->assertSame(0, EventMapping::query()->count());
        $this->assertSame(0, Xs2Ticket::query()->count());
    }

    public function test_reset_all_deletes_remote_listings_before_wiping_local_data(): void
    {
        config()->set('seller-api.listing_base_url', 'https://seller.test');
        config()->set('seller-api.delete_listing_endpoint', '/api/ticket/delete');
        config()->set('seller-api.seller_id', 77);
        config()->set('seller-api.listing_api_key', 'seller-test-key');
        config()->set('seller-api.retry_times', 0);

        Http::fake([
            'https://seller.test/*' => Http::response([
                'status' => 1,
                'message' => 'Ticket deleted successfully',
            ]),
        ]);

        $token = $this->adminToken();

        $event = Xs2Event::query()->create([
            'external_event_id' => 'api-reset-remote-event',
            'event_name' => 'Remote delete fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $mapping = EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 91002,
            'status' => 'mapped',
        ]);

        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'api-reset-remote-ticket',
            'external_event_id' => $event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);

        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => 91002,
            'event_mapping_id' => $mapping->id,
            'seller_listing_id' => '880001',
            'seller_reference' => 'XS2-api-reset-remote-ticket',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);

        $this->withToken($token)
            ->postJson('/api/admin/xs2/reset-all', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.remote.tickets_processed', 1)
            ->assertJsonPath('data.remote.sb_listings_deleted', 1)
            ->assertJsonPath('data.remote.sb_listings_failed', 0)
            ->assertJsonPath('data.catalog.events_deleted', 1);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'seller.test/api/ticket/delete'));
        $this->assertSame(0, Xs2Event::query()->count());
        $this->assertSame(0, Xs2Ticket::query()->count());
    }

    public function test_reset_all_can_wipe_orders_when_preserve_orders_is_false(): void
    {
        $token = $this->adminToken();

        Xs2Event::query()->create([
            'external_event_id' => 'full-wipe-event',
            'event_name' => 'Full wipe fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $this->withToken($token)
            ->postJson('/api/admin/xs2/reset-all', [
                'confirm' => true,
                'preserve_orders' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.preserve_orders', false)
            ->assertJsonPath('data.catalog.events_deleted', 1);

        $this->assertSame(0, Xs2Event::query()->count());
    }

    private function adminToken(): string
    {
        return User::factory()->create(['user_type' => 6])
            ->createToken('xs2-reset-test')
            ->plainTextToken;
    }
}
