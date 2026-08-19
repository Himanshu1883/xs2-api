<?php

namespace App\Jobs;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Models\Xs2Ticket;
use App\Services\Xs2\Xs2Client;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncXs2TicketGuestRequirements implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 120, 300];

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $ticketId)
    {
        $this->onQueue(config('xs2.guest_queue', 'xs2-guest'));
    }

    public function uniqueId(): string
    {
        return 'xs2-ticket-guest-reqs:'.$this->ticketId;
    }

    public function handle(Xs2Client $client): void
    {
        $ticket = Xs2Ticket::query()->find($this->ticketId);
        if ($ticket === null) {
            return;
        }

        try {
            $payload = $client->getTicketGuestData($ticket->external_ticket_id);
            $requirements = $payload['guest_data_requirements'] ?? [];
            if (! is_array($requirements)) {
                $requirements = [];
            }

            $ticket->update([
                'guest_data_requirements' => array_values(array_filter($requirements, is_string(...))),
                'guest_data_synced_at' => now(),
            ]);
        } catch (Xs2RateLimitException $exception) {
            $this->release(max(1, $exception->retryAfter));
        }
    }
}
