<?php

namespace App\Services\Xs2;

use App\Jobs\DisableSellerListing;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\SellerApi\SellerApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SellerPublishResetService
{
    public function __construct(
        private readonly Xs2TicketMappingStatusService $mappingStates,
        private readonly SellerApiClient $seller,
    ) {}

    /**
     * @return array{remote_disabled:int,listings_reset:int,tickets_reset:int,mapping_states_resolved:int,skipped_remote:int}
     */
    public function reset(bool $disableRemote = true, bool $syncRemote = true, bool $dryRun = false): array
    {
        $summary = [
            'remote_disabled' => 0,
            'listings_reset' => 0,
            'tickets_reset' => 0,
            'mapping_states_resolved' => 0,
            'skipped_remote' => 0,
        ];

        $listingQuery = ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->whereNotNull('seller_listing_id')
            ->where(function ($query): void {
                $query->where('status', '!=', 'inactive')
                    ->orWhere('last_pushed_quantity', '>', 0);
            });

        $ticketIdsToDisable = (clone $listingQuery)->pluck('xs2_ticket_id')->all();

        if ($dryRun) {
            $summary['remote_disabled'] = count($ticketIdsToDisable);
            $summary['listings_reset'] = ExternalListingMapping::query()->where('provider', 'xs2event')->count();
            $summary['tickets_reset'] = Xs2Ticket::query()->count();
            $summary['mapping_states_resolved'] = Schema::hasTable('xs2_ticket_mapping_states')
                ? (int) Xs2TicketMappingState::query()->count()
                : 0;

            return $summary;
        }

        if ($disableRemote) {
            foreach ($ticketIdsToDisable as $ticketId) {
                if ($syncRemote) {
                    try {
                        (new DisableSellerListing((int) $ticketId))->handle($this->seller);
                        $summary['remote_disabled']++;
                    } catch (\Throwable) {
                        $summary['skipped_remote']++;
                    }
                } else {
                    DisableSellerListing::dispatch((int) $ticketId);
                    $summary['remote_disabled']++;
                }
            }
        }

        DB::transaction(function () use (&$summary): void {
            $summary['listings_reset'] = ExternalListingMapping::query()
                ->where('provider', 'xs2event')
                ->update([
                    'seller_listing_id' => null,
                    'status' => 'pending',
                    'last_payload_hash' => null,
                    'last_pushed_quantity' => 0,
                    'last_pushed_price' => null,
                    'last_request' => null,
                    'last_response' => null,
                    'last_error' => null,
                    'last_pushed_at' => null,
                    'disabled_at' => now(),
                ]);

            $summary['tickets_reset'] = Xs2Ticket::query()->update([
                'sync_status' => 'pending',
                'sync_error' => null,
            ]);
        });

        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            Xs2Ticket::query()->select('id')->orderBy('id')->chunkById(200, function ($tickets) use (&$summary): void {
                foreach ($tickets as $ticket) {
                    $full = Xs2Ticket::query()->find($ticket->id);
                    if ($full) {
                        $this->mappingStates->resolve($full);
                        $summary['mapping_states_resolved']++;
                    }
                }
            });
        }

        return $summary;
    }
}
