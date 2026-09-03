<?php

namespace Tests\Unit;

use App\Jobs\SyncSplitListings;
use App\Models\ListingSplit;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillHomeTownCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('services.seller_api.enabled', true);
    }

    public function test_command_queues_sync_for_splits_with_home_town_zero(): void
    {
        Queue::fake();

        $ticket = $this->splitTicket();
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 1,
            'seller_reference' => 'XS2-HT-S1',
            'quantity' => 2,
            'price' => 100,
            'seatsbroker_listing_id' => '906600',
            'status' => 'active',
            'sync_status' => 'synced',
            'last_request' => ['home_town' => 0],
        ]);

        $this->artisan('xs2:backfill-home-town')
            ->assertSuccessful()
            ->expectsOutputToContain('Queued 1 SyncSplitListings job(s)');

        Queue::assertPushed(SyncSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    public function test_command_reports_no_work_when_home_town_already_team_name(): void
    {
        Queue::fake();

        $ticket = $this->splitTicket();
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 1,
            'seller_reference' => 'XS2-HT-S1',
            'quantity' => 2,
            'price' => 100,
            'seatsbroker_listing_id' => '906600',
            'status' => 'active',
            'sync_status' => 'synced',
            'last_request' => ['home_town' => 'Arsenal'],
        ]);

        $this->artisan('xs2:backfill-home-town')
            ->assertSuccessful()
            ->expectsOutputToContain('No active split listings with legacy numeric home_town');

        Queue::assertNothingPushed();
    }

    public function test_command_queues_sync_for_splits_with_home_town_one(): void
    {
        Queue::fake();

        $ticket = $this->splitTicket();
        ListingSplit::query()->create([
            'master_listing_id' => $ticket->id,
            'split_order' => 1,
            'seller_reference' => 'XS2-HT-S2',
            'quantity' => 2,
            'price' => 100,
            'seatsbroker_listing_id' => '906601',
            'status' => 'active',
            'sync_status' => 'synced',
            'last_request' => ['home_town' => 1],
        ]);

        $this->artisan('xs2:backfill-home-town')
            ->assertSuccessful()
            ->expectsOutputToContain('Queued 1 SyncSplitListings job(s)');

        Queue::assertPushed(SyncSplitListings::class, fn ($job): bool => $job->ticketId === $ticket->id);
    }

    private function splitTicket(): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'event_status' => 'available',
            'date_start_local' => now()->addWeek(),
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-home-town-backfill',
            'external_event_id' => 'evt-home-town-backfill',
            'ticket_status' => 'available',
            'stock' => 4,
            'net_rate' => 10000,
            'split_enabled' => true,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 10,
            'split_sync_status' => 'completed',
            'raw_payload' => [],
        ]);
    }

    private function createTables(): void
    {
        foreach (['listing_splits', 'xs2_tickets', 'xs2_events'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('date_start_local')->nullable();
            $table->string('event_status')->nullable();
            $table->timestamps();
        });

        Schema::create('xs2_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('xs2_event_id');
            $table->string('external_ticket_id')->unique();
            $table->string('external_event_id')->nullable();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->json('raw_payload')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->unsignedInteger('split_quantity')->nullable();
            $table->string('price_increment_type', 20)->nullable();
            $table->decimal('price_increment_value', 12, 2)->nullable();
            $table->string('split_sync_status', 30)->default('idle');
            $table->timestamps();
        });

        Schema::create('listing_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_listing_id');
            $table->string('seatsbroker_listing_id')->nullable();
            $table->string('seller_reference')->unique();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('split_order');
            $table->string('status', 20)->default('active');
            $table->string('sync_status', 30)->default('pending');
            $table->json('last_request')->nullable();
            $table->timestamps();
        });
    }
}
