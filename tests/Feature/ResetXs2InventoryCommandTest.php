<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Models\Xs2Venue;
use App\Services\Xs2\Xs2ResetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResetXs2InventoryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createVenueMappingTables();
    }

    public function test_reset_inventory_command_wipes_inventory_and_preserves_events(): void
    {
        User::query()->create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'status' => User::ACTIVE_STATUS,
        ]);

        $event = Xs2Event::query()->create([
            'external_event_id' => 'reset-inventory-event',
            'event_name' => 'Reset inventory fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9002,
            'status' => 'mapped',
        ]);

        Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'reset-inventory-ticket',
            'external_event_id' => $event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);

        Xs2Venue::query()->create([
            'external_venue_id' => 'reset-inventory-venue',
            'venue_name' => 'Inventory Arena',
            'raw_payload' => [],
        ]);

        $this->artisan('xs2:reset-inventory --force')
            ->assertSuccessful();

        $this->assertSame(1, Xs2Event::query()->count());
        $this->assertSame(1, EventMapping::query()->count());
        $this->assertSame(0, Xs2Ticket::query()->count());
        $this->assertSame(1, Xs2Venue::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_reset_inventory_dry_run_does_not_delete_rows(): void
    {
        Xs2Event::query()->create([
            'external_event_id' => 'inventory-dry-run-event',
            'event_name' => 'Inventory dry run fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $this->artisan('xs2:reset-inventory --force --dry-run')
            ->assertSuccessful();

        $this->assertSame(1, Xs2Event::query()->count());
    }

    public function test_reset_inventory_requires_force_flag(): void
    {
        $this->artisan('xs2:reset-inventory')
            ->assertFailed();
    }

    public function test_reset_inventory_service_reports_before_and_after_counts(): void
    {
        $event = Xs2Event::query()->create([
            'external_event_id' => 'inventory-service-event',
            'event_name' => 'Inventory service fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'inventory-service-ticket',
            'external_event_id' => $event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 1,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);

        $summary = app(Xs2ResetService::class)->resetInventory();

        $this->assertSame(1, $summary['before']['xs2_tickets']);
        $this->assertSame(0, $summary['after']['xs2_tickets']);
        $this->assertSame(1, $summary['preserved']['xs2_events']);
    }

    private function createVenueMappingTables(): void
    {
        if (! Schema::hasTable('xs2_venues')) {
            Schema::create('xs2_venues', function (Blueprint $table): void {
                $table->id();
                $table->string('external_venue_id', 100)->unique();
                $table->string('venue_name')->nullable();
                $table->json('raw_payload');
                $table->timestamps();
            });
        }
    }
}
