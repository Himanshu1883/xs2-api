<?php

namespace App\Services\SellerApi;

use App\Jobs\PushXs2TicketToSellerApi;
use App\Jobs\SyncSplitListings;
use App\Models\ExternalListingMapping;
use App\Models\ListingSplit;
use App\Models\SbOrder;
use App\Models\Xs2Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Links SB orders to published marketplace listings and derives sold / remaining qty.
 *
 * Booking payloads expose both `listing_id` and `ticket_id`; SeatsBroker's create
 * response `ticket_id` is what we persist as `seller_listing_id` /
 * `seatsbroker_listing_id`, so matching prefers `ticket_id` then `listing_id`.
 */
class ListingSalesService
{
    public const CANCELLED_STATUS = 3;

    /**
     * Attach sold_quantity / remaining_quantity (and optional split_sales) onto tickets.
     *
     * @param  Collection<int, Xs2Ticket>  $tickets
     */
    public function attachSalesToTickets(Collection $tickets): void
    {
        if ($tickets->isEmpty() || ! Schema::hasTable('sb_orders')) {
            foreach ($tickets as $ticket) {
                $ticket->setAttribute('sold_quantity', 0);
                $ticket->setAttribute('remaining_quantity', max(0, (int) ($ticket->stock ?? 0)));
                $ticket->setAttribute('split_sales', []);
            }

            return;
        }

        foreach ($tickets as $ticket) {
            $ticket->loadMissing(['listingMapping', 'listingSplits']);
        }

        $listingIds = [];
        foreach ($tickets as $ticket) {
            foreach ($this->marketplaceListingIdsForTicket($ticket) as $id) {
                $listingIds[$id] = true;
            }
        }

        $soldByListing = $this->soldQuantitiesByListingIds(array_keys($listingIds));

        foreach ($tickets as $ticket) {
            $ids = $this->marketplaceListingIdsForTicket($ticket);
            $sold = 0;
            foreach ($ids as $id) {
                $sold += $soldByListing[$id] ?? 0;
            }

            $stock = max(0, (int) ($ticket->stock ?? 0));
            $ticket->setAttribute('sold_quantity', $sold);
            $ticket->setAttribute('remaining_quantity', max(0, $stock - $sold));

            $splitSales = [];
            foreach ($ticket->listingSplits ?? [] as $split) {
                if ($split->status !== 'active' || ! $split->seatsbroker_listing_id) {
                    continue;
                }
                $splitSold = $soldByListing[$split->seatsbroker_listing_id] ?? 0;
                $splitQty = max(0, (int) $split->quantity);
                $splitSales[] = [
                    'id' => $split->id,
                    'split_order' => $split->split_order,
                    'seatsbroker_listing_id' => $split->seatsbroker_listing_id,
                    'quantity' => $splitQty,
                    'sold_quantity' => $splitSold,
                    'remaining_quantity' => max(0, $splitQty - $splitSold),
                ];
            }
            $ticket->setAttribute('split_sales', $splitSales);
        }
    }

    /**
     * Total active sold qty across every marketplace listing id for a ticket.
     */
    public function soldQuantityForTicket(Xs2Ticket $ticket): int
    {
        $ticket->loadMissing(['listingMapping', 'listingSplits']);
        $ids = $this->marketplaceListingIdsForTicket($ticket);
        if ($ids === []) {
            return 0;
        }

        $soldByListing = $this->soldQuantitiesByListingIds($ids);

        return array_sum($soldByListing);
    }

    public function soldQuantityForMarketplaceListingId(string $listingId): int
    {
        $listingId = trim($listingId);
        if ($listingId === '' || ! Schema::hasTable('sb_orders')) {
            return 0;
        }

        return $this->soldQuantitiesByListingIds([$listingId])[$listingId] ?? 0;
    }

    /**
     * Remaining publishable qty for a 1:1 master listing (stock − sold).
     */
    public function remainingQuantityForTicket(Xs2Ticket $ticket): int
    {
        return max(0, (int) ($ticket->stock ?? 0) - $this->soldQuantityForTicket($ticket));
    }

    /**
     * Remaining qty for a split row given its planned chunk size.
     */
    public function remainingQuantityForSplit(ListingSplit $split, ?int $plannedQuantity = null): int
    {
        $planned = max(0, $plannedQuantity ?? (int) $split->quantity);
        if (! $split->seatsbroker_listing_id) {
            return $planned;
        }

        return max(0, $planned - $this->soldQuantityForMarketplaceListingId($split->seatsbroker_listing_id));
    }

    /**
     * @param  list<string>  $listingIds
     * @return array<string, int>
     */
    public function soldQuantitiesByListingIds(array $listingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $listingIds,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === [] || ! Schema::hasTable('sb_orders')) {
            return [];
        }

        $numericIds = array_values(array_filter($ids, static fn (string $id): bool => ctype_digit($id)));

        $orders = SbOrder::query()
            ->activeSold()
            ->where(function ($query) use ($ids, $numericIds): void {
                $query->whereIn('listing_id', $ids);
                if ($numericIds !== []) {
                    $query->orWhereIn('ticket_id', array_map(intval(...), $numericIds));
                }
            })
            ->get(['listing_id', 'ticket_id', 'quantity']);

        $sold = array_fill_keys($ids, 0);

        foreach ($orders as $order) {
            $key = $this->resolveOrderListingKey($order, $ids);
            if ($key === null) {
                continue;
            }
            $sold[$key] = ($sold[$key] ?? 0) + max(0, (int) ($order->quantity ?? 0));
        }

        return $sold;
    }

    /**
     * After bookings sync, re-push affected published listings so SB quantity
     * reflects stock − sold (or per-split remaining).
     *
     * @param  list<string|null>  $listingIds
     * @return array{queued: int, tickets: list<int>}
     */
    public function queueStockReconcileForListingIds(array $listingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $listingIds,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return ['queued' => 0, 'tickets' => []];
        }

        $ticketIds = [];

        $mapped = ExternalListingMapping::query()
            ->whereIn('seller_listing_id', $ids)
            ->pluck('xs2_ticket_id');
        foreach ($mapped as $ticketId) {
            $ticketIds[(int) $ticketId] = true;
        }

        if (Schema::hasTable('listing_splits')) {
            $splitMasters = ListingSplit::query()
                ->whereIn('seatsbroker_listing_id', $ids)
                ->pluck('master_listing_id');
            foreach ($splitMasters as $ticketId) {
                $ticketIds[(int) $ticketId] = true;
            }
        }

        $queued = [];
        foreach (array_keys($ticketIds) as $ticketId) {
            $ticket = Xs2Ticket::query()->find($ticketId);
            if ($ticket === null) {
                continue;
            }

            if ($ticket->split_enabled) {
                SyncSplitListings::dispatch($ticketId);
            } else {
                PushXs2TicketToSellerApi::dispatch($ticketId);
            }
            $queued[] = $ticketId;
        }

        if ($queued !== []) {
            Log::info('Queued listing stock reconcile after SB booking sync', [
                'ticket_ids' => $queued,
                'listing_ids' => $ids,
            ]);
        }

        return ['queued' => count($queued), 'tickets' => $queued];
    }

    /**
     * @return list<string>
     */
    public function marketplaceListingIdsForTicket(Xs2Ticket $ticket): array
    {
        $ids = [];
        $sellerId = $ticket->listingMapping?->seller_listing_id;
        if (is_string($sellerId) && $sellerId !== '') {
            $ids[] = $sellerId;
        }

        foreach ($ticket->listingSplits ?? [] as $split) {
            if (is_string($split->seatsbroker_listing_id)
                && $split->seatsbroker_listing_id !== '') {
                $ids[] = $split->seatsbroker_listing_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Prefer ticket_id (matches create response / seller_listing_id), then listing_id.
     *
     * @param  list<string>  $knownIds
     */
    private function resolveOrderListingKey(SbOrder $order, array $knownIds): ?string
    {
        $known = array_fill_keys($knownIds, true);

        if ($order->ticket_id !== null) {
            $fromTicket = (string) $order->ticket_id;
            if (isset($known[$fromTicket])) {
                return $fromTicket;
            }
        }

        if (is_string($order->listing_id) && $order->listing_id !== '' && isset($known[$order->listing_id])) {
            return $order->listing_id;
        }

        return null;
    }
}
