<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Services\Xs2\Xs2EventInventorySyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class Xs2EventInventoryFetchDebugTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('services.xs2.base_url', 'https://xs2.test');
        config()->set('services.xs2.api_key', 'super-secret-xs2-key');
        config()->set('services.xs2.api_key_header', 'apiKey');
        config()->set('services.xs2.tickets_endpoint', '/v1/tickets');
        config()->set('services.xs2.rate_limit_pacing', false);
        config()->set('services.xs2.retry_times', 1);
    }

    public function test_admin_can_fetch_event_inventory_and_view_last_api_debug(): void
    {
        $mapping = $this->mapping();
        $this->fakeInventorySync($mapping);

        Http::fake([
            'https://xs2.test/v1/tickets*' => Http::response([
                'tickets' => [[
                    'ticket_id' => 'ticket-1',
                    'event_id' => 'xs2-event-1',
                    'stock' => 4,
                    'ticket_status' => 'available',
                ]],
                'pagination' => [],
            ]),
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/events/{$mapping->id}/fetch-inventory")
            ->assertOk()
            ->assertJsonPath('data.mapping_id', $mapping->id)
            ->assertJsonPath('data.tickets_fetched', 1)
            ->assertJsonPath('data.tickets_saved', 1)
            ->assertJsonPath('data.sync_queued', false)
            ->assertJsonPath('data.sync_completed', true)
            ->assertJsonPath('data.xs2_api_debug.scope', 'event')
            ->assertJsonPath('data.xs2_api_debug.interactions.0.operation', 'get_tickets')
            ->assertJsonMissing(['super-secret-xs2-key']);

        $debug = $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/events/{$mapping->id}/last-api-debug")
            ->assertOk()
            ->assertJsonPath('data.scope', 'event')
            ->assertJsonPath('data.debug.mapping_id', $mapping->id)
            ->json('data.debug');

        $this->assertNotEmpty($debug['interactions']);
        $this->assertStringContainsString('https://xs2.test/v1/tickets', (string) $debug['interactions'][0]['url']);
        $this->assertArrayHasKey('request_headers', $debug['interactions'][0]);
        $this->assertArrayHasKey('request_body', $debug['interactions'][0]);
        $this->assertArrayHasKey('response_body', $debug['interactions'][0]);
        $this->assertStringNotContainsString('super-secret-xs2-key', json_encode($debug));
    }

    public function test_last_api_debug_falls_back_to_global_when_event_has_none(): void
    {
        $first = $this->mapping('xs2-event-a');
        $second = $this->mapping('xs2-event-b');
        $this->fakeInventorySync($first);

        Http::fake([
            'https://xs2.test/v1/tickets*' => Http::response([
                'tickets' => [],
                'pagination' => [],
            ]),
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/xs2/events/{$first->id}/fetch-inventory")
            ->assertOk();

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/xs2/events/{$second->id}/last-api-debug")
            ->assertOk()
            ->assertJsonPath('data.scope', 'global')
            ->assertJsonPath('data.debug.mapping_id', $first->id);
    }

    private function fakeInventorySync(EventMapping $mapping): void
    {
        $sync = Mockery::mock(Xs2EventInventorySyncService::class);
        $sync->shouldReceive('sync')
            ->once()
            ->withArgs(function (mixed $source, string $mode) use ($mapping): bool {
                return $mode === 'full'
                    && $source instanceof EventMapping
                    && $source->id === $mapping->id;
            })
            ->andReturn([
                'tickets_created' => 1,
                'tickets_updated' => 0,
                'tickets_unchanged' => 0,
            ]);
        $this->app->instance(Xs2EventInventorySyncService::class, $sync);
    }

    private function mapping(string $externalEventId = 'xs2-event-1'): EventMapping
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => $externalEventId,
            'event_name' => 'Test Fixture',
            'event_status' => 'notstarted',
            'raw_payload' => ['event_id' => $externalEventId],
        ]);

        return EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'status' => 'pending',
            'mapping_method' => 'automatic',
        ]);
    }

    private function adminToken(): string
    {
        $user = User::factory()->create(['user_type' => 6]);

        return $user->createToken('xs2-inventory-fetch-test')->plainTextToken;
    }

    private function createTables(): void
    {
        foreach (['event_mappings', 'xs2_events', 'personal_access_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('user_type')->nullable();
            $table->unsignedInteger('store_id')->default(13);
            $table->boolean('two_factor_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('event_name')->nullable();
            $table->string('event_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('status')->default('pending');
            $table->string('mapping_method')->nullable();
            $table->timestamps();
        });
    }
}
