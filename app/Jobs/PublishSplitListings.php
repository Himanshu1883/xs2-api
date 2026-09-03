<?php

namespace App\Jobs;

use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class PublishSplitListings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /** @param  array{split_quantity: int, price_increment_type: string, price_increment_value: float|string, base_price?: float|string|null}  $config */
    public function __construct(
        public int $ticketId,
        public array $config,
    ) {
        $this->onQueue(config('services.seller_api.queue'));
    }

    public function uniqueId(): string
    {
        return 'xs2-split-publish:'.$this->ticketId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('xs2-seller-listing:'.$this->ticketId))
                ->shared()
                ->releaseAfter(90)
                ->expireAfter(320),
        ];
    }

    public function handle(SplitListingService $splits): void
    {
        $ticket = Xs2Ticket::query()->with('xs2Event.mapping')->findOrFail($this->ticketId);
        $ticket->refresh();

        // Stock can drop to zero between queue time and job execution (inventory sync,
        // delayed publish batches, or a fast sell-through). Skip quietly — not a failure.
        if ((int) $ticket->stock <= 0) {
            Log::channel(config('services.seller_api.log_channel', 'stack'))->info(
                'Skipping split publish: ticket has no stock at job run time.',
                [
                    'ticket_id' => $this->ticketId,
                    'external_ticket_id' => $ticket->external_ticket_id,
                ],
            );

            return;
        }

        $splits->publishListings($ticket, $this->config);
    }

    public function failed(?\Throwable $exception): void
    {
        $ticket = Xs2Ticket::query()->find($this->ticketId);
        if (! $ticket) {
            return;
        }

        $splits = app(SplitListingService::class);
        if ($exception) {
            $splits->markFailedFromException($ticket, $exception);
        } else {
            $splits->markFailed($ticket, 'Split publish failed.');
        }

        Log::channel(config('services.seller_api.log_channel', 'stack'))->error('Split listing publish job failed.', [
            'ticket_id' => $this->ticketId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
