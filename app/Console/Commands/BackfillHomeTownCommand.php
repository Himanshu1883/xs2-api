<?php

namespace App\Console\Commands;

use App\Jobs\SyncSplitListings;
use App\Models\ListingSplit;
use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillHomeTownCommand extends Command
{
    protected $signature = 'xs2:backfill-home-town
                            {--sync : Run SplitListingService inline instead of queueing SyncSplitListings jobs}
                            {--dry-run : Report affected splits without syncing}';

    protected $description = 'Re-sync split listings whose last_request still has legacy numeric home_town (0 or 1) instead of a team name.';

    public function handle(SplitListingService $splits): int
    {
        if (! (bool) config('services.seller_api.enabled', true)) {
            $this->warn('Seller API integration is disabled (SELLER_API_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('listing_splits')) {
            $this->warn('listing_splits table is not available.');

            return self::SUCCESS;
        }

        $splits = ListingSplit::query()
            ->where('status', 'active')
            ->whereNotNull('seatsbroker_listing_id')
            ->get()
            ->filter(fn (ListingSplit $split): bool => $this->hasLegacyNumericHomeTown($split));

        $splitCount = $splits->count();
        $ticketIds = $splits->pluck('master_listing_id')->unique()->values();

        if ($splitCount === 0) {
            $this->info('No active split listings with legacy numeric home_town in last_request.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Dry run: would sync %d split listing(s) across %d master ticket(s).',
                $splitCount,
                $ticketIds->count(),
            ));

            return self::SUCCESS;
        }

        $synced = 0;
        $queued = 0;
        foreach ($ticketIds as $ticketId) {
            if ($this->option('sync')) {
                $ticket = Xs2Ticket::query()->with('xs2Event.mapping')->find($ticketId);
                if (! $ticket) {
                    continue;
                }
                $splits->syncListings($ticket);
                $synced++;
            } else {
                SyncSplitListings::dispatch((int) $ticketId);
                $queued++;
            }
        }

        if ($this->option('sync')) {
            $this->info(sprintf('Synced %d master ticket(s) covering %d split listing(s).', $synced, $splitCount));
        } else {
            $this->info(sprintf('Queued %d SyncSplitListings job(s) covering %d split listing(s).', $queued, $splitCount));
        }

        return self::SUCCESS;
    }

    private function hasLegacyNumericHomeTown(ListingSplit $split): bool
    {
        $value = data_get($split->last_request, 'home_town');

        return in_array($value, [0, 1, '0', '1'], true);
    }
}
