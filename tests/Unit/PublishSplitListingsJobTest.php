<?php

namespace Tests\Unit;

use App\Jobs\PublishSplitListings;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PublishSplitListingsJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skips_publish_when_ticket_has_zero_stock_at_job_run_time(): void
    {
        $ticket = $this->ticketWithStock(0);

        $splits = Mockery::mock(SplitListingService::class);
        $splits->shouldNotReceive('publishListings');

        $job = new PublishSplitListings($ticket->id, $this->splitConfig());
        $job->handle($splits);

        $this->assertSame('idle', $ticket->fresh()->split_sync_status ?? 'idle');
    }

    public function test_publishes_when_ticket_has_stock(): void
    {
        $ticket = $this->ticketWithStock(4);

        $splits = Mockery::mock(SplitListingService::class);
        $splits->shouldReceive('publishListings')
            ->once()
            ->with(
                Mockery::on(fn (Xs2Ticket $loaded): bool => $loaded->id === $ticket->id),
                $this->splitConfig(),
            )
            ->andReturn([
                'master_listing_id' => $ticket->id,
                'listings_count' => 2,
                'created' => 2,
                'updated' => 0,
                'deleted' => 0,
            ]);

        $job = new PublishSplitListings($ticket->id, $this->splitConfig());
        $job->handle($splits);
    }

    /** @return array{split_quantity: int, price_increment_type: string, price_increment_value: float} */
    private function splitConfig(): array
    {
        return [
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5.0,
        ];
    }

    private function ticketWithStock(int $stock): Xs2Ticket
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'event-publish-split-'.uniqid(),
            'event_status' => 'notstarted',
            'date_start_local' => now()->addDay(),
        ]);

        return Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'ticket-publish-split-'.uniqid(),
            'ticket_status' => 'available',
            'stock' => $stock,
            'net_rate' => 10000,
            'split_quantity' => 2,
            'price_increment_type' => 'fixed',
            'price_increment_value' => 5,
        ]);
    }

    private function createTables(): void
    {
        foreach (['xs2_tickets', 'event_mappings', 'xs2_events'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('xs2_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->nullable();
            $table->dateTime('date_start_local')->nullable();
            $table->string('event_status')->nullable();
            $table->timestamp('missing_since')->nullable();
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
            $table->string('external_ticket_id')->unique();
            $table->string('ticket_status')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedBigInteger('net_rate')->nullable();
            $table->boolean('split_enabled')->default(false);
            $table->unsignedInteger('split_quantity')->nullable();
            $table->string('price_increment_type')->nullable();
            $table->decimal('price_increment_value', 10, 2)->nullable();
            $table->string('split_sync_status')->default('idle');
            $table->text('split_sync_error')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
    }
}
