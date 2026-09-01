<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ZeroPublishedListingStockCommand extends Command
{
    protected $signature = 'xs2:zero-published-listing-stock
                            {--dry-run : Show how many tickets would be updated without writing}';

    protected $description = 'Set xs2_tickets.stock to 0 for every published listing (run xs2:sync-sb-listing-inventory afterward to push to Seats Broker).';

    public function handle(): int
    {
        if (! Schema::hasTable('xs2_tickets') || ! Schema::hasTable('xs2_ticket_mapping_states')) {
            $this->error('Required XS2 inventory tables are not available.');

            return self::FAILURE;
        }

        $publishedQuery = DB::table('xs2_tickets as t')
            ->join('xs2_ticket_mapping_states as ms', 'ms.xs2_ticket_id', '=', 't.id')
            ->where('ms.mapping_status', 'published');

        $publishedCount = (clone $publishedQuery)->count();
        $needsUpdateCount = (clone $publishedQuery)->where('t.stock', '>', 0)->count();
        $totalStock = (int) (clone $publishedQuery)->sum('t.stock');

        $this->table(
            ['Metric', 'Value'],
            [
                ['published_tickets', (string) $publishedCount],
                ['tickets_with_stock_gt_0', (string) $needsUpdateCount],
                ['current_total_stock', (string) $totalStock],
            ],
        );

        if ($needsUpdateCount === 0) {
            $this->info('All published tickets already have stock = 0.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run only — no database changes made.');
            $this->line('Next: php artisan xs2:sync-sb-listing-inventory --sync --force');

            return self::SUCCESS;
        }

        $updated = DB::table('xs2_tickets as t')
            ->join('xs2_ticket_mapping_states as ms', 'ms.xs2_ticket_id', '=', 't.id')
            ->where('ms.mapping_status', 'published')
            ->where('t.stock', '>', 0)
            ->update([
                't.stock' => 0,
                't.updated_at' => now(),
            ]);

        $this->info("Updated {$updated} published ticket(s) to stock = 0.");
        $this->line('Next: php artisan xs2:sync-sb-listing-inventory --sync --force');

        return self::SUCCESS;
    }
}
