<?php

namespace App\Listeners;

use App\Services\Admin\CronExecutionContext;
use App\Services\Admin\CronExecutionLogService;
use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\Xs2\Xs2ApiDebugRecorder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

class CronCommandInstrumentationListener
{
    /** @var array<string, 'xs2'|'seller'> */
    private const INSTRUMENTED_COMMANDS = [
        'xs2:sync-inventory' => 'xs2',
        'xs2:publish-new-sb-listings' => 'seller',
        'xs2:sync-sb-listing-inventory' => 'seller',
        'seller-api:sync-bookings' => 'seller',
        'xs2:sync-order-guest-data' => 'seller',
    ];

    public function __construct(
        private readonly CronExecutionContext $context,
        private readonly CronExecutionLogService $executionLogs,
        private readonly Xs2ApiDebugRecorder $xs2Recorder,
        private readonly SellerApiDebugRecorder $sellerRecorder,
    ) {}

    public function handleStarting(CommandStarting $event): void
    {
        if (! $this->context->hasActiveLog()) {
            return;
        }

        $api = self::INSTRUMENTED_COMMANDS[$event->command] ?? null;
        if ($api === 'xs2') {
            $this->xs2Recorder->enable();
        } elseif ($api === 'seller') {
            $this->sellerRecorder->enable();
        }
    }

    public function handleFinished(CommandFinished $event): void
    {
        $logId = $this->context->activeLogId();
        if ($logId === null) {
            return;
        }

        $api = self::INSTRUMENTED_COMMANDS[$event->command] ?? null;
        if ($api === 'xs2') {
            $requests = $this->xs2Recorder->flush();
            if ($requests !== []) {
                $this->executionLogs->appendApiRequests($logId, $requests, 'command');
            }
        } elseif ($api === 'seller') {
            $requests = $this->sellerRecorder->flush();
            if ($requests !== []) {
                $this->executionLogs->appendApiRequests($logId, $requests, 'command');
            }
        }

        $this->executionLogs->mergeMetadata($logId, [
            'command' => $event->command,
            'exit_code' => $event->exitCode,
        ]);
    }
}
