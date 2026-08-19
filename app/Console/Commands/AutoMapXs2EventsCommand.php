<?php

namespace App\Console\Commands;

use App\Models\Xs2Event;
use App\Services\Xs2\EventMappingService;
use Illuminate\Console\Command;

class AutoMapXs2EventsCommand extends Command
{
    protected $signature = 'xs2:auto-map-events
        {--min-score= : Minimum match score required to auto-map (defaults to XS2_EVENT_AUTO_MAP_THRESHOLD)}
        {--event-id= : Limit to a specific XS2 external event id}
        {--force : Recalculate existing mappings, including non-pending ones}';

    protected $description = 'Auto-map XS2 events to Seatsbroker match_info records when the match score meets the threshold.';

    public function handle(EventMappingService $mappingService): int
    {
        $minScore = $this->option('min-score');
        $autoMapThreshold = $minScore !== null && $minScore !== ''
            ? (float) $minScore
            : (float) config('xs2.mapping.event_auto_map_threshold', 100);

        $query = Xs2Event::query()->with('mapping');
        if (filled($this->option('event-id'))) {
            $query->where('external_event_id', (string) $this->option('event-id'));
        }

        $mapped = 0;
        $pending = 0;
        $skipped = 0;

        foreach ($query->get() as $event) {
            $mapping = $event->mapping;
            if (! $this->option('force')) {
                if ($mapping?->status === 'created') {
                    $skipped++;

                    continue;
                }

                if ($mapping?->status === 'ignored') {
                    $skipped++;

                    continue;
                }

                if ($mapping?->mapping_method === 'manual' && $mapping->status === 'mapped') {
                    $skipped++;

                    continue;
                }
            }

            $result = $mapping && ($this->option('force') || $mapping->status === 'mapped')
                ? $mappingService->recalculate($mapping, (bool) $this->option('force'), $autoMapThreshold)
                : $mappingService->map($event, (bool) $this->option('force'), $autoMapThreshold);

            if ($result->status === 'mapped' && (float) ($result->match_score ?? 0) >= $autoMapThreshold) {
                $mapped++;
            } elseif ($result->status === 'pending') {
                $pending++;
            } else {
                $skipped++;
            }
        }

        $this->info(sprintf(
            'Evaluated XS2 event mapping(s) at %.2f threshold: %d mapped, %d pending, %d skipped.',
            $autoMapThreshold,
            $mapped,
            $pending,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
