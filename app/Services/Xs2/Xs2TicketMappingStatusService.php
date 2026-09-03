<?php

namespace App\Services\Xs2;

use App\Models\EventMapping;
use App\Models\ExternalListingMapping;
use App\Models\Xs2Category;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2StadiumMapping;
use App\Models\ListingSplit;
use App\Models\Xs2Ticket;
use App\Models\Xs2TicketMappingState;
use App\Models\Xs2Venue;
use Illuminate\Support\Facades\Schema;

class Xs2TicketMappingStatusService
{
    /** @var list<string> */
    public const AUTO_PUBLISH_STATUSES = ['ready_to_publish', 'published'];

    /** @var list<string> Statuses that can be bypassed when the ticket carries an XS2 category name. */
    public const DIRECT_CATEGORY_BYPASS_STATUSES = ['pending_category_mapping', 'pending_stadium_mapping'];

    /** @var list<string> */
    public const MANUAL_PUBLISH_STATUSES = ['ready_to_publish', 'published', 'pending_category_mapping', 'pending_stadium_mapping'];

    public function autoPublishStatuses(): array
    {
        return self::AUTO_PUBLISH_STATUSES;
    }

    public function manualPublishStatuses(): array
    {
        return self::MANUAL_PUBLISH_STATUSES;
    }

    public function isAutoPublishable(?string $status): bool
    {
        return in_array($status, self::AUTO_PUBLISH_STATUSES, true);
    }

    public function isManualPublishable(?string $status): bool
    {
        return in_array($status, self::MANUAL_PUBLISH_STATUSES, true);
    }

    public function usesCategoryNameFallback(?string $status): bool
    {
        return in_array($status, self::DIRECT_CATEGORY_BYPASS_STATUSES, true);
    }

    public function hasCategoryNameForPublish(Xs2Ticket $ticket): bool
    {
        return filled(trim((string) ($ticket->category_name ?? '')));
    }

    /**
     * Auto-publish paths (inventory sync, publish-mapped-listings) normally
     * require confirmed category mapping. When mapping is still pending but
     * the ticket carries an XS2 category name, push directly using that name
     * as category_name — no dropdown or stadium-detail mapping required.
     */
    public function canAutoPublish(Xs2Ticket $ticket, ?string $status = null): bool
    {
        $status ??= $ticket->mappingState?->mapping_status;

        if ($this->isAutoPublishable($status)) {
            return true;
        }

        return in_array($status, self::DIRECT_CATEGORY_BYPASS_STATUSES, true)
            && $this->hasCategoryNameForPublish($ticket);
    }

    /**
     * Stored ticket mapping rows can lag behind confirmed event/stadium mappings.
     * Recompute and persist when the snapshot would block manual publish incorrectly.
     */
    public function resolveIfStale(Xs2Ticket $ticket): Xs2TicketMappingState
    {
        if ($this->isStale($ticket)) {
            return $this->resolve($ticket);
        }

        $stored = $ticket->mappingState?->mapping_status;
        if ($stored !== 'published' && $this->hasSellerListing($ticket)) {
            return $this->resolve($ticket);
        }

        return $ticket->mappingState ?? $this->resolve($ticket);
    }

    public function isStale(Xs2Ticket $ticket): bool
    {
        $stored = $ticket->mappingState?->mapping_status;
        if ($stored === null || $this->isManualPublishable($stored)) {
            return false;
        }

        if ($stored === 'unsupported_category') {
            return false;
        }

        $ticket->loadMissing(['xs2Event.mapping', 'xs2Event.venue.stadiumMapping']);
        $eventMapping = $ticket->xs2Event?->mapping;
        $stadiumMapping = $ticket->xs2Event?->venue?->stadiumMapping;
        $eventReady = $eventMapping
            && in_array($eventMapping->status, ['mapped', 'created'], true)
            && $eventMapping->m_id;
        $stadiumReady = $stadiumMapping
            && $stadiumMapping->status === 'mapped'
            && $stadiumMapping->stadium_id;

        if ($stored === 'pending_event_mapping' && $eventReady) {
            return true;
        }

        if ($stored === 'pending_stadium_mapping' && $eventReady && $stadiumReady) {
            return true;
        }

        return false;
    }

    public function manualPublishStatus(Xs2Ticket $ticket): string
    {
        $stored = $ticket->mappingState?->mapping_status;
        if ($stored !== null && ! $this->isStale($ticket)) {
            return $stored;
        }

        return $this->previewStatus($ticket);
    }

    public function previewStatus(Xs2Ticket $ticket): string
    {
        $ticket->loadMissing('xs2Event.mapping');
        $event = $ticket->xs2Event;
        $eventMapping = $event?->mapping;
        $venueId = (string) (data_get($ticket->raw_payload, 'venue_id') ?? $event?->venue_id ?? '');
        $venue = $venueId !== '' ? Xs2Venue::query()->where('external_venue_id', $venueId)->first() : null;
        $stadiumMapping = $venue?->stadiumMapping;
        $category = $this->categoryFor($ticket);
        $categoryMapping = $category?->mapping;

        return $this->status($eventMapping, $stadiumMapping, $categoryMapping);
    }

    public function resolve(Xs2Ticket $ticket): Xs2TicketMappingState
    {
        $ticket->loadMissing('xs2Event.mapping');
        $event = $ticket->xs2Event;
        $eventMapping = $event?->mapping;
        $venueId = (string) (data_get($ticket->raw_payload, 'venue_id') ?? $event?->venue_id ?? '');
        $venue = $venueId !== '' ? Xs2Venue::query()->where('external_venue_id', $venueId)->first() : null;
        $stadiumMapping = $venue?->stadiumMapping;
        $category = $this->categoryFor($ticket);
        $categoryMapping = $category?->mapping;

        $computedStatus = $this->status($eventMapping, $stadiumMapping, $categoryMapping);
        if ($this->shouldPreservePublishedListing($ticket, $computedStatus, $categoryMapping)) {
            $computedStatus = 'published';
        }

        $state = Xs2TicketMappingState::query()->firstOrNew(['xs2_ticket_id' => $ticket->id]);
        $state->fill([
            'event_mapping_id' => $eventMapping?->id,
            'xs2_venue_id' => $venue?->id,
            'xs2_category_id' => $category?->id,
            'xs2_stadium_mapping_id' => $stadiumMapping?->id,
            'xs2_category_mapping_id' => $categoryMapping?->id,
            'mapping_status' => $computedStatus,
            'mapping_error' => $this->error($eventMapping, $stadiumMapping, $categoryMapping),
            'last_resolved_at' => now(),
        ]);
        $state->save();

        return $state;
    }

    /**
     * Manual publishes can succeed while category mapping is still pending.
     * Inventory sync re-resolves mapping state on every ticket — keep the
     * published snapshot while an SB listing id exists unless the category
     * mapping was explicitly ignored.
     */
    private function shouldPreservePublishedListing(
        Xs2Ticket $ticket,
        string $computedStatus,
        ?Xs2CategoryMapping $category,
    ): bool {
        if ($category?->status === 'ignored') {
            return false;
        }

        return $this->hasSellerListing($ticket);
    }

    private function hasSellerListing(Xs2Ticket $ticket): bool
    {
        if (ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->where('xs2_ticket_id', $ticket->id)
            ->whereNotNull('seller_listing_id')
            ->exists()) {
            return true;
        }

        if (! Schema::hasTable('listing_splits')) {
            return false;
        }

        return ListingSplit::query()
            ->where('master_listing_id', $ticket->id)
            ->where('status', 'active')
            ->whereNotNull('seatsbroker_listing_id')
            ->exists();
    }

    private function categoryFor(Xs2Ticket $ticket): ?Xs2Category
    {
        $externalCategoryId = (string) ($ticket->category_id ?? data_get($ticket->raw_payload, 'category_id') ?? '');
        if ($externalCategoryId === '' || ! $ticket->xs2_event_id) {
            return null;
        }

        return Xs2Category::query()
            ->where('xs2_event_id', $ticket->xs2_event_id)
            ->where('external_category_id', $externalCategoryId)
            ->first();
    }

    private function status(?EventMapping $event, ?Xs2StadiumMapping $stadium, ?Xs2CategoryMapping $category): string
    {
        if (! $event || ! in_array($event->status, ['mapped', 'created'], true) || ! $event->m_id) {
            return 'pending_event_mapping';
        }

        if (! $stadium || $stadium->status !== 'mapped' || ! $stadium->stadium_id) {
            return 'pending_stadium_mapping';
        }

        if ($category?->status === 'unsupported') {
            return 'unsupported_category';
        }

        if (! $category || ! $this->categoryBelongsToStadium($category, $stadium)
            || $category->status !== 'mapped'
            || (config('xs2.mapping.require_stadium_detail', true) && ! $this->hasDetails($category))) {
            return 'pending_category_mapping';
        }

        return 'ready_to_publish';
    }

    private function error(?EventMapping $event, ?Xs2StadiumMapping $stadium, ?Xs2CategoryMapping $category): ?string
    {
        if (! $event || ! in_array($event->status, ['mapped', 'created'], true) || ! $event->m_id) {
            return 'A local event mapping is required before publishing.';
        }
        if (! $stadium || $stadium->status !== 'mapped' || ! $stadium->stadium_id) {
            return $stadium?->mapping_error ?? 'A confirmed stadium mapping is required before publishing.';
        }
        if ($category?->status === 'unsupported') {
            return $category->mapping_error ?? 'This XS2 category is not supported for listing publication.';
        }
        if (! $category || ! $this->categoryBelongsToStadium($category, $stadium)) {
            return 'A stadium-detail mapping for the currently selected stadium is required before publishing.';
        }
        if ($category->status !== 'mapped') {
            return $category?->mapping_error ?? 'A confirmed stadium-detail mapping is required before publishing.';
        }

        return null;
    }

    private function categoryBelongsToStadium(Xs2CategoryMapping $category, Xs2StadiumMapping $stadium): bool
    {
        return (int) $category->xs2_stadium_mapping_id === (int) $stadium->id
            && $this->sameNullableId($category->stadium_id, $stadium->stadium_id);
    }

    private function hasDetails(Xs2CategoryMapping $category): bool
    {
        return $category->relationLoaded('details')
            ? $category->details->isNotEmpty()
            : $category->details()->exists();
    }

    private function sameNullableId(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }
}
