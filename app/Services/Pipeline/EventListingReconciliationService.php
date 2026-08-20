<?php

namespace App\Services\Pipeline;

use App\Models\Xs2Ticket;
use App\Services\SellerApi\MasterListingQuantitySyncService;
use App\Services\SplitListings\SplitListingQuantitySyncService;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event reconciliation reusing master and split quantity sync services.
 */
class EventListingReconciliationService
{
    public function __construct(
        private readonly MasterListingQuantitySyncService $masters,
        private readonly SplitListingQuantitySyncService $splits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcileEvent(int $xs2EventId, bool $force = false): array
    {
        if (! Schema::hasTable('xs2_tickets')) {
            return [
                'eligible_tickets' => 0,
                'needs_sync' => 0,
                'queued' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        $ticketIds = Xs2Ticket::query()
            ->where('xs2_event_id', $xs2EventId)
            ->pluck('id');

        $summary = [
            'eligible_tickets' => $ticketIds->count(),
            'needs_sync' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($ticketIds as $ticketId) {
            $ticket = Xs2Ticket::query()->with(['listingMapping', 'listingSplits', 'xs2Event'])->find($ticketId);
            if ($ticket === null) {
                continue;
            }

            $service = $ticket->split_enabled ? $this->splits : $this->masters;
            $needsSync = $ticket->split_enabled
                ? $this->splits->ticketNeedsSync($ticket)
                : $this->masters->ticketNeedsSync($ticket);

            if (! $force && ! $needsSync) {
                $summary['skipped']++;

                continue;
            }

            $summary['needs_sync']++;

            try {
                $result = $service->run(
                    inline: false,
                    ticketId: (int) $ticketId,
                    force: $force,
                    manageState: false,
                );
                $summary['queued'] += (int) ($result['queued'] ?? 0);
                $summary['errors'] = array_merge($summary['errors'], $result['errors'] ?? []);
            } catch (\Throwable $exception) {
                $summary['errors'][] = $ticket->external_ticket_id.': '.$exception->getMessage();
            }
        }

        return $summary;
    }
}
