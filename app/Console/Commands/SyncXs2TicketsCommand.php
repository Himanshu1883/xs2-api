<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Jobs\SyncXs2EventInventory;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use Illuminate\Console\Command;

class SyncXs2TicketsCommand extends Command
{
    use RespectsQueueBackpressure;
    protected $signature = 'xs2:sync-tickets
        {--mode=incremental}
        {--event-id=}
        {--mapping-id=}
        {--force}
        {--only-with-tickets : Only queue events whose XS2 catalog reports number_of_tickets > 0}
        {--bulk : Use bulk-import dispatch spacing (no stagger by default)}';

    protected $description = 'Queue XS2 ticket synchronization for all upcoming sellable XS2 events.';

    public function handle(): int
    {
        if ($this->skipIfQueueBackpressureActive()) {
            return self::SUCCESS;
        }

        $dispatchBudget = $this->queueDispatchBudget();
        $mode = $this->option('mode') === 'full' ? 'full' : 'incremental';
        $dispatchSpacingSeconds = $this->dispatchSpacingSeconds();
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

        if ($this->option('mapping-id')) {
            $syncQuery->whereHas('mapping', fn ($mapping) => $mapping->whereKey((int) $this->option('mapping-id')));
        }
        if ($this->option('event-id')) {
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

            if (! $this->option('force') && $event->inventorySyncState?->tickets_next_sync_at?->isFuture()) {
                continue;
            }

            SyncXs2EventInventory::dispatch($event->id, $mode, false, 'tickets')
                ->delay(now()->addSeconds($count * $dispatchSpacingSeconds));
            $count++;
        }

        $this->info("Queued {$count} XS2 ticket synchronization job(s) (tickets-only).");
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

        return 1;
    }
}
