<?php

namespace App\Services\Xs2;

use App\Jobs\DisableSellerListing;
use App\Models\EventMapping;
use App\Models\Xs2Ticket;
use App\Services\Xs2\MappedListingPublishService;

class Xs2TicketSyncService
{
    public function __construct(
        private Xs2Client $client,
        private Xs2TicketNormalizer $normalizer,
        private MappedListingPublishService $publisher,
    ) {}

    public function sync(EventMapping $mapping, bool $full = false): array
    {
        if (! in_array($mapping->status, ['mapped', 'created'], true) || ! $mapping->m_id) {
            throw new \RuntimeException('A local mapped event is required.');
        } $event = $mapping->xs2Event;
        if (! $event->isSellable()) {
            $tickets = Xs2Ticket::query()->where('xs2_event_id', $event->id)->get();
            foreach ($tickets as $ticket) {
                DisableSellerListing::dispatch($ticket->id);
            }

            return ['fetched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'disabled' => $tickets->count()];
        }
        $started = now();
        $payloads = $full ? $this->client->getTicketsForEvent($event->external_event_id) : $this->client->getIncrementalTicketsForEvent($event->external_event_id, ($event->tickets_last_incremental_sync_at ?? now()->subMinutes(config('services.xs2.incremental_sync_interval_minutes')))->subMinutes(config('services.xs2.sync_overlap_minutes')));
        $seen = [];
        $summary = ['fetched' => count($payloads), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'disabled' => 0];
        foreach ($payloads as $payload) {
            $data = $this->normalizer->normalize($payload);
            $hash = hash('sha256', json_encode($this->stable($data)));
            $seen[] = $data['external_ticket_id'];
            $ticket = Xs2Ticket::firstOrNew(['external_ticket_id' => $data['external_ticket_id']]);
            if ($ticket->exists && $ticket->external_updated_at && $data['external_updated_at'] && $ticket->external_updated_at->gt($data['external_updated_at'])) {
                continue;
            }$new = ! $ticket->exists;
            $changed = $new || $ticket->payload_hash !== $hash;
            $needsSellerSync = $changed || $ticket->sync_status !== 'synced';
            $ticket->fill($data + ['xs2_event_id' => $event->id, 'last_seen_at' => now(), 'last_synced_at' => now(), 'payload_hash' => $hash, 'sync_status' => 'pending', 'sync_error' => null]);
            $ticket->save();
            $summary[$new ? 'created' : ($changed ? 'updated' : 'unchanged')]++;
            if ($needsSellerSync) {
                if ($event->isSellable() && $ticket->ticket_status === 'available' && $ticket->stock > 0) {
                    $this->publisher->publishTicket($ticket->id);
                } else {
                    DisableSellerListing::dispatch($ticket->id);
                    $summary['disabled']++;
                }
            }
        } if ($full) {
            $missing = Xs2Ticket::where('xs2_event_id', $event->id)->whereNotIn('external_ticket_id', $seen)->get();
            foreach ($missing as $ticket) {
                $ticket->update(['stock' => 0, 'ticket_status' => 'unavailable', 'sync_status' => 'pending']);
                DisableSellerListing::dispatch($ticket->id);
                $summary['disabled']++;
            }
        } $event->update([$full ? 'tickets_last_full_sync_at' : 'tickets_last_incremental_sync_at' => now(), 'tickets_next_sync_at' => now()->addMinutes($full ? config('services.xs2.full_sync_interval_minutes') : config('services.xs2.incremental_sync_interval_minutes')), 'tickets_sync_status' => 'completed', 'tickets_sync_error' => null]);

        return $summary;
    }

    private function stable(array $data): array
    {
        unset($data['raw_payload'],$data['external_created_at'],$data['external_updated_at']);
        ksort($data);

        return $data;
    }
}
