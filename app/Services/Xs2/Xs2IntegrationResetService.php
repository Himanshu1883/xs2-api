<?php

namespace App\Services\Xs2;

use App\Jobs\DeleteXs2SellerListing;
use App\Models\ExternalListingMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Xs2IntegrationResetService
{
    public function __construct(
        private readonly Xs2ResetService $reset,
    ) {}

    /**
     * Delete remote Seats Broker listings, then wipe local XS2 integration data.
     *
     * @return array{
     *     preserve_orders: bool,
     *     remote: array{
     *         tickets_processed: int,
     *         sb_listings_deleted: int,
     *         sb_listings_failed: int
     *     },
     *     catalog: array{
     *         events_deleted: int,
     *         tickets_deleted: int,
     *         venues_deleted: int,
     *         mappings_deleted: int,
     *         tables: array<string, int>,
     *         cache_locks_cleared: int,
     *         cache_entries_cleared: int,
     *         preserved: array<string, int>
     *     }
     * }
     */
    public function wipe(bool $preserveOrders = true, bool $clearCacheLocks = true): array
    {
        $remoteSummary = $this->deleteRemoteListings();

        $catalogSummary = $preserveOrders
            ? $this->reset->resetCatalog(false, $clearCacheLocks)
            : $this->reset->reset(false);

        return [
            'preserve_orders' => $preserveOrders,
            'remote' => $remoteSummary,
            'catalog' => $this->summarizeCatalog($catalogSummary, $preserveOrders),
        ];
    }

    /**
     * @return array{tickets_processed: int, sb_listings_deleted: int, sb_listings_failed: int}
     */
    private function deleteRemoteListings(): array
    {
        $summary = [
            'tickets_processed' => 0,
            'sb_listings_deleted' => 0,
            'sb_listings_failed' => 0,
        ];

        foreach ($this->ticketIdsWithRemoteListings() as $ticketId) {
            $summary['tickets_processed']++;

            try {
                DeleteXs2SellerListing::dispatchSync((int) $ticketId);
                $summary['sb_listings_deleted']++;
            } catch (\Throwable) {
                $summary['sb_listings_failed']++;
            }
        }

        return $summary;
    }

    /**
     * @return list<int>
     */
    private function ticketIdsWithRemoteListings(): array
    {
        $ticketIds = ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->whereNotNull('seller_listing_id')
            ->pluck('xs2_ticket_id');

        if (Schema::hasTable('listing_splits')) {
            $splitTicketIds = DB::table('listing_splits')
                ->where('status', 'active')
                ->whereNotNull('seatsbroker_listing_id')
                ->pluck('master_listing_id');

            $ticketIds = $ticketIds->merge($splitTicketIds);
        }

        return $ticketIds
            ->unique()
            ->sort()
            ->values()
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array{
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     preserved: array<string, int>,
     *     cache_locks_cleared?: int,
     *     cache_entries_cleared?: int
     * }  $summary
     * @return array{
     *     events_deleted: int,
     *     tickets_deleted: int,
     *     venues_deleted: int,
     *     mappings_deleted: int,
     *     tables: array<string, int>,
     *     cache_locks_cleared: int,
     *     cache_entries_cleared: int,
     *     preserved: array<string, int>
     * }
     */
    private function summarizeCatalog(array $summary, bool $preserveOrders): array
    {
        $before = $summary['before'] ?? [];
        $tables = $preserveOrders ? Xs2ResetService::CATALOG_TABLES : Xs2ResetService::TABLES;

        $deleted = [];
        foreach ($tables as $table) {
            $count = (int) ($before[$table] ?? 0);
            if ($count > 0) {
                $deleted[$table] = $count;
            }
        }

        return [
            'events_deleted' => (int) ($before['xs2_events'] ?? 0),
            'tickets_deleted' => (int) ($before['xs2_tickets'] ?? 0),
            'venues_deleted' => (int) ($before['xs2_venues'] ?? 0),
            'mappings_deleted' => (int) (($before['event_mappings'] ?? 0) + ($before['xs2_stadium_mappings'] ?? 0)),
            'tables' => $deleted,
            'cache_locks_cleared' => (int) ($summary['cache_locks_cleared'] ?? 0),
            'cache_entries_cleared' => (int) ($summary['cache_entries_cleared'] ?? 0),
            'preserved' => $summary['preserved'] ?? [],
        ];
    }
}
