<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Jobs\SyncXs2EventInventory;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use Illuminate\Console\Command;

class SyncXs2InventoryCommand extends Command
{
    use RespectsQueueBackpressure;
    protected $signature = 'xs2:sync-inventory
        {--mode=incremental : incremental or full}
        {--event-id=}
        {--mapping-id=}
        {--force}
        {--tickets-only : Skip venue, category, and away-team API calls}
        {--only-with-tickets : Only queue events whose XS2 catalog reports number_of_tickets > 0}
        {--bulk : Use bulk-import dispatch spacing (no stagger by default)}';

    protected $description = 'Queue XS2 venue, category, ticket, and Seller API inventory synchronization.';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['incremental', 'full'], true)) {
            $this->error('The --mode option must be incremental or full.');

            return self::INVALID;
        }

        if ($this->skipIfQueueBackpressureActive()) {
            return self::SUCCESS;
        }

        $dispatchBudget = $this->queueDispatchBudget();

        $scope = $this->option('tickets-only') ? 'tickets' : 'full';
        $dispatchSpacingSeconds = $this->dispatchSpacingSeconds();
        $firstDispatchAt = now();
        $count = 0;
        $reconciliationCount = 0;

        $reconciliationQuery = EventMapping::query()
            ->with('xs2Event')
            ->whereHas('xs2Event', fn ($event) => $event->unsellable())
            ->whereHas('xs2Event.tickets.listingMapping', fn ($listing) => $listing
                ->where('provider', 'xs2event')
                ->whereNotNull('seller_listing_id')
                ->where(fn ($listing) => $listing
                    ->where('status', '!=', 'inactive')
                    ->orWhere('last_pushed_quantity', '>', 0)));

        foreach ($reconciliationQuery->get() as $mapping) {
            if ($mapping->xs2Event && ! $mapping->xs2Event->isSellable()) {
                ReconcileSellerListingsForMapping::dispatch($mapping->id);
                $reconciliationCount++;
            }
        }

        $syncQuery = Xs2Event::query()
            ->with('inventorySyncState')
            ->where('date_start_local', '>=', now());

        if ($this->option('only-with-tickets')) {
            $syncQuery->where('number_of_tickets', '>', 0);
        }

        if (filled($this->option('mapping-id'))) {
            $mappingId = (int) $this->option('mapping-id');
            $syncQuery->whereHas('mapping', fn ($mapping) => $mapping->whereKey($mappingId));
        }

        if (filled($this->option('event-id'))) {
            $eventId = (string) $this->option('event-id');
            $syncQuery->where(function ($event) use ($eventId): void {
                $event->where('external_event_id', $eventId);
                if (ctype_digit($eventId)) {
                    $event->orWhereKey((int) $eventId);
                }
            });
        }

        foreach ($syncQuery->get() as $event) {
            if ($count >= $dispatchBudget) {
                $this->warn("Dispatch budget reached ({$dispatchBudget} jobs). Remaining events will sync on the next run.");
                break;
            }

            if (! $event->isSellable()) {
                continue;
            }

            $state = $event->inventorySyncState;
            if (! $this->option('force') && $state?->tickets_next_sync_at?->isFuture()) {
                continue;
            }

            SyncXs2EventInventory::dispatch($event->id, $mode, false, $scope)
                ->delay($firstDispatchAt->copy()->addSeconds($count * $dispatchSpacingSeconds));
            $count++;
        }

        $scopeLabel = $scope === 'tickets' ? ' (tickets-only)' : '';
        $this->info("Queued {$count} XS2 inventory synchronization job(s){$scopeLabel}.");
        if ($reconciliationCount > 0) {
            $this->info("Queued {$reconciliationCount} Seller listing reconciliation job(s) for unavailable XS2 events.");
        }

        return self::SUCCESS;
    }

    private function dispatchSpacingSeconds(): int
    {
        if ($this->option('bulk')) {
            return max(0, (int) config('xs2.bulk_import_dispatch_interval_seconds', 0));
        }

        $requestsPerMinute = max(1, (int) config('services.xs2.rate_limit_per_minute', config('xs2.rate_limit_per_minute', 30)));

        return max(
            1,
            (int) config('xs2.inventory_dispatch_interval_seconds', ceil(120 / $requestsPerMinute)),
        );
    }
}
