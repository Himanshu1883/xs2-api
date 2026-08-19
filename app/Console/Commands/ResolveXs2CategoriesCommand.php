<?php

namespace App\Console\Commands;

use App\Models\Xs2Category;
use App\Models\Xs2StadiumMapping;
use App\Services\Mapping\StadiumCategoryMappingService;
use Illuminate\Console\Command;

class ResolveXs2CategoriesCommand extends Command
{
    protected $signature = 'xs2:resolve-categories {--stadium-id=} {--venue-id=} {--event-id=} {--category-id=}';

    protected $description = 'Re-evaluate non-manual XS2 category to stadium-detail mappings.';

    public function handle(StadiumCategoryMappingService $service): int
    {
        $query = Xs2Category::query()->with(['context', 'mapping', 'xs2Event']);
        if (filled($this->option('category-id'))) {
            $category = (string) $this->option('category-id');
            $query->where(function ($query) use ($category): void {
                $query->where('external_category_id', $category);
                if (ctype_digit($category)) {
                    $query->orWhere($query->getModel()->getKeyName(), (int) $category);
                }
            });
        }
        if (filled($this->option('event-id'))) {
            $event = (string) $this->option('event-id');
            $query->whereHas('xs2Event', fn ($query) => $query->where('external_event_id', $event));
        }
        if (filled($this->option('venue-id'))) {
            $query->whereHas('context', fn ($query) => $query->where('external_venue_id', (string) $this->option('venue-id')));
        }

        $count = 0;
        foreach ($query->get() as $category) {
            if ($category->mapping?->manually_confirmed) {
                continue;
            }
            $venueId = $category->context?->external_venue_id ?: $category->xs2Event?->venue_id;
            $stadium = Xs2StadiumMapping::query()
                ->whereHas('venue', fn ($query) => $query->where('external_venue_id', $venueId))
                ->when(filled($this->option('stadium-id')), fn ($query) => $query->where('stadium_id', $this->option('stadium-id')))
                ->first();
            if (! $stadium) {
                continue;
            }
            $service->resolve($category, $stadium);
            $count++;
        }

        $this->info("Resolved {$count} XS2 category mapping(s).");

        return self::SUCCESS;
    }
}
