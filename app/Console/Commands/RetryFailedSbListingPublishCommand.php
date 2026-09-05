<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Services\SellerApi\SbNewListingPublishService;
use Illuminate\Console\Command;

class RetryFailedSbListingPublishCommand extends Command
{
    use RespectsQueueBackpressure;

    protected $signature = 'xs2:retry-failed-listing-publish
                            {--sync : Run publish jobs inline instead of queueing}
                            {--ticket= : Limit to one XS2 ticket id}
                            {--dry-run : Show eligible failed tickets without publishing}
                            {--force : Dispatch even when queue backpressure is active}';

    protected $description = 'Retry Seats Broker publish for XS2 tickets previously marked publish-failed.';

    public function handle(SbNewListingPublishService $publisher): int
    {
        if (! (bool) config('xs2.sb_failed_listing_publish_retry.enabled', false)) {
            $this->warn('Failed listing publish retry is disabled (XS2_SB_FAILED_LISTING_PUBLISH_RETRY_ENABLED=false).');

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

        $this->info('Scanning publish-failed XS2 tickets for Seats Broker retry...');

        $summary = $publisher->run(
            inline: (bool) $this->option('sync'),
            ticketId: $ticketId,
            dryRun: (bool) $this->option('dry-run'),
            maxDispatch: $maxDispatch,
            failedOnly: true,
        );

        if (($summary['deferred'] ?? 0) > 0) {
            $this->warn(sprintf(
                'Deferred %d tickets — dispatch budget reached. Remaining tickets will retry on the next run.',
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

        return ($summary['status'] ?? 'completed') === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
