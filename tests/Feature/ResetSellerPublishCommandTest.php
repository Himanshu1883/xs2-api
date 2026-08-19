<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\SellerApiClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ResetSellerPublishCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createMappingStateTable();
    }

    public function test_reset_command_unpublishes_remotely_and_clears_local_publish_state(): void
    {
        $mapping = $this->mappedEvent();
        $ticket = Xs2Ticket::query()->create([
            'xs2_event_id' => $mapping->xs2_event_id,
            'external_ticket_id' => 'reset-ticket-1',
            'external_event_id' => $mapping->xs2Event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);
        ExternalListingMapping::query()->create([
            'provider' => 'xs2event',
            'xs2_ticket_id' => $ticket->id,
            'local_event_id' => $mapping->m_id,
            'event_mapping_id' => $mapping->id,
            'seller_listing_id' => 'seller-999',
            'seller_reference' => 'XS2-reset-ticket-1',
            'status' => 'active',
            'last_pushed_quantity' => 2,
        ]);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'mapping_status' => 'published',
        ]);

        $seller = Mockery::mock(SellerApiClient::class);
        $seller->shouldReceive('sellerId')->once()->andReturn(77);
        $seller->shouldReceive('disableListing')
            ->once()
            ->with('seller-999', Mockery::on(fn (array $payload): bool => $payload['status'] === '0'))
            ->andReturn(['status' => 'ok']);
        $this->app->instance(SellerApiClient::class, $seller);

        $this->artisan('xs2:reset-seller-publish --force')
            ->assertSuccessful();

        $listing = ExternalListingMapping::query()->sole();
        $this->assertNull($listing->seller_listing_id);
        $this->assertSame('pending', $listing->status);
        $this->assertSame(0, $listing->last_pushed_quantity);

        $this->assertSame('pending', $ticket->fresh()->sync_status);
        $this->assertNotSame('published', $ticket->fresh()->mappingState?->mapping_status);
    }

    private function mappedEvent(): EventMapping
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'reset-event',
            'event_name' => 'Reset fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        return EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9001,
            'status' => 'mapped',
        ]);
    }

    private function createMappingStateTable(): void
    {
        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            return;
        }

        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_ticket_id')->unique();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_venue_id')->nullable();
            $table->unsignedBigInteger('xs2_category_id')->nullable();
            $table->unsignedBigInteger('xs2_stadium_mapping_id')->nullable();
            $table->unsignedBigInteger('xs2_category_mapping_id')->nullable();
            $table->string('mapping_status', 40)->default('pending_event_mapping');
            $table->text('mapping_error')->nullable();
            $table->timestamp('last_resolved_at')->nullable();
            $table->timestamps();
        });
    }
}
