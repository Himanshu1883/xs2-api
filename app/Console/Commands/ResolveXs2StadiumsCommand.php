<?php

namespace App\Console\Commands;

use App\Models\Xs2Venue;
use App\Services\Mapping\StadiumMappingService;
use Illuminate\Console\Command;

class ResolveXs2StadiumsCommand extends Command
{
    protected $signature = 'xs2:resolve-stadiums {--venue-id=} {--force}';

    protected $description = 'Re-evaluate non-manual XS2 venue to stadium mappings.';

    public function handle(StadiumMappingService $service): int
    {
        $query = Xs2Venue::query()->with('stadiumMapping');
        if (filled($this->option('venue-id'))) {
            $venue = (string) $this->option('venue-id');
            $query->where(function ($query) use ($venue): void {
                $query->where('external_venue_id', $venue);
                if (ctype_digit($venue)) {
                    $query->orWhere($query->getModel()->getKeyName(), (int) $venue);
                }
            });
        }

        $count = 0;
        foreach ($query->get() as $venue) {
            // --force rechecks unmatched/pending decisions; it still never
            // overwrites administrator-confirmed mappings.
            if ($venue->stadiumMapping?->manually_confirmed) {
                continue;
            }
            $service->resolve($venue);
            $count++;
        }

        $this->info("Resolved {$count} XS2 stadium mapping(s).");

        return self::SUCCESS;
    }
}
