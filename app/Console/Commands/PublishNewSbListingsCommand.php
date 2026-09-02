<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Services\SellerApi\SbNewListingPublishService;
use Illuminate\Console\Command;

class PublishNewSbListingsCommand extends Command
{
    use RespectsQueueBackpressure;

    protected $signature = 'xs2:publish-new-sb-listings
                            {--sync : Run publish jobs inline instead of queueing}
                            {--ticket= : Limit to one XS2 ticket id}
                            {--dry-run : Show eligible unpublished tickets without publishing}
                            {--force : Dispatch even when queue backpressure is active}
                            {--manual : Allow pending mapping with category_name bypass (admin Run now)}';

    protected $description = 'Publish new XS2 inventory on mapped events to Seats Broker (skips tickets already listed on SB).';

    public function handle(SbNewListingPublishService $publisher): int
    {
        if (! (bool) config('xs2.sb_new_listing_publish.enabled', true)) {
            $this->warn('Seats Broker new listing publish is disabled (XS2_SB_NEW_LISTING_PUBLISH_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! (bool) config('services.seller_api.enabled', true)) {
            $this->warn('Seller API integration is disabled (SELLER_API_ENABLED=false).');

            return self::SUCCESS;
        }

        $ticketId = filled($this->option('ticket')) ? (int) $this->option('ticket') : null;

        if ($ticketId === null && ! (bool) $this->option('sync') && $this->skipIfQueueBackpressureActive()) {
            return self::SUCCESS;
        }

        $maxDispatch = $ticketId !== null || ! $this->respectsQueueBackpressure()
            ? null
            : $this->queueDispatchBudget();

        $this->info('Scanning mapped XS2 events for inventory not yet published on Seats Broker...');

        $summary = $publisher->run(
            inline: (bool) $this->option('sync'),
            ticketId: $ticketId,
            dryRun: (bool) $this->option('dry-run'),
            maxDispatch: $maxDispatch,
            manualPublish: (bool) $this->option('manual'),
        );

        if (($summary['deferred'] ?? 0) > 0) {
            $this->warn(sprintf(
                'Deferred %d tickets — dispatch budget reached. Remaining tickets will publish on the next run.',
                (int) $summary['deferred'],
            ));
        }

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->except(['errors'])
                ->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value) : (string) $value])
                ->values()
                ->all(),
        );

        foreach ($summary['errors'] ?? [] as $error) {
            $this->error($error);
        }

        return ($summary['errors'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}
