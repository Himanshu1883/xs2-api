<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Services\SellerApi\SbNewListingPublishService;
use Illuminate\Console\Command;

class PublishNewSbListingsCommand extends Command
{
    use RespectsQueueBackpressure;

    protected function queueBackpressureScope(): ?string
    {
        return (string) config('services.seller_api.queue', 'seller-api');
    }

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

        try {
            $summary = $publisher->run(
                inline: (bool) $this->option('sync'),
                ticketId: $ticketId,
                dryRun: (bool) $this->option('dry-run'),
                maxDispatch: $maxDispatch,
                manualPublish: (bool) $this->option('manual'),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

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
                ->map(fn (mixed $value, int|string $key): array => [(string) $key, $this->formatSummaryValue($value)])
                ->values()
                ->all(),
        );

        foreach ($summary['errors'] ?? [] as $error) {
            $this->error(is_scalar($error) ? (string) $error : json_encode($error));
        }

        return ($summary['status'] ?? 'completed') === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function formatSummaryValue(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return $value::class;
    }
}
