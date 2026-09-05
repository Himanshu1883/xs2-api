<?php

namespace Tests\Unit;

use App\Models\Xs2EventInventorySyncState;
use App\Services\Pipeline\PipelineStaleStateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PipelineStaleStateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createInventorySyncStateTable();
    }

    public function test_clears_stale_inventory_running_when_downstream_pipeline_stages_completed(): void
    {
        $eventId = $this->seedEvent();
        $state = Xs2EventInventorySyncState::query()->create([
            'xs2_event_id' => $eventId,
            'tickets_sync_status' => 'running',
            'listing_gen_status' => 'completed',
            'reconcile_status' => 'completed',
            'tickets_last_incremental_sync_at' => now()->subHour(),
            'updated_at' => now()->subMinutes(20),
        ]);

        $summary = app(PipelineStaleStateService::class)->reconcile();

        $this->assertSame(1, $summary['inventory_completed']);
        $this->assertSame(0, $summary['inventory_failed']);
        $this->assertSame('completed', $state->fresh()->tickets_sync_status);
        $this->assertNull($state->fresh()->tickets_sync_error);
    }

    public function test_skips_reconcile_when_inventory_lock_is_held(): void
    {
        $eventId = $this->seedEvent();
        $state = Xs2EventInventorySyncState::query()->create([
            'xs2_event_id' => $eventId,
            'tickets_sync_status' => 'running',
            'listing_gen_status' => 'completed',
            'reconcile_status' => 'completed',
            'updated_at' => now()->subMinutes(20),
        ]);

        $lock = Cache::lock('xs2-event-inventory:event:'.$eventId, 600);
        $lock->get();

        $summary = app(PipelineStaleStateService::class)->reconcile();

        $this->assertSame(0, $summary['inventory_completed']);
        $this->assertSame(0, $summary['inventory_failed']);
        $this->assertSame('running', $state->fresh()->tickets_sync_status);

        $lock->release();
    }

    public function test_marks_stale_inventory_running_as_failed_without_prior_success(): void
    {
        $eventId = $this->seedEvent();
        $state = Xs2EventInventorySyncState::query()->create([
            'xs2_event_id' => $eventId,
            'tickets_sync_status' => 'running',
            'updated_at' => now()->subMinutes(20),
        ]);

        $summary = app(PipelineStaleStateService::class)->reconcile();

        $this->assertSame(0, $summary['inventory_completed']);
        $this->assertSame(1, $summary['inventory_failed']);
        $this->assertSame('failed', $state->fresh()->tickets_sync_status);
        $this->assertStringContainsString('no active worker', (string) $state->fresh()->tickets_sync_error);
    }

    private function seedEvent(): int
    {
        return (int) DB::table('xs2_events')->insertGetId([
            'external_event_id' => 'evt-'.uniqid(),
            'event_name' => 'Test Event',
            'date_start_local' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createInventorySyncStateTable(): void
    {
        if (! Schema::hasTable('xs2_events')) {
            Schema::create('xs2_events', function (Blueprint $table): void {
                $table->id();
                $table->string('external_event_id')->unique();
                $table->string('event_name')->nullable();
                $table->timestamp('date_start_local')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            Schema::create('xs2_event_inventory_sync_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('xs2_event_id');
                $table->string('tickets_sync_status', 40)->nullable();
                $table->text('tickets_sync_error')->nullable();
                $table->timestamp('tickets_last_incremental_sync_at')->nullable();
                $table->timestamp('tickets_last_full_sync_at')->nullable();
                $table->timestamp('tickets_next_sync_at')->nullable();
                $table->string('listing_gen_status', 40)->nullable();
                $table->string('publish_status', 40)->nullable();
                $table->string('reconcile_status', 40)->nullable();
                $table->timestamp('last_pipeline_stage_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
