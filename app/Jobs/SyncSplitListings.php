<?php

namespace App\Jobs;

use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncSplitListings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $ticketId)
    {
        $this->onQueue(config('services.seller_api.queue'));
    }

    public function uniqueId(): string
    {
        return 'xs2-split-sync:'.$this->ticketId;
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
        $splits->syncListings($ticket);
    }

    public function failed(?\Throwable $exception): void
    {
        $ticket = Xs2Ticket::query()->find($this->ticketId);
        if (! $ticket) {
            return;
        }

        app(SplitListingService::class)->markFailed(
            $ticket,
            $exception?->getMessage() ?? 'Split sync failed.',
        );

        Log::channel(config('services.seller_api.log_channel', 'stack'))->error('Split listing sync job failed.', [
            'ticket_id' => $this->ticketId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
