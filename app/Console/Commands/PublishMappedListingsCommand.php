<?php

namespace App\Console\Commands;

use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Xs2\ListingPublishReadinessService;
use App\Services\Xs2\MappedListingPublishService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PublishMappedListingsCommand extends Command
{
    protected $signature = 'xs2:publish-mapped-listings
                            {--sync : Run publish jobs inline instead of queueing}
                            {--ticket= : Limit to one XS2 ticket id}
                            {--dry-run : Show eligible tickets without publishing}';

    protected $description = 'Publish mapped XS2 tickets to Seats Broker using configurable listing publish rules.';

    public function handle(
        MappedListingPublishService $publisher,
        Xs2TicketMappingStatusService $mappingStatuses,
        ListingPublishReadinessService $readiness,
    ): int {
        if (! (bool) config('services.seller_api.enabled', true)) {
            $this->warn('Seller API integration is disabled (SELLER_API_ENABLED=false).');

            return self::SUCCESS;
        }

        $ticketId = filled($this->option('ticket')) ? (int) $this->option('ticket') : null;
        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');

        $query = Xs2Ticket::query()
            ->with(['xs2Event.mapping', 'mappingState'])
            ->where('ticket_status', 'available')
            ->where('stock', '>', 0)
            ->whereHas('xs2Event', fn ($event) => $event->where('event_status', '!=', 'cancelled'))
            ->whereHas('xs2Event.mapping', fn ($mapping) => $mapping
                ->whereIn('status', ['mapped', 'created'])
                ->whereNotNull('m_id'));

        if ($ticketId !== null) {
            $query->whereKey($ticketId);
        }

        $eligible = 0;
        $published = 0;
        $skipped = 0;

        $this->info('Scanning mapped XS2 tickets eligible for rule-based publish...');

        $query->orderBy('id')->chunkById(100, function ($tickets) use (
            $publisher,
            $mappingStatuses,
            $readiness,
            $sync,
            $dryRun,
            &$eligible,
            &$published,
            &$skipped,
        ): void {
            foreach ($tickets as $ticket) {
                if (! ($ticket->xs2Event?->isSellable() ?? false)) {
                    $skipped++;

                    continue;
                }

                $state = Schema::hasTable('xs2_ticket_mapping_states')
                    ? $mappingStatuses->resolve($ticket)
                    : null;

                if (! $mappingStatuses->isAutoPublishable($state?->mapping_status)) {
                    $skipped++;

                    continue;
                }

                $assessment = $readiness->assess($ticket);
                if (! $assessment['ready']) {
                    $skipped++;

                    continue;
                }

                $eligible++;

                if ($dryRun) {
                    $this->line("  [dry-run] ticket #{$ticket->id} stock={$ticket->stock}");

                    continue;
                }

                try {
                    $publisher->publishTicket($ticket->id, strictPublish: false, sync: $sync);
                    $published++;
                } catch (\Throwable $e) {
                    $this->error("  ticket #{$ticket->id}: {$e->getMessage()}");
                }
            }
        });

        $this->table(
            ['Metric', 'Value'],
            [
                ['eligible', (string) $eligible],
                ['published', (string) $published],
                ['skipped', (string) $skipped],
                ['dry_run', $dryRun ? 'yes' : 'no'],
            ],
        );

        return self::SUCCESS;
    }
}
