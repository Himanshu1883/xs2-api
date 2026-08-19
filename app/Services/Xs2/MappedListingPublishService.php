<?php

namespace App\Services\Xs2;

use App\Jobs\PublishSplitListings;
use App\Jobs\PushXs2TicketToSellerApi;
use App\Models\Xs2Ticket;
use App\Services\SplitListings\SplitListingService;

class MappedListingPublishService
{
    public function __construct(
        private readonly ListingPublishRuleService $rules,
        private readonly SplitListingService $splitListings,
    ) {}

    /**
     * Publish a mapped ticket to Seats Broker, applying configured rules when enabled.
     */
    public function publishTicket(int $ticketId, bool $strictPublish = false, bool $sync = false): void
    {
        if (! $this->rules->rulesEnabled()) {
            $this->dispatchSingle($ticketId, $strictPublish, $sync);

            return;
        }

        $ticket = Xs2Ticket::query()->findOrFail($ticketId);
        $plan = $this->rules->buildPlan($ticket);

        if ($plan === null) {
            $this->dispatchSingle($ticketId, $strictPublish, $sync);

            return;
        }

        if (($plan['mode'] ?? '') === 'split') {
            $this->publishSplit($ticket, $plan, $sync);

            return;
        }

        $this->publishSingle($ticket, $plan, $strictPublish, $sync);
    }

    /** @param  array<string, mixed>  $plan */
    private function publishSplit(Xs2Ticket $ticket, array $plan, bool $sync): void
    {
        if ($sync) {
            PublishSplitListings::dispatchSync($ticket->id, $plan['split_config']);
        } else {
            PublishSplitListings::dispatch($ticket->id, $plan['split_config']);
        }
    }

    /** @param  array<string, mixed>  $plan */
    private function publishSingle(Xs2Ticket $ticket, array $plan, bool $strictPublish, bool $sync): void
    {
        if ($ticket->split_enabled) {
            $this->splitListings->deleteAllListings($ticket);
            $ticket->refresh();
        }

        $quantity = max(1, (int) ($plan['quantity'] ?? $ticket->stock));
        $pairsOnly = (bool) ($plan['pairs_only'] ?? false);

        if ($sync) {
            PushXs2TicketToSellerApi::dispatchSync($ticket->id, $strictPublish, $quantity, $pairsOnly);
        } else {
            PushXs2TicketToSellerApi::dispatch($ticket->id, $strictPublish, $quantity, $pairsOnly);
        }
    }

    private function dispatchSingle(int $ticketId, bool $strictPublish, bool $sync): void
    {
        if ($sync) {
            PushXs2TicketToSellerApi::dispatchSync($ticketId, $strictPublish);
        } else {
            PushXs2TicketToSellerApi::dispatch($ticketId, $strictPublish);
        }
    }
}
