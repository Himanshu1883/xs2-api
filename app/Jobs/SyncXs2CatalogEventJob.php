<?php

namespace App\Jobs;

use App\Services\Xs2\Xs2CatalogEventService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncXs2CatalogEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>|null  $payload  Pre-fetched catalog payload (avoids re-fetch when available).
     */
    public function __construct(
        public readonly string $externalEventId,
        public readonly ?array $payload = null,
    ) {
        $this->onQueue(config('xs2.queue', 'xs2-sync'));
    }

    public function handle(Xs2CatalogEventService $catalog): void
    {
        try {
            $result = $catalog->syncEvent($this->externalEventId, $this->payload);

            Log::info('XS2 catalog event sync job completed.', [
                'external_event_id' => $this->externalEventId,
                'mapping_id' => $result['mapping_id'] ?? null,
                'mapping_status' => $result['mapping_status'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Log::error('XS2 catalog event sync job failed.', [
                'external_event_id' => $this->externalEventId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
