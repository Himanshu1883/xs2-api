<?php

namespace App\Services\Xs2;

use App\Models\Xs2Event;
use App\Models\Xs2EventInventorySyncState;
use App\Models\Xs2Ticket;
use Illuminate\Support\Facades\Log;

/**
 * Pull venue and ticket catalog rows from the XS2 sandbox API into local tables.
 *
 * Uses Xs2SandboxService credentials only — never Xs2Client / production sync.
 * Does not dispatch Seller API listing jobs; sandbox inventory is for admin QA.
 */
class Xs2SandboxInventorySyncService
{
    public function __construct(
        private readonly Xs2SandboxService $sandbox,
        private readonly Xs2VenueSyncService $venues,
        private readonly Xs2TicketNormalizer $normalizer,
        private readonly Xs2TicketMappingStatusService $mappingStates,
        private readonly Xs2AwayTeamContextService $awayTeamContext,
    ) {}

    /** @return array<string, int|string|list<string>|null> */
    public function sync(Xs2Event $event): array
    {
        if (! $this->sandbox->isConfigured()) {
            throw new \RuntimeException('XS2 sandbox credentials are not configured.');
        }

        $event = $event->loadMissing('mapping');
        $startedAt = now();
        $state = Xs2EventInventorySyncState::query()->firstOrCreate(['xs2_event_id' => $event->id]);
        $summary = [
            'event_id' => $event->id,
            'external_event_id' => $event->external_event_id,
            'venue_created' => 0,
            'venue_updated' => 0,
            'stadium_mapping_status' => null,
            'tickets_created' => 0,
            'tickets_updated' => 0,
            'tickets_unchanged' => 0,
            'tickets_total' => 0,
            'tickets_disabled' => 0,
            'errors' => [],
            'duration_ms' => 0,
        ];
        $state->update(['tickets_sync_status' => 'running', 'tickets_sync_error' => null]);

        try {
            $venueResult = null;
            try {
                $venueResult = $this->syncVenueForEvent($event);
                $summary['venue_created'] = (int) $venueResult['created'];
                $summary['venue_updated'] = (int) $venueResult['updated'];
                $summary['stadium_mapping_status'] = $venueResult['mapping']->status;
            } catch (\Throwable $exception) {
                $summary['errors'][] = 'venue: '.$this->safeMessage($exception);
                Log::channel(config('xs2.log_channel', 'stack'))->warning('XS2 sandbox venue sync failed.', [
                    'external_event_id' => $event->external_event_id,
                    'error_message' => $this->safeMessage($exception),
                ]);
            }

            $this->awayTeamContext->resolve($event);

            $seen = [];
            foreach ($this->sandbox->fetchAllTicketsForEvent($event->external_event_id) as $payload) {
                $result = $this->upsertTicket($event, $payload, $startedAt);
                $seen[] = $result['ticket']->external_ticket_id;
                $summary[$result['action']]++;
                $this->mappingStates->resolve($result['ticket']);
            }

            $summary['tickets_total'] = count($seen);
            $summary['tickets_disabled'] = $this->disableMissingTickets($event, $startedAt, $seen);

            $state->update([
                'tickets_last_full_sync_at' => now(),
                'tickets_next_sync_at' => null,
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

    /** @return array{venue:\App\Models\Xs2Venue,mapping:\App\Models\Xs2StadiumMapping,created:bool,updated:bool} */
    private function syncVenueForEvent(Xs2Event $event): array
    {
        $source = is_array($event->raw_payload) ? $event->raw_payload : [];
        $externalVenueId = trim((string) (data_get($source, 'venue_id') ?? $event->venue_id ?? ''));
        if ($externalVenueId === '') {
            throw new \InvalidArgumentException('XS2 event has no venue ID.');
        }

        $embedded = data_get($source, 'venue');
        $payload = is_array($embedded) ? $embedded : [];
        if (! $this->venuePayloadHasDetails($payload)) {
            $payload = $this->sandbox->fetchVenue($externalVenueId);
        }
        $payload['venue_id'] ??= $externalVenueId;

        return $this->venues->syncPayload($payload);
    }

    /** @param array<string,mixed> $payload @return array{ticket:Xs2Ticket,action:string} */
    private function upsertTicket(Xs2Event $event, array $payload, $seenAt): array
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
            'is_sandbox' => true,
            'last_seen_at' => $seenAt,
            'last_synced_at' => now(),
            'payload_hash' => $hash,
            'sync_status' => 'pending',
            'sync_error' => null,
        ]));
        $ticket->save();

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
            $count++;
        }

        return $count;
    }

    /** @param array<string,mixed> $payload */
    private function venuePayloadHasDetails(array $payload): bool
    {
        return trim((string) ($payload['venue_name'] ?? $payload['name'] ?? '')) !== '';
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function stable(array $attributes): array
    {
        unset($attributes['raw_payload'], $attributes['external_created_at'], $attributes['external_updated_at']);
        ksort($attributes);

        return $attributes;
    }

    private function safeMessage(\Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 1000);
    }
}
