<?php

namespace App\Jobs;

use App\Models\SbOrder;
use App\Services\Xs2\SbOrderXs2SandboxOrderService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateXs2SandboxOrderFromSbOrder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $sbOrderId)
    {
        $this->onQueue(config('xs2.sandbox.order_queue', config('xs2.queue', 'xs2-sync')));
    }

    public function uniqueId(): string
    {
        return 'sb-xs2-sandbox-order:'.$this->sbOrderId;
    }

    public function handle(SbOrderXs2SandboxOrderService $service): void
    {
        $order = SbOrder::query()->with('attendees')->find($this->sbOrderId);
        if ($order === null) {
            return;
        }

        $result = $service->createFromSbOrder($order);

        if ($result['skipped'] ?? false) {
            Log::debug('Skipped XS2 sandbox order creation for SB order.', [
                'sb_order_id' => $this->sbOrderId,
                'reason' => $result['reason'] ?? null,
            ]);

            return;
        }

        if (($result['created'] ?? false) || ($result['updated'] ?? false)) {
            Log::info('XS2 sandbox order synced from SB order.', [
                'sb_order_id' => $this->sbOrderId,
                'xs2_order_id' => $result['order']?->id,
                'created' => $result['created'] ?? false,
            ]);
        }
    }
}
