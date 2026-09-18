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

    public const SYNC_RESOURCE_FAILED_RETRY = 'sb-listings:failed-publish-retry';

    private const SCAN_CHUNK_SIZE = 50;

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
        bool $failedOnly = false,
    ): array {
        $syncResource = $failedOnly ? self::SYNC_RESOURCE_FAILED_RETRY : self::SYNC_RESOURCE;

        $summary = [
            'eligible_tickets' => 0,
            'needs_publish' => 0,
            'queued' => 0,
            'deferred' => 0,
            'published_inline' => 0,
            'skipped' => 0,
            'failed' => 0,
            'skip_reasons' => [
                'event_not_sellable' => 0,
                'mapping_not_ready' => 0,
                'validation_failed' => 0,
                'already_published_on_sb' => 0,
                'publish_failed' => 0,
            ],
            'dry_run' => $dryRun,
            'errors' => [],
        ];

        try {
            $this->markRunning($syncResource);

            $dispatchSpacingSeconds = max(1, (int) config('xs2.sb_new_listing_publish.dispatch_interval_seconds', 2));
            $firstDispatchAt = now();
            $queueIndex = 0;
            $stopScanning = false;

            $this->eligibleTickets($ticketId, $failedOnly)
                ->orderBy('id')
                ->chunkById(self::SCAN_CHUNK_SIZE, function ($tickets) use (
                    &$summary,
                    &$queueIndex,
                    &$stopScanning,
                    $inline,
                    $dryRun,
                    $maxDispatch,
                    $manualPublish,
                    $failedOnly,
                    $dispatchSpacingSeconds,
                    $firstDispatchAt,
                ): bool {
                    foreach ($tickets as $ticket) {
                        if ($stopScanning) {
                            $summary['deferred']++;

                            continue;
                        }

                        $this->processTicket(
                            $ticket,
                            $summary,
                            $queueIndex,
                            $stopScanning,
                            $inline,
                            $dryRun,
                            $maxDispatch,
                            $manualPublish,
                            $failedOnly,
                            $dispatchSpacingSeconds,
                            $firstDispatchAt,
                        );
                    }

                    return ! $stopScanning;
                });

            return $this->finalizeRun($summary, syncResource: $syncResource);
        } catch (Throwable $exception) {
            return $this->finalizeRun($summary, $exception->getMessage(), syncResource: $syncResource);
        }
    }

    /**
     * Queue a first-time Seller API publish as soon as the event is mapped and
     * the listing is locally complete (stock, price, XS2 category name).
     * Does not wait for xs2:publish-new-sb-listings.
     */
    public function dispatchIfReady(Xs2Ticket $ticket, bool $manualPublish = false): bool
    {
        if (! (bool) config('xs2.sb_new_listing_publish.enabled', true)
            || ! (bool) config('services.seller_api.enabled', true)) {
            return false;
        }

        $ticket->loadMissing(['xs2Event.mapping', 'mappingState', 'listingMapping', 'listingSplits']);

        if (! ($ticket->xs2Event?->isSellable() ?? false)) {
            return false;
        }

        if ($ticket->ticket_status !== 'available' || (int) $ticket->stock <= 0) {
            return false;
        }

        $mapping = $ticket->xs2Event?->mapping;
        if (! $mapping || ! in_array($mapping->status, ['mapped', 'created'], true) || ! $mapping->m_id) {
            return false;
        }

        $state = Schema::hasTable('xs2_ticket_mapping_states')
            ? $this->mappingStatuses->resolveIfStale($ticket)
            : null;

        if (! $this->mappingStatuses->canAutoPublish($ticket, $state?->mapping_status)) {
            return false;
        }

        if ($this->hasPublishFailure($ticket) || $this->isPublishedOnSb($ticket)) {
            return false;
        }

        $readiness = $this->readiness->assess($ticket, strictPublish: $manualPublish);
        if (! $readiness['ready']) {
            return false;
        }

        try {
            $this->queuePublish($ticket, inline: false, manualPublish: $manualPublish, delayUntil: null);

            return true;
        } catch (Throwable $exception) {
            $message = $this->safeMessage($exception);
            $this->mappingStatuses->markPublishFailed($ticket, $message);
            Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                'Seats Broker listing could not be queued immediately after mapping.',
                [
                    'ticket_id' => $ticket->id,
                    'external_ticket_id' => $ticket->external_ticket_id,
                    'error' => $message,
                ],
            );

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function processTicket(
        Xs2Ticket $ticket,
        array &$summary,
        int &$queueIndex,
        bool &$stopScanning,
        bool $inline,
        bool $dryRun,
        ?int $maxDispatch,
        bool $manualPublish,
        bool $failedOnly,
        int $dispatchSpacingSeconds,
        \Illuminate\Support\Carbon $firstDispatchAt,
    ): void {
        try {
            $this->processEligibleTicket(
                $ticket,
                $summary,
                $queueIndex,
                $stopScanning,
                $inline,
                $dryRun,
                $maxDispatch,
                $manualPublish,
                $failedOnly,
                $dispatchSpacingSeconds,
                $firstDispatchAt,
            );
        } catch (Throwable $exception) {
            $message = $this->safeMessage($exception);
            $summary['failed']++;
            $summary['errors'][] = ($ticket->external_ticket_id ?? (string) $ticket->id).': '.$message;
            Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                'Seats Broker new listing publish skipped a ticket after an unexpected error.',
                [
                    'ticket_id' => $ticket->id,
                    'external_ticket_id' => $ticket->external_ticket_id,
                    'error' => $message,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function processEligibleTicket(
        Xs2Ticket $ticket,
        array &$summary,
        int &$queueIndex,
        bool &$stopScanning,
        bool $inline,
        bool $dryRun,
        ?int $maxDispatch,
        bool $manualPublish,
        bool $failedOnly,
        int $dispatchSpacingSeconds,
        \Illuminate\Support\Carbon $firstDispatchAt,
    ): void {
        $summary['eligible_tickets']++;

        if (! ($ticket->xs2Event?->isSellable() ?? false)) {
            $summary['skipped']++;
            $summary['skip_reasons']['event_not_sellable']++;

            return;
        }

        $state = Schema::hasTable('xs2_ticket_mapping_states')
            ? $this->mappingStatuses->resolveIfStale($ticket)
            : null;

        $mappingStatus = $state?->mapping_status;
        $mappingAllowed = $this->mappingStatuses->canAutoPublish($ticket, $mappingStatus);

        if (! $mappingAllowed) {
            $summary['skipped']++;
            $summary['skip_reasons']['mapping_not_ready']++;

            return;
        }

        if ($failedOnly) {
            if (! $this->hasPublishFailure($ticket)) {
                $summary['skipped']++;
                $summary['skip_reasons']['publish_failed']++;

                return;
            }
        } elseif ($this->hasPublishFailure($ticket)) {
            $summary['skipped']++;
            $summary['skip_reasons']['publish_failed']++;

            return;
        }

        if ($this->isPublishedOnSb($ticket)) {
            $summary['skipped']++;
            $summary['skip_reasons']['already_published_on_sb']++;

            return;
        }

        if (! $dryRun && ! $inline && $maxDispatch !== null && $summary['queued'] >= $maxDispatch) {
            $summary['deferred']++;
            $stopScanning = true;

            return;
        }

        $readiness = $this->readiness->assess($ticket, strictPublish: $manualPublish);
        if (! $readiness['ready']) {
            $summary['skipped']++;
            $summary['skip_reasons']['validation_failed']++;

            return;
        }

        $summary['needs_publish']++;

        if ($dryRun) {
            return;
        }

        try {
            $delayUntil = $inline
                ? null
                : $firstDispatchAt->copy()->addSeconds($queueIndex * $dispatchSpacingSeconds);

            $this->queuePublish($ticket, $inline, $manualPublish, $delayUntil);

            if ($inline) {
                $summary['published_inline']++;
            } else {
                $summary['queued']++;
                $queueIndex++;
            }
        } catch (Throwable $exception) {
            $message = $this->safeMessage($exception);
            $this->mappingStatuses->markPublishFailed($ticket, $message);
            $summary['failed']++;
            $summary['errors'][] = $ticket->external_ticket_id.': '.$message;
            Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                'Seats Broker new listing publish could not be queued or completed.',
                [
                    'ticket_id' => $ticket->id,
                    'external_ticket_id' => $ticket->external_ticket_id,
                    'error' => $message,
                ],
            );
        }
    }

    private function queuePublish(
        Xs2Ticket $ticket,
        bool $inline,
        bool $manualPublish,
        ?\Illuminate\Support\Carbon $delayUntil,
    ): void {
        if ($this->splitRestock->canRepublishAfterRestock($ticket)) {
            $config = $this->splitRestock->resolveSplitConfig($ticket);
            if ($config === null) {
                throw new \RuntimeException('Split listing configuration is missing.');
            }

            if ($inline) {
                PublishSplitListings::dispatchSync($ticket->id, $config);
            } else {
                $pending = PublishSplitListings::dispatch($ticket->id, $config);
                if ($delayUntil !== null) {
                    $pending->delay($delayUntil);
                }
            }

            return;
        }

        if ($inline) {
            $this->publisher->publishTicket($ticket->id, strictPublish: $manualPublish, sync: true);

            return;
        }

        $this->publisher->publishTicket(
            $ticket->id,
            strictPublish: $manualPublish,
            sync: false,
            delayUntil: $delayUntil,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(bool $failedOnly = false): array
    {
        $syncResource = $failedOnly ? self::SYNC_RESOURCE_FAILED_RETRY : self::SYNC_RESOURCE;
        $eligible = $this->eligibleTickets(null, $failedOnly)->count();

        $state = Schema::hasTable('xs2_sync_states')
            ? Xs2SyncState::query()->where('resource', $syncResource)->first()
            : null;

        $metadata = is_array($state?->metadata) ? $state->metadata : [];
        // Dashboard reads must stay fast — full per-ticket publish scans run only in the cron itself.
        $pendingPublish = (int) ($metadata['needs_publish'] ?? 0);

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
    private function eligibleTickets(?int $ticketId = null, bool $failedOnly = false): Builder
    {
        $query = Xs2Ticket::query()
            ->with(['xs2Event.mapping', 'mappingState', 'listingMapping', 'listingSplits'])
            ->where('ticket_status', 'available')
            ->where('stock', '>', 0)
            ->whereHas('xs2Event', fn ($event) => $event->where('event_status', '!=', 'cancelled'))
            ->whereHas('xs2Event.mapping', fn ($mapping) => $mapping
                ->whereIn('status', ['mapped', 'created'])
                ->whereNotNull('m_id'))
            ->whereDoesntHave('listingMapping', function (Builder $mapping): void {
                $mapping->where('provider', 'xs2event')
                    ->whereNotNull('seller_listing_id')
                    ->where('status', 'active');
            })
            ->whereDoesntHave('listingSplits', function (Builder $splits): void {
                $splits->where('status', 'active')
                    ->whereNotNull('seatsbroker_listing_id');
            });

        if ($failedOnly) {
            $query->where(function (Builder $failed): void {
                $failed->where('sync_status', 'failed')
                    ->orWhere('split_sync_status', 'failed');
            });
        } else {
            $query->where(function (Builder $healthy): void {
                $healthy->where(fn (Builder $q) => $q->whereNull('sync_status')->orWhere('sync_status', '!=', 'failed'))
                    ->where(fn (Builder $q) => $q->whereNull('split_sync_status')->orWhere('split_sync_status', '!=', 'failed'));
            });
        }

        if ($ticketId !== null) {
            $query->whereKey($ticketId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function finalizeRun(
        array $summary,
        ?string $fatalError = null,
        string $syncResource = self::SYNC_RESOURCE,
    ): array {
        $errors = $summary['errors'] ?? [];
        if ($fatalError !== null) {
            $errors[] = $fatalError;
        }

        $cronFailed = $fatalError !== null;
        $ticketFailures = (int) ($summary['failed'] ?? 0);

        if (Schema::hasTable('xs2_sync_states')) {
            $state = Xs2SyncState::query()->firstOrCreate(['resource' => $syncResource]);
            $state->update([
                'status' => $cronFailed ? 'failed' : 'completed',
                'last_attempted_at' => now(),
                'last_successful_at' => $cronFailed ? $state->last_successful_at : now(),
                'last_error' => $cronFailed
                    ? mb_substr((string) $fatalError, 0, 5000)
                    : ($ticketFailures > 0
                        ? mb_substr(implode('; ', $errors), 0, 5000)
                        : null),
                'metadata' => [
                    'eligible_tickets' => (int) ($summary['eligible_tickets'] ?? 0),
                    'needs_publish' => (int) ($summary['needs_publish'] ?? 0),
                    'queued' => (int) ($summary['queued'] ?? 0),
                    'deferred' => (int) ($summary['deferred'] ?? 0),
                    'published_inline' => (int) ($summary['published_inline'] ?? 0),
                    'skipped' => (int) ($summary['skipped'] ?? 0),
                    'failed' => $ticketFailures,
                    'skip_reasons' => is_array($summary['skip_reasons'] ?? null)
                        ? $summary['skip_reasons']
                        : [],
                    'errors' => count($errors),
                ],
            ]);
        }

        $summary['errors'] = $errors;
        $summary['status'] = $cronFailed ? 'failed' : 'completed';
        $summary['completed_at'] = now()->toIso8601String();

        return $summary;
    }

    private function markRunning(string $syncResource): void
    {
        if (! Schema::hasTable('xs2_sync_states')) {
            return;
        }

        Xs2SyncState::query()->firstOrCreate(['resource' => $syncResource])->update([
            'status' => 'running',
            'last_attempted_at' => now(),
            'last_error' => null,
        ]);
    }

    private function hasPublishFailure(Xs2Ticket $ticket): bool
    {
        return $ticket->sync_status === 'failed'
            || $ticket->split_sync_status === 'failed';
    }

    private function safeMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 1000);
    }
}
