<?php

namespace App\Console\Commands;

use App\Services\Xs2\Xs2OrderSyncService;
use Illuminate\Console\Command;

class SyncXs2OrdersCommand extends Command
{
    protected $signature = 'xs2:sync-orders';

    protected $description = 'Fetch XS2 Test API GET /v1/bookingorders and upsert sandbox rows into xs2_orders.';

    public function handle(Xs2OrderSyncService $sync): int
    {
        $this->info('Syncing XS2 orders into the local database...');

        try {
            $summary = $sync->sync();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(array_keys($summary), [array_map(strval(...), array_values($summary))]);

        return self::SUCCESS;
    }
}
