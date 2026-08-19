<?php

namespace App\Services\SellerApi;

use App\Models\Xs2SyncState;
use App\Services\SplitListings\SplitListingQuantitySyncService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Unified Seats Broker inventory reconcile for published XS2 listings (master + split).
 */
class SbListingInventorySyncService
{
    public const SYNC_RESOURCE = 'sb-listings:inventory';

    public function __construct(
        private readonly MasterListingQuantitySyncService $masters,
        private readonly SplitListingQuantitySyncService $splits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $inline = false, ?int $ticketId = null, bool $force = false): array
    {
        if (Schema::hasTable('xs2_sync_states')) {
            Xs2SyncState::query()->firstOrCreate(['resource' => self::SYNC_RESOURCE])->update([
                'status' => 'running',
                'last_attempted_at' => now(),
                'last_error' => null,
            ]);
        }

        $summary = [
            'masters' => [],
            'splits' => [],
            'eligible_tickets' => 0,
            'needs_sync' => 0,
            'queued' => 0,
            'synced_inline' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $masterSummary = $this->masters->run(
                inline: $inline,
                ticketId: $ticketId,
                force: $force,
                manageState: false,
            );
            $splitSummary = $this->splits->run(
                inline: $inline,
                ticketId: $ticketId,
                force: $force,
                manageState: false,
            );

            $summary['masters'] = $masterSummary;
            $summary['splits'] = $splitSummary;

            foreach (['eligible_tickets', 'needs_sync', 'queued', 'synced_inline', 'skipped'] as $metric) {
                $summary[$metric] = (int) ($masterSummary[$metric] ?? 0) + (int) ($splitSummary[$metric] ?? 0);
            }

            $summary['errors'] = array_values(array_merge(
                $masterSummary['errors'] ?? [],
                $splitSummary['errors'] ?? [],
            ));

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
        $masters = $this->masters->telemetry();
        $splits = $this->splits->telemetry();

        $state = Schema::hasTable('xs2_sync_states')
            ? Xs2SyncState::query()->where('resource', self::SYNC_RESOURCE)->first()
            : null;

        $rawStatus = $state?->status ?? 'never_run';
        $pendingSync = (int) ($masters['pending_sync'] ?? 0) + (int) ($splits['pending_sync'] ?? 0);

        return [
            'masters' => $masters,
            'splits' => $splits,
            'eligible_tickets' => (int) ($masters['eligible_tickets'] ?? 0) + (int) ($splits['eligible_tickets'] ?? 0),
            'pending_sync' => $pendingSync,
            'status' => $rawStatus,
            'last_run_at' => $state?->last_attempted_at?->toIso8601String(),
            'last_successful_at' => $state?->last_successful_at?->toIso8601String(),
            'last_error' => filled($state?->last_error) ? (string) $state->last_error : null,
            'is_running' => $rawStatus === 'running',
            'metadata' => is_array($state?->metadata) ? $state->metadata : [],
        ];
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
                    'needs_sync' => (int) ($summary['needs_sync'] ?? 0),
                    'queued' => (int) ($summary['queued'] ?? 0),
                    'synced_inline' => (int) ($summary['synced_inline'] ?? 0),
                    'skipped' => (int) ($summary['skipped'] ?? 0),
                    'master_eligible' => (int) ($summary['masters']['eligible_tickets'] ?? 0),
                    'split_eligible' => (int) ($summary['splits']['eligible_tickets'] ?? 0),
                    'errors' => count($errors),
                ],
            ]);
        }

        $summary['errors'] = $errors;
        $summary['status'] = $failed ? 'failed' : 'completed';
        $summary['completed_at'] = now()->toIso8601String();

        return $summary;
    }
}
