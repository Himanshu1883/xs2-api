<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Jobs\SyncXs2EventsJob;
use App\Services\Xs2\Xs2EventSyncService;
use Illuminate\Console\Command;

class SyncXs2EventsCommand extends Command
{
    use RespectsQueueBackpressure;

    protected $signature = 'xs2:sync-events {--sport= : XS2 sport type} {--full : Full reconciliation} {--sync : Run now rather than queue} {--force : Ignore queue backpressure}';

    protected $description = 'Import and map XS2 events for all configured sports (XS2_SPORTS).';

    public function handle(Xs2EventSyncService $syncService): int
    {
        if (! $this->option('sync') && $this->skipIfQueueBackpressureActive()) {
            return self::SUCCESS;
        }

        $sports = $this->option('sport')
            ? [trim((string) $this->option('sport'))]
            : array_values(array_filter(array_map(
                fn (mixed $sport): string => is_string($sport) ? trim($sport) : '',
                config('services.xs2.sports', []),
            )));

        if ($sports === []) {
            $this->warn('No sports configured. Set XS2_SPORTS in .env (e.g. soccer,tennis).');

            return self::SUCCESS;
        }

        $full = (bool) $this->option('full');
        $queued = 0;

        foreach ($sports as $sport) {
            if ($sport === '') {
                continue;
            }

            if ($this->option('sync')) {
                $summary = $syncService->sync($sport, $full);
                $this->info("Synced {$sport} (".($full ? 'full' : 'incremental').').');
                $this->table(array_keys($summary), [array_values($summary)]);
            } else {
                SyncXs2EventsJob::dispatch($sport, $full);
                $this->line("  Queued {$sport} (".($full ? 'full' : 'incremental').')');
                $queued++;
            }
        }

        if (! $this->option('sync') && $queued > 0) {
            $this->info("Queued XS2 event sync for {$queued} sport(s): ".implode(', ', $sports));
        }

        return self::SUCCESS;
    }
}
