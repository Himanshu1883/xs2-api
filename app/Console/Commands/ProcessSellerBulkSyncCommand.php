<?php

namespace App\Console\Commands;

use App\Jobs\SyncSellerBulkEventsJob;
use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\SellerApi\SellerBulkEventSyncState;
use App\Services\SellerApi\SellerEventImportService;
use Illuminate\Console\Command;

class ProcessSellerBulkSyncCommand extends Command
{
    protected $signature = 'seller-api:process-bulk-sync
                            {syncId : Bulk sync run id}
                            {tournamentId : Local tournament id}
                            {environment : sandbox or production}';

    protected $description = 'Run a single Seats Broker bulk event sync in the foreground (used by the admin UI).';

    public function handle(SellerEventImportService $import, SellerApiDebugRecorder $recorder): int
    {
        $syncId = (string) $this->argument('syncId');
        $tournamentId = (int) $this->argument('tournamentId');
        $environment = (string) $this->argument('environment');

        if (SellerBulkEventSyncState::get($syncId) === null) {
            $this->error("Bulk sync run {$syncId} was not found.");

            return self::FAILURE;
        }

        (new SyncSellerBulkEventsJob($syncId, $tournamentId, $environment))->handle($import, $recorder);

        $state = SellerBulkEventSyncState::get($syncId);
        $status = is_array($state) ? (string) ($state['status'] ?? '') : '';

        if ($status === 'failed') {
            $this->error((string) ($state['message'] ?? 'Bulk sync failed.'));

            return self::FAILURE;
        }

        $this->info((string) ($state['message'] ?? 'Bulk sync completed.'));

        return self::SUCCESS;
    }
}
