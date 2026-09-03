<?php

namespace App\Services\SellerApi;

use App\Jobs\PublishSplitListings;
use App\Models\ExternalListingMapping;
use App\Models\Xs2SyncState;
use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingRestockService;
use App\Services\Xs2\ListingPublishReadinessService;
use App\Services\Xs2\MappedListingPublishService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Publishes XS2 tickets that are eligible on mapped events but not yet on Seats Broker.
 */
class SbNewListingPublishService
{
    public const SYNC_RESOURCE = 'sb-listings:new-publish';

    public function __construct(
        private readonly MappedListingPublishService $publisher,
        private readonly Xs2TicketMappingStatusService $mappingStatuses,
        private readonly ListingPublishReadinessService $readiness,
        private readonly SplitListingRestockService $splitRestock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(
        bool $inline = false,
        ?int $ticketId = null,
        bool $dryRun = false,
        ?int $maxDispatch = null,
        bool $manualPublish = false,
    ): array {
        if (Schema::hasTable('xs2_sync_states')) {
            Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE])->update([
                'status' => 'running',
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);
        }

        $summary = [
            'eligible_tickets' => 0,
            'needs_publish' => 0,
            'queued' => 0,
            'deferred' => 0,
            'published_inline' => 0,
            'skipped' => 0,
            'skip_reasons' => [
                'event_not_sellable' => 0,
                'mapping_not_ready' => 0,
                'validation_failed' => 0,
                'already_published_on_sb' => 0,
            ],
            'dry_run' => $dryRun,
            'errors' => [],
        ];

        try {
            $tickets = $this->eligibleTickets($ticketId)->get();
            $summary['eligible_tickets'] = $tickets->count();

            $dispatchSpacingSeconds = max(1, (int) config('xs2.sb_new_listing_publish.dispatch_interval_seconds', 2));
            $firstDispatchAt = now();
            $queueIndex = 0;

            foreach ($tickets as $ticket) {
                if (! ($ticket->xs2Event?->isSellable() ?? false)) {
                    $summary['skipped']++;
                    $summary['skip_reasons']['event_not_sellable']++;

                    continue;
                }

                $state = Schema::hasTable('xs2_ticket_mapping_states')
                    ? $this->mappingStatuses->resolveIfStale($ticket)
                    : null;

                $mappingStatus = $state?->mapping_status;
                $mappingAllowed = $this->mappingStatuses->canAutoPublish($ticket, $mappingStatus);

                if (! $mappingAllowed) {
                    $summary['skipped']++;
                    $summary['skip_reasons']['mapping_not_ready']++;

                    continue;
                }

                $readiness = $this->readiness->assess($ticket, strictPublish: $manualPublish);
                if (! $readiness['ready']) {
                    $summary['skipped']++;
                    $summary['skip_reasons']['validation_failed']++;

                    continue;
                }

                if ($this->isPublishedOnSb($ticket)) {
                    $summary['skipped']++;
                    $summary['skip_reasons']['already_published_on_sb']++;

                    continue;
                }

                $summary['needs_publish']++;

                if ($dryRun) {
                    continue;
                }

                if (! $inline && $maxDispatch !== null && $summary['queued'] >= $maxDispatch) {
                    $summary['deferred']++;

                    continue;
                }

                try {
                    $delayUntil = $inline
                        ? null
                        : $firstDispatchAt->copy()->addSeconds($queueIndex * $dispatchSpacingSeconds);

                    if ($this->splitRestock->canRepublishAfterRestock($ticket)) {
                        $config = $this->splitRestock->resolveSplitConfig($ticket);
                        if ($config === null) {
                            $summary['skipped']++;
                            $summary['skip_reasons']['validation_failed']++;

                            continue;
                        }

                        if ($inline) {
                            PublishSplitListings::dispatchSync($ticket->id, $config);
                            $summary['published_inline']++;
                        } else {
                            $pending = PublishSplitListings::dispatch($ticket->id, $config);
                            if ($delayUntil !== null) {
                                $pending->delay($delayUntil);
                            }
                            $summary['queued']++;
                            $queueIndex++;
                        }

                        continue;
                    }

                    if ($inline) {
                        $this->publisher->publishTicket($ticket->id, strictPublish: $manualPublish, sync: true);
                        $summary['published_inline']++;
                    } else {
                        $this->publisher->publishTicket(
                            $ticket->id,
                            strictPublish: $manualPublish,
                            sync: false,
                            delayUntil: $delayUntil,
                        );
                        $summary['queued']++;
                        $queueIndex++;
                    }
                } catch (Throwable $exception) {
                    $summary['errors'][] = $ticket->external_ticket_id.': '.$this->safeMessage($exception);
                    Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                        'Seats Broker new listing publish could not be queued or completed.',
                        [
                            'ticket_id' => $ticket->id,
                            'external_ticket_id' => $ticket->external_ticket_id,
                            'error' => $this->safeMessage($exception),
                        ],
                    );
                }
            }

            return $this->finalizeRun($summary);
        } catch (Throwable $exception) {
            return $this->finalizeRun($summary, $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(): array
    {
        $eligible = $this->eligibleTickets(null)->count();
        $pendingPublish = 0;

        foreach ($this->eligibleTickets(null)->cursor() as $ticket) {
            if (! ($ticket->xs2Event?->isSellable() ?? false)) {
                continue;
            }

            $state = Schema::hasTable('xs2_ticket_mapping_states')
                ? $this->mappingStatuses->resolveIfStale($ticket)
                : null;

            if (! $this->mappingStatuses->canAutoPublish($ticket, $state?->mapping_status)) {
                continue;
            }

            $readiness = $this->readiness->assess($ticket);
            if (! $readiness['ready']) {
                continue;
            }

            if (! $this->isPublishedOnSb($ticket)) {
                $pendingPublish++;
            }
        }

        $state = Schema::hasTable('xs2_sync_states')
            ? Xs2SyncState::query()->where('resource', self::SYNC_RESOURCE)->first()
            : null;

        $rawStatus = $state?->status ?? 'never_run';

        return [
            'eligible_tickets' => $eligible,
            'pending_publish' => $pendingPublish,
            'status' => $rawStatus,
            'last_run_at' => $state?->last_attempted_at?->toIso8601String(),
            'last_successful_at' => $state?->last_successful_at?->toIso8601String(),
            'last_error' => filled($state?->last_error) ? (string) $state->last_error : null,
            'is_running' => $rawStatus === 'running',
            'metadata' => is_array($state?->metadata) ? $state->metadata : [],
        ];
    }

    public function isPublishedOnSb(Xs2Ticket $ticket): bool
    {
        if (ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->where('xs2_ticket_id', $ticket->id)
            ->whereNotNull('seller_listing_id')
            ->where('status', 'active')
            ->exists()) {
            return true;
        }

        return $ticket->listingSplits()
            ->where('status', 'active')
            ->whereNotNull('seatsbroker_listing_id')
            ->exists();
    }

    /** @return Builder<Xs2Ticket> */
    private function eligibleTickets(?int $ticketId = null): Builder
    {
        $query = Xs2Ticket::query()
            ->with(['xs2Event.mapping', 'mappingState', 'listingMapping', 'listingSplits'])
            ->where('ticket_status', 'available')
            ->where('stock', '>', 0)
            ->whereHas('xs2Event', fn ($event) => $event->where('event_status', '!=', 'cancelled'))
            ->whereHas('xs2Event.mapping', fn ($mapping) => $mapping
                ->whereIn('status', ['mapped', 'created'])
                ->whereNotNull('m_id'));

        if ($ticketId !== null) {
            $query->whereKey($ticketId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function finalizeRun(array $summary, ?string $fatalError = null): array
    {
        $errors = $summary['errors'] ?? [];
        if ($fatalError !== null) {
            $errors[] = $fatalError;
        }

        $failed = $errors !== [];

        if (Schema::hasTable('xs2_sync_states')) {
            $state = Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE]);
            $state->update([
                'status' => $failed ? 'failed' : 'completed',
                'last_attempted_at' => now(),
                'last_successful_at' => $failed ? $state->last_successful_at : now(),
                'last_error' => $failed ? mb_substr(implode('; ', $errors), 0, 5000) : null,
                'metadata' => [
                    'eligible_tickets' => (int) ($summary['eligible_tickets'] ?? 0),
                    'needs_publish' => (int) ($summary['needs_publish'] ?? 0),
                    'queued' => (int) ($summary['queued'] ?? 0),
                    'deferred' => (int) ($summary['deferred'] ?? 0),
                    'published_inline' => (int) ($summary['published_inline'] ?? 0),
                    'skipped' => (int) ($summary['skipped'] ?? 0),
                    'skip_reasons' => is_array($summary['skip_reasons'] ?? null)
                        ? $summary['skip_reasons']
                        : [],
                    'errors' => count($errors),
                ],
            ]);
        }

        $summary['errors'] = $errors;
        $summary['status'] = $failed ? 'failed' : 'completed';
        $summary['completed_at'] = now()->toIso8601String();

        return $summary;
    }

    private function safeMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 1000);
    }
}
