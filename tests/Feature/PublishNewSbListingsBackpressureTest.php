<?php

namespace Tests\Feature;

use App\Jobs\PublishSplitListings;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Admin\QueueProfileService;
use App\Services\Xs2\ListingPublishReadinessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PublishNewSbListingsBackpressureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('xs2.sb_new_listing_publish.enabled', true);
        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.queue', 'seller-api');
        config()->set('listing_publish_rules.enabled', true);
    }

    public function test_publish_cron_dispatches_when_xs2_sync_backlog_is_overloaded(): void
    {
        Queue::fake();
        $this->seedPendingJobs('xs2-sync', 248);
        $this->mockQueueProfile(maxPending: 150);

        $ticket = $this->mappedTicket(stock: 8);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')
            ->once()
            ->andReturn(['ready' => true, 'error' => null]);
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $exitCode = Artisan::call('xs2:publish-new-sb-listings');

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(PublishSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_publish_cron_skips_when_seller_api_queue_is_overloaded(): void
    {
        Queue::fake();
        $this->seedPendingJobs('xs2-sync', 0);
        $this->seedPendingJobs('seller-api', 160);
        $this->mockQueueProfile(maxPending: 150);

        $ticket = $this->mappedTicket(stock: 8);
        Xs2TicketMappingState::query()->create([
            'xs2_ticket_id' => $ticket->id,
            'event_mapping_id' => $ticket->xs2Event->mapping->id,
            'mapping_status' => 'ready_to_publish',
        ]);

        $readiness = Mockery::mock(ListingPublishReadinessService::class);
        $readiness->shouldReceive('assess')->never();
        $this->instance(ListingPublishReadinessService::class, $readiness);

        $exitCode = Artisan::call('xs2:publish-new-sb-listings');

        $this->assertSame(0, $exitCode);
        Queue::assertNothingPushed();
    }

    private function mockQueueProfile(int $maxPending, int $maxDispatch = 30): void
    {
        $profiles = Mockery::mock(QueueProfileService::class);
        $profiles->shouldReceive('activeProfile')->andReturn([
            'max_pending_jobs' => $maxPending,
            'max_dispatch_per_run' => $maxDispatch,
        ]);
        $profiles->shouldReceive('activeProfileId')->andReturn('balanced');
        $this->instance(QueueProfileService::class, $profiles);
    }

    private function seedPendingJobs(string $queue, int $count): void
    {
        $now = now()->getTimestamp();
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $rows[] = [
                'queue' => $queue,
                'payload' => json_encode(['job' => 'test']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('jobs')->insert($chunk);
        }
    }

    private function mappedTicket(int $stock): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-bp-'.uniqid(),
            'event_name' => 'Backpressure Test Event',
            'sport_type' => 'soccer',
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
            'raw_payload' => [],
        ]);
        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9020,
            'status' => 'mapped',
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'external_ticket_id' => 'ticket-bp-'.uniqid(),
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
            'jobs',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
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
}
