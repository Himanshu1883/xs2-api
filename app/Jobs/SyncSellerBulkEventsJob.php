<?php

namespace App\Jobs;

use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\SellerApi\SellerBulkEventSyncState;
use App\Services\SellerApi\SellerEventImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncSellerBulkEventsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly string $syncId,
        public readonly int $tournamentId,
        public readonly string $environment,
    ) {
        $this->onQueue(config('services.seller_api.queue'));
    }

    public function handle(SellerEventImportService $import, SellerApiDebugRecorder $recorder): void
    {
        SellerBulkEventSyncState::markRunning($this->syncId);
        $recorder->enable();

        try {
            $result = $import->syncByTournament(
                $this->tournamentId,
                $this->environment,
                function (array $summary, int $page, int $lastPage): void {
                    SellerBulkEventSyncState::updateProgress(
                        $this->syncId,
                        $summary,
                        $page,
                        $lastPage,
                    );
                },
            );

            SellerBulkEventSyncState::markCompleted($this->syncId, $result, $recorder->flush());

            Log::info('Seller bulk event sync completed.', [
                'sync_id' => $this->syncId,
                'tournament_id' => $this->tournamentId,
                'environment' => $this->environment,
                'summary' => $result,
            ]);
        } catch (\Throwable $exception) {
            SellerBulkEventSyncState::markFailed(
                $this->syncId,
                $exception,
                $recorder->flush(),
                $import,
                $this->tournamentId,
                $this->environment,
            );

            Log::error('Seller bulk event sync failed.', [
                'sync_id' => $this->syncId,
                'tournament_id' => $this->tournamentId,
                'environment' => $this->environment,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            $recorder->disable();
        }
    }
}
