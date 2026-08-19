<?php

namespace App\Services\Xs2;

use App\Jobs\SyncXs2CategoriesForEvent;
use App\Jobs\SyncXs2VenueForEvent;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;

class Xs2InventoryMappingSyncManagementService
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function queueVenues(): int
    {
        $events = $this->eligibleEvents()
            ->filter(fn (Xs2Event $event): bool => filled($event->venue_id))
            ->sortByDesc('updated_at')
            ->unique(fn (Xs2Event $event): string => (string) $event->venue_id)
            ->values();

        foreach ($events as $event) {
            $this->dispatcher->dispatch(new SyncXs2VenueForEvent($event->id));
        }

        return $events->count();
    }

    public function queueCategories(): int
    {
        $events = $this->eligibleEvents();

        foreach ($events as $event) {
            $this->dispatcher->dispatch(new SyncXs2CategoriesForEvent($event->id));
        }

        return $events->count();
    }

    /** @return Collection<int, Xs2Event> */
    private function eligibleEvents(): Collection
    {
        return EventMapping::query()
            ->with('xs2Event')
            ->whereIn('status', ['mapped', 'created'])
            ->whereNotNull('m_id')
            ->whereHas('xs2Event', fn ($event) => $event->where('date_start_local', '>=', now()))
            ->get()
            ->pluck('xs2Event')
            ->filter()
            ->unique('id')
            ->values();
    }
}
