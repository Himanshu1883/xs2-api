<?php

namespace App\Console\Commands;

use App\Console\Concerns\RespectsQueueBackpressure;
use App\Jobs\SyncXs2TicketGuestRequirements;
use App\Models\Xs2Ticket;
use Illuminate\Console\Command;

class SyncXs2GuestRequirementsCommand extends Command
{
    use RespectsQueueBackpressure;

    protected $signature = 'xs2:sync-guest-requirements {--ticket-id=} {--event-id=} {--missing-only} {--force : Ignore queue backpressure}';

    protected $description = 'Queue XS2 guest-data requirement fetches for stored tickets.';

    public function handle(): int
    {
        if ($this->skipIfQueueBackpressureActive()) {
            return self::SUCCESS;
        }

        $dispatchBudget = $this->queueDispatchBudget();
        $query = Xs2Ticket::query()->select('id');

        if ($this->option('missing-only')) {
            $query->whereNull('guest_data_synced_at');
        }

        if (filled($this->option('ticket-id'))) {
            $query->where('external_ticket_id', (string) $this->option('ticket-id'));
        }

        if (filled($this->option('event-id'))) {
            $eventId = (string) $this->option('event-id');
            $query->where(function ($ticket) use ($eventId): void {
                $ticket->where('external_event_id', $eventId);
                if (ctype_digit($eventId)) {
                    $ticket->orWhere('xs2_event_id', (int) $eventId);
                }
            });
        }

        $count = 0;
        foreach ($query->pluck('id') as $ticketId) {
            if ($count >= $dispatchBudget) {
                $this->warn("Dispatch budget reached ({$dispatchBudget} jobs). Remaining tickets will sync on the next run.");
                break;
            }

            SyncXs2TicketGuestRequirements::dispatch((int) $ticketId);
            $count++;
        }

        $this->info("Queued {$count} XS2 guest requirement synchronization job(s).");

        return self::SUCCESS;
    }
}
