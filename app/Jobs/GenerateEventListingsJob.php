<?php

namespace App\Jobs;

use App\Exceptions\Integrations\Xs2RateLimitException;
use App\Models\PipelineJobStep;
use App\Models\PipelineRun;
use App\Models\Xs2Event;
use App\Models\Xs2Ticket;
use App\Services\Pipeline\InventorySchedulerService;
use App\Services\Pipeline\PipelineJobStepService;
use App\Services\SellerApi\SbNewListingPublishService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateEventListingsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $xs2EventId,
        public int $pipelineRunId,
        public string $correlationId,
    ) {
        $this->onQueue(config('pipeline.listing_gen_queue', 'xs2-listing-gen'));
    }

    public function uniqueId(): string
    {
        return 'listing-gen:event:'.$this->xs2EventId.':run:'.$this->pipelineRunId;
    }

    public function handle(
        SbNewListingPublishService $sbPublish,
        PipelineJobStepService $steps,
        InventorySchedulerService $scheduler,
    ): void {
        $run = PipelineRun::query()->findOrFail($this->pipelineRunId);
        $step = $steps->findOrCreateStep(
            $run,
            $this->xs2EventId,
            PipelineJobStep::STAGE_LISTING_GEN,
            self::class,
        );
        $steps->start($step);

        $lock = Cache::lock('listing-gen:event:'.$this->xs2EventId, 600);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $started = microtime(true);
        $summary = [
            'published' => 0,
            'disabled' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $event = Xs2Event::query()->with('tickets.listingMapping')->findOrFail($this->xs2EventId);

            foreach ($event->tickets as $ticket) {
                $this->processTicket($ticket, $event, $sbPublish, $summary);
            }

            $scheduler->scheduleReconciliation($this->xs2EventId, $this->pipelineRunId, $this->correlationId);

            $steps->complete($step, (int) ((microtime(true) - $started) * 1000));

            Log::channel(config('pipeline.log_channel', 'stack'))->info('Pipeline listing generation completed.', [
                'correlation_id' => $this->correlationId,
                'xs2_event_id' => $this->xs2EventId,
                'summary' => $summary,
            ]);
        } catch (Xs2RateLimitException $exception) {
            $steps->fail($step, $exception->getMessage());
            $this->release(max(1, $exception->retryAfter));
        } catch (\Throwable $exception) {
            $steps->fail($step, $exception->getMessage());
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $summary */
    private function processTicket(
        Xs2Ticket $ticket,
        Xs2Event $event,
        SbNewListingPublishService $sbPublish,
        array &$summary,
    ): void {
        $available = $event->isSellable()
            && $ticket->ticket_status === 'available'
            && (int) $ticket->stock > 0;

        try {
            // Listing generation never creates SB listings — only the dedicated publish cron
            // runs after full validation. Here we only retire listings for unavailable tickets.
            if ($available) {
                $summary['skipped']++;

                return;
            }

            if (! $sbPublish->isPublishedOnSb($ticket)) {
                $summary['skipped']++;

                return;
            }

            if ($ticket->split_enabled) {
                DeleteSplitListings::dispatch($ticket->id);
            } else {
                DisableXs2SellerListing::dispatch($ticket->id);
            }
            $summary['disabled']++;
        } catch (Xs2RateLimitException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $summary['errors'][] = $ticket->external_ticket_id.': '.$exception->getMessage();
            Log::channel(config('pipeline.log_channel', 'stack'))->warning('Pipeline listing generation ticket failed.', [
                'correlation_id' => $this->correlationId,
                'xs2_event_id' => $this->xs2EventId,
                'external_ticket_id' => $ticket->external_ticket_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
