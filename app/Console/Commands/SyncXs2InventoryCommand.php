<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Jobs\ReconcileSellerListingsForMapping;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Services\Pipeline\InventorySchedulerService;
use App\Services\Pipeline\PipelineRunService;
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

    public function handle(InventorySchedulerService $scheduler, PipelineRunService $pipelineRuns): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['incremental', 'full'], true)) {
            $this->error('The --mode option must be incremental or full.');

            return self::INVALID;
        }

        $force = (bool) $this->option('force');
        $scope = $this->option('tickets-only') ? 'tickets' : 'full';
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

        $singleEventId = null;
        if (filled($this->option('event-id'))) {
            $eventId = (string) $this->option('event-id');
            $singleEventId = Xs2Event::query()
                ->where(function ($event) use ($eventId): void {
                    $event->where('external_event_id', $eventId);
                    if (ctype_digit($eventId)) {
                        $event->orWhereKey((int) $eventId);
                    }
                })
                ->value('id');
        }

        $mappingId = filled($this->option('mapping-id')) ? (int) $this->option('mapping-id') : null;

        $result = $scheduler->dispatchDueEvents(
            mode: $mode,
            trigger: $force ? 'manual' : 'scheduled',
            force: $force,
            bulk: (bool) $this->option('bulk'),
            scope: $scope,
            singleEventId: $singleEventId ? (int) $singleEventId : null,
            mappingId: $mappingId,
            onlyWithTickets: (bool) $this->option('only-with-tickets'),
        );

        if ($result['skipped_backpressure']) {
            $status = $this->queueBackpressure()->status();
            $this->warn(sprintf(
                'Skipping dispatch — queue backpressure active (%d/%d pending jobs, profile %s). Use --force to override.',
                $status['pending_jobs'],
                $status['max_pending_jobs'],
                $status['profile'],
            ));

            return self::SUCCESS;
        }

        $count = (int) $result['dispatched'];
        $run = $result['pipeline_run'];

        if ($run !== null && $count === 0) {
            $pipelineRuns->finish($run);
        }

        $scopeLabel = $scope === 'tickets' ? ' (tickets-only)' : '';
        $correlation = $run?->correlation_id;
        $this->info("Queued {$count} XS2 inventory synchronization job(s){$scopeLabel}.");
        if ($correlation) {
            $this->info("Pipeline correlation: {$correlation}");
        }
        if ($reconciliationCount > 0) {
            $this->info("Queued {$reconciliationCount} Seller listing reconciliation job(s) for unavailable XS2 events.");
        }

        return self::SUCCESS;
    }
}
