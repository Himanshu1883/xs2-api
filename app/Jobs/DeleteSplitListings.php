<?php

namespace App\Jobs;

use App\Models\ListingSplit;
use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class DeleteSplitListings implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $ticketId, public ?int $triggerSplitId = null)
    {
        $this->onQueue(config('services.seller_api.queue'));
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
        $ticket = Xs2Ticket::query()->findOrFail($this->ticketId);

        if ($this->triggerSplitId !== null) {
            $split = ListingSplit::query()->findOrFail($this->triggerSplitId);
            $splits->deleteOneSplitListingCascade($ticket, $split);

            return;
        }

        $splits->deleteAllListings($ticket);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel(config('services.seller_api.log_channel', 'stack'))->error('Split listing delete job failed.', [
            'ticket_id' => $this->ticketId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
