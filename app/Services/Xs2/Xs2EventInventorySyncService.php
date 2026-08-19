<?php

namespace App\Services\Xs2;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Jobs\DeleteSplitListings;
use App\Jobs\DisableXs2SellerListing;
use App\Jobs\SyncXs2TicketGuestRequirements;
use App\Jobs\SyncSplitListings;
use App\Models\EventMapping;
use App\Models\Xs2Event;
use App\Models\Xs2EventInventorySyncState;
use App\Models\Xs2Ticket;
use Illuminate\Support\Facades\Log;

class Xs2EventInventorySyncService
{
    public function __construct(
        private readonly Xs2Client $client,
        private readonly Xs2VenueSyncService $venues,
        private readonly Xs2CategorySyncService $categories,
        private readonly Xs2TicketNormalizer $normalizer,
        private readonly Xs2TicketMappingStatusService $mappingStates,
        private readonly Xs2AwayTeamContextService $awayTeamContext,
        private readonly MappedListingPublishService $publisher,
    ) {}

    /** @return array<string,int|string|array<int,string>|null> */
    public function sync(EventMapping|Xs2Event|int|string $source, string $mode = 'incremental', string $scope = 'full'): array
    {
        $full = $mode === 'full';
        $ticketsOnly = $scope === 'tickets';
        $event = $this->eventFor($source);
        $state = Xs2EventInventorySyncState::query()->firstOrCreate(['xs2_event_id' => $event->id]);
        $startedAt = now();
        $summary = [
            'event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'venue_created' => 0,
            'venue_updated' => 0,
            'stadium_mapping_status' => null,
            'categories_created' => 0,
            'categories_updated' => 0,
            'categories_mapped' => 0,
            'categories_pending' => 0,
            'categories_unmatched' => 0,
            'tickets_created' => 0,
            'tickets_updated' => 0,
            'tickets_unchanged' => 0,
            'tickets_ready' => 0,
            'tickets_pending' => 0,
            'tickets_disabled' => 0,
            'listing_jobs_dispatched' => 0,
            'errors' => [],
            'duration_ms' => 0,
        ];
        $state->update(['tickets_sync_status' => 'running', 'tickets_sync_error' => null]);

        try {
            if (! $event->isSellable()) {
                $summary['tickets_disabled'] = $this->disableEventTickets($event);
                $state->update([
                    'tickets_next_sync_at' => null,
                    'tickets_sync_status' => 'completed',
                    'tickets_sync_error' => null,
                ]);

                return $summary;
            }

            $venueResult = null;
            if (! $ticketsOnly) {
                try {
                    $venueResult = $this->venues->syncForEvent($event);
                    $summary['venue_created'] = (int) $venueResult['created'];
                    $summary['venue_updated'] = (int) $venueResult['updated'];
                    $summary['stadium_mapping_status'] = $venueResult['mapping']->status;
                } catch (Xs2RateLimitException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    $summary['errors'][] = 'venue: '.$this->safeMessage($exception);
                    Log::channel(config('xs2.log_channel', 'stack'))->warning('XS2 venue synchronization could not be completed.', [
                        'provider' => 'xs2event',
                        'external_event_id' => $event->external_event_id,
                        'sync_mode' => $mode,
                        'error_class' => $exception::class,
                        'error_message' => $this->safeMessage($exception),
                    ]);
                }

                if (! $event->categories()->exists()) {
                    try {
                        $categorySummary = $this->categories->sync($event, $venueResult['mapping'] ?? null);
                        $summary['categories_created'] = $categorySummary['created'];
                        $summary['categories_updated'] = $categorySummary['updated'];
                        $summary['categories_mapped'] = $categorySummary['mapped'];
                        $summary['categories_pending'] = $categorySummary['pending'];
                        $summary['categories_unmatched'] = $categorySummary['unmatched'];
                    } catch (Xs2RateLimitException $exception) {
                        throw $exception;
                    } catch (\Throwable $exception) {
                        // Category failures must not block ticket persistence — the
                        // admin fetch path and scheduled sync still need listings_count.
                        $summary['errors'][] = 'categories: '.$this->safeMessage($exception);
                        Log::channel(config('xs2.log_channel', 'stack'))->warning('XS2 category synchronization could not be completed.', [
                            'provider' => 'xs2event',
                            'external_event_id' => $event->external_event_id,
                            'sync_mode' => $mode,
                            'error_class' => $exception::class,
                            'error_message' => $this->safeMessage($exception),
                        ]);
                    }
                }

                $this->awayTeamContext->resolve($event);
            }

            $payloads = $full
                ? $this->client->getTicketsForEvent($event->external_event_id)
                : $this->client->getIncrementalTicketsForEvent(
                    $event->external_event_id,
                    ($state->tickets_last_incremental_sync_at ?? now()->subMinutes(config('xs2.sync.incremental_interval_minutes', 15)))
                        ->copy()
                        ->subMinutes(config('xs2.sync_overlap_minutes', 5)),
                );
            $seen = [];
            foreach ($payloads as $payload) {
                $result = $this->upsertTicket($event, $payload, $startedAt, $full);
                $seen[] = $result['ticket']->external_ticket_id;
                $summary[$result['action']]++;

                $mappingState = $this->mappingStates->resolve($result['ticket']);
                if ($this->canPublishListing($mappingState->mapping_status, $result['ticket'])) {
                    if ($mappingState->mapping_status === 'ready_to_publish') {
                        $summary['tickets_ready']++;
                    }
                    if ($this->isAvailable($result['ticket'], $event)) {
                        if ($this->dispatchListingJob($result['ticket'], $event, $mode, $summary)) {
                            $summary['listing_jobs_dispatched']++;
                        }
                    } else {
                        $this->dispatchDisableJob($result['ticket'], $event, $mode, $summary);
                        $summary['tickets_disabled']++;
                    }
                } else {
                    $summary['tickets_pending']++;
                    $this->dispatchDisableJob($result['ticket'], $event, $mode, $summary);
                    $summary['tickets_disabled']++;
                }
            }

            if ($full) {
                $summary['tickets_disabled'] += $this->disableMissingTickets($event, $startedAt, $seen);
            }

            $state->update([
                $full ? 'tickets_last_full_sync_at' : 'tickets_last_incremental_sync_at' => now(),
                'tickets_next_sync_at' => now()->addMinutes($this->nextInterval($event, $full)),
                'tickets_sync_status' => 'completed',
                'tickets_sync_error' => null,
            ]);
        } catch (\Throwable $exception) {
            $state->update([
                'tickets_sync_status' => 'failed',
                'tickets_sync_error' => $this->safeMessage($exception),
            ]);

            throw $exception;
        } finally {
            $summary['duration_ms'] = (int) $startedAt->diffInMilliseconds(now());
        }

        return $summary;
    }

    /** @param array<string,mixed> $payload @return array{ticket:Xs2Ticket,action:string} */
    private function upsertTicket(Xs2Event $event, array $payload, $seenAt, bool $fullSync = false): array
    {
        $attributes = $this->normalizer->normalize($payload);
        $hash = hash('sha256', json_encode($this->stable($attributes), JSON_THROW_ON_ERROR));
        $ticket = Xs2Ticket::query()->firstOrNew(['external_ticket_id' => $attributes['external_ticket_id']]);
        $created = ! $ticket->exists;

        if ($ticket->exists && $ticket->external_updated_at && $attributes['external_updated_at']
            && $ticket->external_updated_at->gt($attributes['external_updated_at'])) {
            $ticket->update(['last_seen_at' => $seenAt, 'last_synced_at' => now()]);

            return ['ticket' => $ticket->fresh(), 'action' => 'tickets_unchanged'];
        }

        $changed = $created || $ticket->payload_hash !== $hash;
        $ticket->fill(array_merge($attributes, [
            'xs2_event_id' => $event->id,
            'last_seen_at' => $seenAt,
            'last_synced_at' => now(),
            'payload_hash' => $hash,
            'sync_status' => 'pending',
            'sync_error' => null,
        ]));
        $ticket->save();

        if ($created || $changed || ($fullSync && $ticket->guest_data_synced_at === null)) {
            SyncXs2TicketGuestRequirements::dispatch($ticket->id);
        }

        return [
            'ticket' => $ticket,
            'action' => $created ? 'tickets_created' : ($changed ? 'tickets_updated' : 'tickets_unchanged'),
        ];
    }

    /** @param list<string> $seen */
    private function disableMissingTickets(Xs2Event $event, $startedAt, array $seen): int
    {
        $query = Xs2Ticket::query()
            ->where('xs2_event_id', $event->id)
            ->where(function ($query) use ($startedAt): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $startedAt);
            });
        if ($seen !== []) {
            $query->whereNotIn('external_ticket_id', $seen);
        }

        $count = 0;
        foreach ($query->get() as $ticket) {
            if ($ticket->ticket_status !== 'unavailable' || (int) $ticket->stock !== 0) {
                $ticket->update([
                    'ticket_status' => 'unavailable',
                    'stock' => 0,
                    'sync_status' => 'pending',
                    'sync_error' => null,
                ]);
            }

            DisableXs2SellerListing::dispatch($ticket->id);
            $count++;
        }

        return $count;
    }

    private function disableEventTickets(Xs2Event $event): int
    {
        $ticketIds = $event->tickets()->pluck('id');
        foreach ($ticketIds as $ticketId) {
            DisableXs2SellerListing::dispatch((int) $ticketId);
        }

        return $ticketIds->count();
    }

    private function eventFor(EventMapping|Xs2Event|int|string $source): Xs2Event
    {
        if ($source instanceof Xs2Event) {
            return $source->loadMissing('mapping');
        }
        if ($source instanceof EventMapping) {
            return $source->loadMissing('xs2Event.mapping')->xs2Event;
        }
        if (is_int($source) || ctype_digit((string) $source)) {
            $mapping = EventMapping::query()->with('xs2Event.mapping')->find((int) $source);
            if ($mapping) {
                return $mapping->xs2Event;
            }
        }

        return Xs2Event::query()->with('mapping')->where('external_event_id', (string) $source)->firstOrFail();
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function stable(array $attributes): array
    {
        unset($attributes['raw_payload'], $attributes['external_created_at'], $attributes['external_updated_at']);
        ksort($attributes);

        return $attributes;
    }

    private function isAvailable(Xs2Ticket $ticket, Xs2Event $event): bool
    {
        return $event->isSellable()
            && $ticket->ticket_status === 'available'
            && (int) $ticket->stock > 0;
    }

    /** Initial publish (ready_to_publish) and stock/price reconcile (published). */
    private function canPublishListing(?string $mappingStatus, Xs2Ticket $ticket): bool
    {
        return $this->mappingStates->canAutoPublish($ticket, $mappingStatus);
    }

    private function nextInterval(Xs2Event $event, bool $full): int
    {
        $base = (int) config($full ? 'xs2.sync.full_interval_minutes' : 'xs2.sync.incremental_interval_minutes', $full ? 180 : 15);
        if (! $event->date_start_local) {
            return $base;
        }

        $hours = now()->diffInHours($event->date_start_local, false);
        if ($hours <= 24 && $hours >= 0) {
            return min($base, $full ? 30 : 10);
        }
        if ($hours <= 24 * 7 && $hours >= 0) {
            return min($base, $full ? 60 : 15);
        }
        if ($hours <= 24 * 30 && $hours >= 0) {
            return min($base, $full ? 120 : 30);
        }

        return $base;
    }

    private function safeMessage(\Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 1000);
    }

    /**
     * Under a synchronous queue connection, dispatch() runs the job inline
     * and its exceptions land here. One ticket's Seller API failure must not
     * abort the rest of this event's ticket loop or the whole scheduled sync.
     *
     * @param array<string,mixed> &$summary
     */
    private function dispatchListingJob(Xs2Ticket $ticket, Xs2Event $event, string $mode, array &$summary): bool
    {
        try {
            if ($ticket->split_enabled) {
                SyncSplitListings::dispatch($ticket->id);
            } else {
                $this->publisher->publishTicket($ticket->id, strictPublish: false, sync: false);
            }

            return true;
        } catch (Xs2RateLimitException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $summary['errors'][] = 'listing:'.$ticket->external_ticket_id.': '.$this->safeMessage($exception);
            Log::channel(config('xs2.log_channel', 'stack'))->warning('XS2 ticket listing publish could not be completed.', [
                'provider' => 'xs2event',
                'external_event_id' => $event->external_event_id,
                'external_ticket_id' => $ticket->external_ticket_id,
                'sync_mode' => $mode,
                'error_class' => $exception::class,
                'error_message' => $this->safeMessage($exception),
            ]);

            return false;
        }
    }

    /** @param array<string,mixed> &$summary */
    private function dispatchDisableJob(Xs2Ticket $ticket, Xs2Event $event, string $mode, array &$summary): void
    {
        try {
            if ($ticket->split_enabled) {
                DeleteSplitListings::dispatch($ticket->id);
            } else {
                DisableXs2SellerListing::dispatch($ticket->id);
            }
        } catch (Xs2RateLimitException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $summary['errors'][] = 'disable-listing:'.$ticket->external_ticket_id.': '.$this->safeMessage($exception);
            Log::channel(config('xs2.log_channel', 'stack'))->warning('XS2 ticket listing disable could not be completed.', [
                'provider' => 'xs2event',
                'external_event_id' => $event->external_event_id,
                'external_ticket_id' => $ticket->external_ticket_id,
                'sync_mode' => $mode,
                'error_class' => $exception::class,
                'error_message' => $this->safeMessage($exception),
            ]);
        }
    }
}
