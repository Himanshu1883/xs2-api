<?php

namespace App\Services\SellerApi;

use App\Jobs\DeleteXs2SellerListing;
use App\Jobs\DisableXs2SellerListing;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Models\ExternalListingMapping;
use App\Models\Xs2SyncState;
use App\Models\Xs2Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Scheduled reconcile: keep Seats Broker 1:1 master listing quantities aligned with XS2 stock.
 */
class MasterListingQuantitySyncService
{
    public const SYNC_RESOURCE = 'sb-listings:masters';

    public function __construct(
        private readonly ListingSalesService $listingSales,
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
        if (! Schema::hasTable('external_listing_mappings')) {
            return $this->finalizeRun([
                'eligible_tickets' => 0,
                'needs_sync' => 0,
                'queued' => 0,
                'synced_inline' => 0,
                'skipped' => 0,
                'errors' => [],
            ], 'external_listing_mappings table is not available.', $manageState);
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
                        $this->dispatchInline($ticket);
                        $summary['synced_inline']++;
                    } else {
                        $this->dispatchQueued($ticket)
                            ->delay($firstDispatchAt->copy()->addSeconds($queueIndex * $dispatchSpacingSeconds));
                        $summary['queued']++;
                        $queueIndex++;
                    }
                } catch (Throwable $exception) {
                    $summary['errors'][] = $ticket->external_ticket_id.': '.$this->safeMessage($exception);
                    Log::channel(config('services.seller_api.log_channel', 'stack'))->warning(
                        'Master listing quantity sync could not be queued or completed.',
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
        $mapping = $ticket->listingMapping;
        if (! $mapping instanceof ExternalListingMapping || ! filled($mapping->seller_listing_id)) {
            return false;
        }

        if ($this->shouldDisableOnMarketplace($ticket, $mapping)) {
            return $mapping->status === 'active' || (int) ($mapping->last_pushed_quantity ?? 0) > 0;
        }

        if (in_array($ticket->sync_status, ['failed', 'processing'], true)) {
            return true;
        }

        if ($mapping->status === 'failed') {
            return true;
        }

        $remaining = $this->listingSales->remainingQuantityForTicket($ticket);

        return (int) ($mapping->last_pushed_quantity ?? -1) !== $remaining
            || $mapping->status !== 'active';
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(): array
    {
        if (! Schema::hasTable('external_listing_mappings')) {
            return $this->emptyTelemetry();
        }

        $eligible = $this->eligibleTickets()->count();

        $state = Schema::hasTable('xs2_sync_states')
            ? Xs2SyncState::query()->where('resource', self::SYNC_RESOURCE)->first()
            : null;

        $metadata = is_array($state?->metadata) ? $state->metadata : [];
        // Dashboard reads must stay fast — per-ticket qty diff scans run only in the cron itself.
        $pendingSync = (int) ($metadata['needs_sync'] ?? 0);

        $activeListings = (int) ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->where('status', 'active')
            ->whereNotNull('seller_listing_id')
            ->whereHas('ticket', fn (Builder $ticket) => $ticket->where('split_enabled', false))
            ->count();

        $failedListings = (int) ExternalListingMapping::query()
            ->where('provider', 'xs2event')
            ->where('status', 'failed')
            ->whereHas('ticket', fn (Builder $ticket) => $ticket->where('split_enabled', false))
            ->count();

        $rawStatus = $state?->status ?? 'never_run';

        return [
            'eligible_tickets' => $eligible,
            'active_listings' => $activeListings,
            'pending_sync' => $pendingSync,
            'failed_listings' => $failedListings,
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
            ->with(['xs2Event.mapping', 'listingMapping'])
            ->where('split_enabled', false)
            ->whereHas('listingMapping', fn (Builder $mapping) => $mapping
                ->where('provider', 'xs2event')
                ->whereNotNull('seller_listing_id')
                ->whereIn('status', ['active', 'failed']))
            ->whereHas('xs2Event.mapping', fn (Builder $mapping) => $mapping
                ->whereIn('status', ['mapped', 'created'])
                ->whereNotNull('m_id'));

        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            $query->whereHas('mappingState', fn (Builder $state) => $state->where('mapping_status', 'published'));
        }

        if ($ticketId !== null) {
            $query->whereKey($ticketId);
        }

        return $query;
    }

    private function shouldDisableOnMarketplace(Xs2Ticket $ticket, ExternalListingMapping $mapping): bool
    {
        return ! ($ticket->xs2Event?->isSellable() ?? false)
            || $ticket->ticket_status !== 'available'
            || (int) $ticket->stock <= 0;
    }

    private function dispatchInline(Xs2Ticket $ticket): void
    {
        if ($this->shouldDisableOnMarketplace($ticket, $ticket->listingMapping)) {
            if ((int) $ticket->stock <= 0) {
                DeleteXs2SellerListing::dispatchSync($ticket->id);
            } else {
                DisableXs2SellerListing::dispatchSync($ticket->id);
            }

            return;
        }

        PushXs2TicketToSellerApi::dispatchSync($ticket->id);
    }

    private function dispatchQueued(Xs2Ticket $ticket): PendingDispatch
    {
        if ($this->shouldDisableOnMarketplace($ticket, $ticket->listingMapping)) {
            if ((int) $ticket->stock <= 0) {
                return DeleteXs2SellerListing::dispatch($ticket->id);
            }

            return DisableXs2SellerListing::dispatch($ticket->id);
        }

        return PushXs2TicketToSellerApi::dispatch($ticket->id);
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
            $state->update([
                'status' => $failed ? 'failed' : 'completed',
                'last_attempted_at' => now(),
                'last_successful_at' => $failed ? $state->last_successful_at : now(),
                'last_error' => $failed ? mb_substr(implode('; ', $errors), 0, 5000) : null,
                'metadata' => [
                    'eligible_tickets' => (int) ($summary['eligible_tickets'] ?? 0),
                    'needs_sync' => (int) ($summary['needs_sync'] ?? 0),
                    'queued' => (int) ($summary['queued'] ?? 0),
                    'synced_inline' => (int) ($summary['synced_inline'] ?? 0),
                    'skipped' => (int) ($summary['skipped'] ?? 0),
                    'errors' => count($errors),
                ],
            ]);
        }

        $summary['errors'] = $errors;
        $summary['status'] = $failed ? 'failed' : 'completed';
        $summary['completed_at'] = now()->toIso8601String();

        return $summary;
    }

    /** @return array<string, mixed> */
    private function emptyTelemetry(): array
    {
        return [
            'eligible_tickets' => 0,
            'active_listings' => 0,
            'pending_sync' => 0,
            'failed_listings' => 0,
            'status' => 'never_run',
            'last_run_at' => null,
            'last_successful_at' => null,
            'last_error' => null,
            'is_running' => false,
        ];
    }

    private function safeMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 1000);
    }
}
