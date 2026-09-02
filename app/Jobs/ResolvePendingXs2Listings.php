<?php

namespace App\Jobs;

use App\Models\Xs2Category;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Services\Mapping\StadiumCategoryMappingService;
use App\Services\SellerApi\SbNewListingPublishService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ResolvePendingXs2Listings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public string $mappingType, public int $mappingId)
    {
        // Prefer the mapping queue so reconciliation is not stuck behind the
        // large xs2-sync inventory backlog when workers listen to both.
        $this->onQueue(config('xs2.mapping_queue', config('xs2.queue', config('services.xs2.queue'))));
    }

    public function uniqueId(): string
    {
        return 'xs2-resolve-pending:'.$this->mappingType.':'.$this->mappingId;
    }

    /**
     * Re-evaluate ticket mapping states after a stadium/category mapping change.
     *
     * Category and stadium confirms run synchronously: the admin UI marks the
     * group Mapped immediately, and async jobs can lag for hours behind
     * inventory sync, leaving listings stuck on pending_category_mapping or
     * stale pending_stadium_mapping (venue detail then shows pending tickets
     * while the category page already shows Mapped).
     *
     * Stadium confirms also re-resolve category mappings (via handle →
     * ticketsForStadium). Those rows keep stadium_id null /
     * pending_stadium_mapping until resolve runs, and the category-mapping
     * admin dropdown is keyed off stadium_id — so a queued-only path leaves
     * "Not yet mapped" rows without SB options even though the parent venue
     * is already mapped.
     */
    public static function dispatchAfterMappingChange(string $mappingType, int $mappingId): void
    {
        $run = static function () use ($mappingType, $mappingId): void {
            if ($mappingType === 'category' || $mappingType === 'stadium') {
                static::dispatchSync($mappingType, $mappingId);

                return;
            }

            static::dispatch($mappingType, $mappingId);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);

            return;
        }

        $run();
    }

    /**
     * Propagate a confirmed (or cleared) stadium mapping onto every XS2
     * category at that venue so the admin category-mapping UI can load SB
     * seat/section options immediately.
     */
    public function syncStadiumCategoryMappings(StadiumCategoryMappingService $categories): void
    {
        $this->resolveCategoriesForStadium($categories);
    }

    public function handle(
        Xs2TicketMappingStatusService $states,
        StadiumCategoryMappingService $categories,
        ?SbNewListingPublishService $sbPublish = null,
    ): void {
        $sbPublish ??= app(SbNewListingPublishService::class);

        $tickets = match ($this->mappingType) {
            'stadium' => $this->ticketsForStadium($categories),
            'category' => $this->ticketsForCategory(),
            default => collect(),
        };

        foreach ($tickets as $ticket) {
            $this->reconcileTicket($ticket, $states, $sbPublish);
        }
    }

    private function reconcileTicket(
        Xs2Ticket $ticket,
        Xs2TicketMappingStatusService $states,
        SbNewListingPublishService $sbPublish,
    ): void {
        $state = $states->resolve($ticket);
        $eventSellable = $ticket->xs2Event?->isSellable() ?? false;
        $available = $ticket->ticket_status === 'available'
            && (int) $ticket->stock > 0
            && $eventSellable;

        if (! $eventSellable || ! $available) {
            if ($sbPublish->isPublishedOnSb($ticket)) {
                DisableSellerListing::dispatch($ticket->id);
            }

            return;
        }

        if ($this->shouldRetireListing($ticket, $state)) {
            if ($sbPublish->isPublishedOnSb($ticket)) {
                DisableSellerListing::dispatch($ticket->id);
            }

            return;
        }

        // Mapping resolution only updates local state — never auto-publishes or
        // retires listings for pending stadium/category mapping alone.
    }

    private function shouldRetireListing(Xs2Ticket $ticket, Xs2TicketMappingState $state): bool
    {
        if ($state->mapping_status === 'unsupported_category') {
            return true;
        }

        $state->loadMissing('categoryMapping');
        if ($state->categoryMapping?->status === 'ignored') {
            return true;
        }

        $liveCategoryMapping = Xs2CategoryMapping::query()
            ->whereHas('category', function ($query) use ($ticket): void {
                $query->where('xs2_event_id', $ticket->xs2_event_id)
                    ->where('external_category_id', (string) ($ticket->category_id ?? ''));
            })
            ->first();
        if ($liveCategoryMapping?->status === 'ignored') {
            return true;
        }

        $ticket->loadMissing('xs2Event.venue.stadiumMapping');
        if ($ticket->xs2Event?->venue?->stadiumMapping?->status === 'ignored') {
            return true;
        }

        return false;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Xs2Category>
     */
    private function resolveCategoriesForStadium(StadiumCategoryMappingService $categories)
    {
        $stadium = Xs2StadiumMapping::query()->with('venue')->find($this->mappingId);
        if (! $stadium || ! $stadium->venue) {
            return collect();
        }

        $categoryIds = Xs2Category::query()
            ->where(function ($query) use ($stadium): void {
                $query
                    ->whereHas('context', fn ($context) => $context->where('external_venue_id', $stadium->venue->external_venue_id))
                    ->orWhereHas('xs2Event', fn ($event) => $event->where('venue_id', $stadium->venue->external_venue_id));
            })
            ->pluck('id');
        $categoriesForVenue = Xs2Category::query()->whereIn('id', $categoryIds)->get();
        foreach ($categoriesForVenue as $category) {
            $categories->resolve($category, $stadium);
        }

        return $categoriesForVenue;
    }

    private function ticketsForStadium(StadiumCategoryMappingService $categories)
    {
        $stadium = Xs2StadiumMapping::query()->with('venue')->find($this->mappingId);
        if (! $stadium || ! $stadium->venue) {
            return collect();
        }

        // Re-evaluate categories now that their parent stadium is confirmed
        // (also done synchronously on confirm; safe to re-run here).
        $categoriesForVenue = $this->resolveCategoriesForStadium($categories);

        $eventIds = $categoriesForVenue
            ->pluck('xs2_event_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Xs2Ticket::query()
            ->with('xs2Event')
            ->where(function ($query) use ($stadium, $eventIds): void {
                $query->whereHas('xs2Event', fn ($event) => $event->where('venue_id', $stadium->venue->external_venue_id));
                if ($eventIds !== []) {
                    $query->orWhereIn('xs2_event_id', $eventIds);
                }
            })
            ->get();
    }

    private function ticketsForCategory()
    {
        $mapping = Xs2CategoryMapping::query()->with('category')->find($this->mappingId);
        if (! $mapping?->category) {
            return collect();
        }

        return Xs2Ticket::query()
            ->with('xs2Event')
            ->where('xs2_event_id', $mapping->category->xs2_event_id)
            ->where('category_id', $mapping->category->external_category_id)
            ->get();
    }
}
