<?php

namespace Tests\Feature;

use App\Jobs\PublishSplitListings;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Admin\CronToggleService;
use App\Services\SellerApi\SbNewListingPublishService;
use App\Services\Xs2\ListingPublishReadinessService;
use App\Services\Xs2\MappedListingPublishService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class RetryFailedSbListingPublishCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('xs2.sb_failed_listing_publish_retry.enabled', true);
        config()->set('services.seller_api.enabled', true);
        config()->set('listing_publish_rules.enabled', true);
    }

    public function test_retry_cron_only_picks_failed_tickets(): void
    {
        Queue::fake();
        config()->set('listing_publish_rules.enabled', false);

        $failedTicket = $this->mappedTicket(stock: 4);
        $failedTicket->update(['sync_status' => 'failed', 'sync_error' => 'Seller API unavailable']);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $failedTicket->id,
            'event_mapping_id' => $failedTicket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
            'mapping_error' => 'Seller API unavailable',
        ]);

        $healthyTicket = $this->mappedTicket(stock: 6);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $healthyTicket->id,
            'event_mapping_id' => $healthyTicket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')
            ->once()
            ->withArgs(fn ($assessedTicket, bool $strictPublish): bool => $assessedTicket->is($failedTicket) && $strictPublish === false)
            ->andReturn(['ready' => true, 'error' => null]);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $summary = app(SbNewListingPublishService::class)->run(failedOnly: true);

        $this->assertSame(1, $summary['eligible_tickets']);
        $this->assertSame(1, $summary['queued']);
        Queue::assertPushed(PushXs2TicketToSellerApi::class, fn ($job): bool => $job->ticketId === $failedTicket->id);
        Queue::assertNotPushed(PublishSplitListings::class);
    }

    public function test_retry_cron_clears_failed_state_on_inline_success(): void
    {
        config()->set('listing_publish_rules.enabled', false);

        $ticket = $this->mappedTicket(stock: 4);
        $ticket->update(['sync_status' => 'failed', 'sync_error' => 'Transient error']);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
            'mapping_error' => 'Transient error',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')
            ->once()
            ->andReturn(['ready' => true, 'error' => null]);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $publisher = Mockery::mock(MappedListingPublishService::class);
        $publisher->shouldReceive('publishTicket')
            ->once()
            ->with($ticket->id, false, true);
        $this->instance(MappedListingPublishService::class, $publisher);

        $this->app->forgetInstance(SbNewListingPublishService::class);
        $summary = app(SbNewListingPublishService::class)->run(inline: true, failedOnly: true);

        $this->assertSame(1, $summary['published_inline']);
        $this->assertSame(0, $summary['failed']);
    }

    public function test_retry_cron_keeps_failed_state_on_inline_failure(): void
    {
        config()->set('listing_publish_rules.enabled', false);

        $ticket = $this->mappedTicket(stock: 4);
        $ticket->update(['sync_status' => 'failed', 'sync_error' => 'Old error']);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
            'mapping_error' => 'Old error',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')
            ->once()
            ->andReturn(['ready' => true, 'error' => null]);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $publisher = Mockery::mock(MappedListingPublishService::class);
        $publisher->shouldReceive('publishTicket')
            ->once()
            ->with($ticket->id, false, true)
            ->andThrow(new \RuntimeException('Still failing'));
        $this->instance(MappedListingPublishService::class, $publisher);

        $this->app->forgetInstance(SbNewListingPublishService::class);
        $summary = app(SbNewListingPublishService::class)->run(inline: true, failedOnly: true);

        $this->assertSame(1, $summary['failed']);
        $ticket->refresh();
        $this->assertSame('failed', $ticket->sync_status);
        $this->assertSame('Still failing', $ticket->sync_error);
    }

    public function test_retry_cron_toggle_is_opt_in(): void
    {
        $this->createIntegrationSettingsTable();

        $service = app(CronToggleService::class);
        $service->setStartAllEnabled(true);

        $this->assertFalse($service->shouldRun('xs2-sb-failed-listing-publish-retry', true));
        $this->assertFalse($service->isCronEnabled('xs2-sb-failed-listing-publish-retry'));

        $service->setCronEnabled('xs2-sb-failed-listing-publish-retry', true);

        $this->assertTrue($service->shouldRun('xs2-sb-failed-listing-publish-retry', true));
        $this->assertTrue($service->isCronEnabled('xs2-sb-failed-listing-publish-retry'));
    }

    private function mappedTicket(int $stock): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-retry-sb-'.uniqid(),
            'event_name' => 'Retry SB Publish Test Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
            'raw_payload' => [],
        ]);
        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9021,
            'status' => 'mapped',
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-retry-sb-'.uniqid(),
            'ticket_status' => 'available',
            'stock' => $stock,
            'category_name' => 'Tribuna',
            'ticket_type' => 'eticket',
            'currency_code' => 'EUR',
            'net_rate' => 35800,
            'raw_payload' => [],
        ]);
    }

    private function createTables(): void
    {
        foreach ([
            'listing_splits',
            'xs2_ticket_mapping_states',
            'external_listing_mappings',
            'xs2_tickets',
            'event_mappings',
            'xs2_events',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->nullable();
            $table->string('event_name')->nullable();
            $table->string('sport_type')->nullable();
            $table->dateTime('date_start_local')->nullable();
            $table->string('event_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
        Schema::create('event_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->unsignedInteger('m_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_event_id')->nullable();
            $table->string('external_ticket_id')->unique();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->string('category_name')->nullable();
            $table->string('ticket_type')->nullable();
            $table->string('currency_code')->nullable();
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->string('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('split_sync_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
        Schema::create('xs2_ticket_mapping_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_ticket_id')->unique();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->string('mapping_status')->nullable();
            $table->text('mapping_error')->nullable();
            $table->timestamp('last_resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('external_listing_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->unsignedBigInteger('xs2_ticket_id');
            $table->unsignedInteger('local_event_id')->nullable();
            $table->unsignedBigInteger('event_mapping_id')->nullable();
            $table->string('seller_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->string('status')->default('pending');
            $table->unsignedInteger('last_pushed_quantity')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->string('status')->default('pending');
            $table->string('seatsbroker_listing_id')->nullable();
            $table->timestamps();
        });
    }

    private function createIntegrationSettingsTable(): void
    {
        if (! Schema::hasTable('integration_settings')) {
            Schema::create('integration_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->boolean('is_secret')->default(false);
                $table->timestamps();
            });
        } else {
            \Illuminate\Support\Facades\DB::table('integration_settings')->delete();
        }
    }
}
