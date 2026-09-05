<?php

namespace App\Services\Pipeline;

use App\Models\Xs2EventInventorySyncState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PipelineStaleStateService
{
    /** @return array{inventory_completed: int, inventory_failed: int, listing_gen_completed: int, reconcile_completed: int, publish_completed: int} */
    public function reconcile(): array
    {
        if (! Schema::hasTable('xs2_event_inventory_sync_states')) {
            return $this->emptySummary();
        }

        $stallMinutes = (int) config('pipeline.stall_minutes', 15);
        $cutoff = now()->subMinutes($stallMinutes);

        $summary = $this->emptySummary();

        Xs2EventInventorySyncState::query()
            ->where('updated_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->where('tickets_sync_status', 'running')
                    ->orWhere('listing_gen_status', 'running')
                    ->orWhere('publish_status', 'running')
                    ->orWhere('reconcile_status', 'running');
            })
            ->orderBy('id')
            ->chunkById(100, function ($states) use (&$summary): void {
                foreach ($states as $state) {
                    $inventoryOutcome = $this->reconcileInventorySyncState($state);
                    if ($inventoryOutcome === 'completed') {
                        $summary['inventory_completed']++;
                    } elseif ($inventoryOutcome === 'failed') {
                        $summary['inventory_failed']++;
                    }

                    if ($this->reconcileListingGenState($state)) {
                        $summary['listing_gen_completed']++;
                    }

                    if ($this->reconcilePublishState($state)) {
                        $summary['publish_completed']++;
                    }

                    if ($this->reconcileReconcileState($state)) {
                        $summary['reconcile_completed']++;
                    }
                }
            });

        return $summary;
    }

    private function reconcileInventorySyncState(Xs2EventInventorySyncState $state): ?string
    {
        if ($state->tickets_sync_status !== 'running') {
            return null;
        }

        if ($this->inventoryLockHeld((int) $state->xs2_event_id)) {
            return null;
        }

        $downstreamComplete = in_array($state->listing_gen_status, ['completed', 'skipped'], true)
            || in_array($state->reconcile_status, ['completed', 'skipped'], true);
        $hadSuccessfulSync = filled($state->tickets_last_incremental_sync_at)
            || filled($state->tickets_last_full_sync_at);

        if ($downstreamComplete || $hadSuccessfulSync) {
            $state->update([
                'tickets_sync_status' => 'completed',
                'tickets_sync_error' => null,
            ]);

            return 'completed';
        }

        $stallMinutes = (int) config('pipeline.stall_minutes', 15);
        $state->update([
            'tickets_sync_status' => 'failed',
            'tickets_sync_error' => 'Stale running inventory sync cleared after '
                .$stallMinutes.' minutes with no active worker.',
        ]);

        return 'failed';
    }

    private function reconcileListingGenState(Xs2EventInventorySyncState $state): bool
    {
        if ($state->listing_gen_status !== 'running') {
            return false;
        }

        if (! in_array($state->reconcile_status, ['completed', 'skipped'], true)
            && ! in_array($state->publish_status, ['completed', 'skipped'], true)) {
            return false;
        }

        $state->update(['listing_gen_status' => 'completed']);

        return true;
    }

    private function reconcilePublishState(Xs2EventInventorySyncState $state): bool
    {
        if ($state->publish_status !== 'running') {
            return false;
        }

        if (! in_array($state->reconcile_status, ['completed', 'skipped'], true)) {
            return false;
        }

        $state->update(['publish_status' => 'completed']);

        return true;
    }

    private function reconcileReconcileState(Xs2EventInventorySyncState $state): bool
    {
        if ($state->reconcile_status !== 'running') {
            return false;
        }

        if ($state->listing_gen_status !== 'running'
            && in_array($state->listing_gen_status, ['completed', 'skipped'], true)
            && in_array($state->tickets_sync_status, ['completed', 'success', 'skipped'], true)) {
            $state->update(['reconcile_status' => 'completed']);

            return true;
        }

        return false;
    }

    private function inventoryLockHeld(int $xs2EventId): bool
    {
        $lock = Cache::lock(
            'xs2-event-inventory:event:'.$xs2EventId,
            max(1, (int) config('xs2.sync.event_lock_minutes', 10)) * 60,
        );

        if (! $lock->get(0)) {
            return true;
        }

        $lock->release();

        return false;
    }

    /** @return array{inventory_completed: int, inventory_failed: int, listing_gen_completed: int, reconcile_completed: int, publish_completed: int} */
    private function emptySummary(): array
    {
        return [
            'inventory_completed' => 0,
            'inventory_failed' => 0,
            'listing_gen_completed' => 0,
            'reconcile_completed' => 0,
            'publish_completed' => 0,
        ];
    }
}
