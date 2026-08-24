<?php

namespace App\Console\Commands;

use App\Services\SellerApi\SellerBookingSyncService;
use Illuminate\Console\Command;

class SyncSellerApiBookingsCommand extends Command
{
    protected $signature = 'seller-api:sync-bookings';

    protected $description = 'Fetch Seatsbrokers GET /api/booking and upsert into sb_orders / sb_order_attendees.';

    public function handle(SellerBookingSyncService $sync): int
    {
        $this->info('Syncing Seatsbrokers bookings into the local database...');

        $summary = $sync->sync();

        $this->table(array_keys($summary), [array_map(strval(...), array_values($summary))]);

        if (($summary['status'] ?? '') === 'failed') {
            $this->error((string) ($summary['error'] ?? 'Seller API booking sync failed.'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
