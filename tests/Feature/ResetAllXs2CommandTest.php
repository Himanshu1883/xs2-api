<?php

namespace Tests\Feature;

use App\Models\EventMapping;
use App\Models\User;
use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2Ticket;
use App\Models\Xs2Venue;
use App\Services\Xs2\Xs2ResetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResetAllXs2CommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createSharedUsersTable();
        $this->createVenueMappingTables();
    }

    public function test_reset_all_command_wipes_xs2_data_and_preserves_users(): void
    {
        User::query()->create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'status' => User::ACTIVE_STATUS,
        ]);

        $event = Xs2Event::query()->create([
            'external_event_id' => 'reset-all-event',
            'event_name' => 'Reset all fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        EventMapping::query()->create([
            'xs2_event_id' => $event->id,
            'm_id' => 9001,
            'status' => 'mapped',
        ]);

        Xs2Ticket::query()->create([
            'xs2_event_id' => $event->id,
            'external_ticket_id' => 'reset-all-ticket',
            'external_event_id' => $event->external_event_id,
            'ticket_status' => 'available',
            'stock' => 2,
            'sync_status' => 'synced',
            'raw_payload' => [],
        ]);

        $venue = Xs2Venue::query()->create([
            'external_venue_id' => 'reset-all-venue',
            'venue_name' => 'Reset Arena',
            'raw_payload' => [],
        ]);

        Xs2StadiumMapping::query()->create([
            'xs2_venue_id' => $venue->id,
            'status' => 'mapped',
        ]);

        $this->artisan('xs2:reset-all --force --skip-remote')
            ->assertSuccessful();

        $this->assertSame(0, Xs2Event::query()->count());
        $this->assertSame(0, EventMapping::query()->count());
        $this->assertSame(0, Xs2Ticket::query()->count());
        $this->assertSame(0, Xs2Venue::query()->count());
        $this->assertSame(0, Xs2StadiumMapping::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_reset_all_dry_run_does_not_delete_rows(): void
    {
        Xs2Event::query()->create([
            'external_event_id' => 'dry-run-event',
            'event_name' => 'Dry run fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $this->artisan('xs2:reset-all --force --dry-run --skip-remote')
            ->assertSuccessful();

        $this->assertSame(1, Xs2Event::query()->count());
    }

    public function test_reset_all_requires_force_flag(): void
    {
        $this->artisan('xs2:reset-all')
            ->assertFailed();
    }

    public function test_reset_service_reports_before_and_after_counts(): void
    {
        Xs2Event::query()->create([
            'external_event_id' => 'service-event',
            'event_name' => 'Service fixture',
            'date_start_local' => now()->addDay(),
            'event_status' => 'notstarted',
            'raw_payload' => [],
        ]);

        $summary = app(Xs2ResetService::class)->reset();

        $this->assertSame(1, $summary['before']['xs2_events']);
        $this->assertSame(0, $summary['after']['xs2_events']);
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

        if (! Schema::hasTable('xs2_stadium_mappings')) {
            Schema::create('xs2_stadium_mappings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('xs2_venue_id')->constrained('xs2_venues')->cascadeOnDelete();
                $table->unsignedBigInteger('stadium_id')->nullable();
                $table->string('status', 40)->default('pending_country_resolution');
                $table->timestamps();
                $table->unique('xs2_venue_id');
            });
        }
    }
}
