<?php

namespace App\Services\SplitListings;

use App\Jobs\SyncSplitListings;
use App\Models\ListingSplit;
use App\Models\Xs2SyncState;
use App\Models\Xs2Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Scheduled reconcile: keep Seats Broker split sublisting quantities aligned with XS2 stock.
 */
class SplitListingQuantitySyncService
{
    public const SYNC_RESOURCE = 'split-listings:quantities';

    public function __construct(
        private readonly SplitListingService $splits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(
        bool $inline = false,
        ?int $ticketId = null,
        bool $force = false,
        bool $manageState = true,
        ?int $maxDispatch = null,
    ): array {
        if (! Schema::hasTable('listing_splits')) {
            return $this->finalizeRun([
                'eligible_tickets' => 0,
                'needs_sync' => 0,
                'queued' => 0,
                'synced_inline' => 0,
                'skipped' => 0,
                'errors' => [],
            ], 'listing_splits table is not available.', $manageState);
        }

        if ($manageState) {
            $state = Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE]);
            $state->update([
                'status' => 'running',
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);
        }

        $summary = [
            'eligible_tickets' => 0,
            'needs_sync' => 0,
            'queued' => 0,
            'synced_inline' => 0,
            'skipped' => 0,
            'deferred' => 0,
            'errors' => [],
        ];

        try {
            $tickets = $this->eligibleTickets($ticketId)->get();
            $summary['eligible_tickets'] = $tickets->count();

            $dispatchSpacingSeconds = max(1, (int) config('xs2.sb_listing_inventory.dispatch_interval_seconds', 2));
            $firstDispatchAt = now();
            $queueIndex = 0;

            foreach ($tickets as $ticket) {
                if (! $force && ! $this->ticketNeedsSync($ticket)) {
                    $summary['skipped']++;

                    continue;
                }

                $summary['needs_sync']++;

                if (! $inline && $maxDispatch !== null && $summary['queued'] >= $maxDispatch) {
                    $summary['deferred']++;

                    continue;
                }

                try {
                    if ($inline) {
                        $this->splits->syncListings($ticket);
                        $summary['synced_inline']++;
                    } else {
                        SyncSplitListings::dispatch($ticket->id)
                            ->delay($firstDispatchAt->copy()->addSeconds($queueIndex * $dispatchSpacingSeconds));
                        $summary['queued']++;
                        $queueIndex++;
                    }
                } catch (Throwable $exception) {
                    $summary['errors'][] = $ticket->external_ticket_id.': '.$this->safeMessage($exception);
                    Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                        'Split listing quantity sync could not be queued or completed.',
                        [
                            'ticket_id' => $ticket->id,
                            'external_ticket_id' => $ticket->external_ticket_id,
                            'error' => $this->safeMessage($exception),
                        ],
                    );
                }
            }

            return $this->finalizeRun($summary, null, $manageState);
        } catch (Throwable $exception) {
            return $this->finalizeRun($summary, $this->safeMessage($exception), $manageState);
        }
    }

    public function ticketNeedsSync(Xs2Ticket $ticket): bool
    {
        if (! $ticket->split_enabled) {
            return false;
        }

        $activeSplits = $this->activePublishedSplits($ticket);
        if ($activeSplits->isEmpty()) {
            return false;
        }

        $unpublishStockMax = max(0, (int) config('xs2.split_listings.unpublish_stock_max', 0));
        if ($unpublishStockMax > 0
            && $ticket->stock > 0
            && $ticket->stock <= $unpublishStockMax) {
            return true;
        }

        if ($ticket->stock <= 0
            || $ticket->ticket_status !== 'available'
            || ! ($ticket->xs2Event?->isSellable() ?? false)) {
            return true;
        }

        if (in_array($ticket->split_sync_status, ['failed', 'syncing', 'publishing'], true)) {
            return true;
        }

        try {
            $desired = $this->splits->preview($ticket)['listings'];
        } catch (ValidationException) {
            return true;
        }

        if (count($desired) !== $activeSplits->count()) {
            return true;
        }

        $byOrder = $activeSplits->keyBy('split_order');
        foreach ($desired as $plan) {
            $split = $byOrder->get($plan['split_order']);
            if (! $split instanceof ListingSplit) {
                return true;
            }

            if ((int) $split->quantity !== (int) $plan['quantity']) {
                return true;
            }

            if (round((float) $split->price, 2) !== round((float) $plan['price'], 2)) {
                return true;
            }

            if ($split->sync_status !== 'synced') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(): array
    {
        if (! Schema::hasTable('listing_splits')) {
            return [
                'eligible_tickets' => 0,
                'active_splits' => 0,
                'pending_sync' => 0,
                'failed_splits' => 0,
                'status' => 'never_run',
                'last_run_at' => null,
                'last_successful_at' => null,
                'last_error' => null,
                'is_running' => false,
            ];
        }

        $eligible = $this->eligibleTickets()->count();
        $activeSplits = (int) ListingSplit::query()
            ->where('status', 'active')
            ->whereNotNull('seatsbroker_listing_id')
            ->whereHas('masterListing', fn (Builder $ticket) => $ticket->where('split_enabled', true))
            ->count();
        $failedSplits = (int) ListingSplit::query()
            ->where('status', 'active')
            ->where('sync_status', 'failed')
            ->whereHas('masterListing', fn (Builder $ticket) => $ticket->where('split_enabled', true))
            ->count();

        $state = Schema::hasTable('xs2_sync_states')
            ? Xs2SyncState::query()->where('resource', self::SYNC_RESOURCE)->first()
            : null;

        $metadata = is_array($state?->metadata) ? $state->metadata : [];
        // Dashboard reads must stay fast — per-ticket split diff scans run only in the cron itself.
        $pendingSync = (int) ($metadata['needs_sync'] ?? 0);

        $rawStatus = $state?->status ?? 'never_run';

        return [
            'eligible_tickets' => $eligible,
            'active_splits' => $activeSplits,
            'pending_sync' => $pendingSync,
            'failed_splits' => $failedSplits,
            'status' => $rawStatus,
            'last_run_at' => $state?->last_attempted_at?->toIso8601String(),
            'last_successful_at' => $state?->last_successful_at?->toIso8601String(),
            'last_error' => filled($state?->last_error) ? (string) $state->last_error : null,
            'is_running' => $rawStatus === 'running',
            'metadata' => is_array($state?->metadata) ? $state->metadata : [],
        ];
    }

    /** @return Builder<Xs2Ticket> */
    private function eligibleTickets(?int $ticketId = null): Builder
    {
        $query = Xs2Ticket::query()
            ->with([
                'xs2Event.mapping',
                'listingSplits' => fn ($split) => $split
                    ->where('status', 'active')
                    ->whereNotNull('seatsbroker_listing_id')
                    ->orderBy('split_order'),
            ])
            ->where('split_enabled', true)
            ->whereHas('listingSplits', fn (Builder $split) => $split
                ->where('status', 'active')
                ->whereNotNull('seatsbroker_listing_id'));

        if ($ticketId !== null) {
            $query->whereKey($ticketId);
        }

        return $query;
    }

    /** @return Collection<int, ListingSplit> */
    private function activePublishedSplits(Xs2Ticket $ticket): Collection
    {
        $ticket->loadMissing([
            'listingSplits' => fn ($split) => $split
                ->where('status', 'active')
                ->whereNotNull('seatsbroker_listing_id')
                ->orderBy('split_order'),
        ]);

        return $ticket->listingSplits;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function finalizeRun(array $summary, ?string $fatalError = null, bool $manageState = true): array
    {
        $errors = $summary['errors'] ?? [];
        if ($fatalError !== null) {
            $errors[] = $fatalError;
        }

        $failed = $errors !== [];

        if ($manageState) {
            $state = Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE]);
            $metadata = [
                'eligible_tickets' => (int) ($summary['eligible_tickets'] ?? 0),
                'needs_sync' => (int) ($summary['needs_sync'] ?? 0),
                'queued' => (int) ($summary['queued'] ?? 0),
                'synced_inline' => (int) ($summary['synced_inline'] ?? 0),
                'skipped' => (int) ($summary['skipped'] ?? 0),
                'errors' => count($errors),
            ];

            $state->update([
                'status' => $failed ? 'failed' : 'completed',
                'last_attempted_at' => now(),
                'last_successful_at' => $failed ? $state->last_successful_at : now(),
                'last_error' => $failed ? mb_substr(implode('; ', $errors), 0, 5000) : null,
                'metadata' => $metadata,
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
