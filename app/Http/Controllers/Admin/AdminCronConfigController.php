<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Services\Admin\CronConfigService;
use App\Services\Admin\CronControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminCronConfigController extends Controller
{
    public function __construct(
        private readonly CronConfigService $cronConfig,
        private readonly CronControlService $cronControl,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $snapshot = $this->cronConfig->snapshot();

        return response()->json([
            'message' => 'Scheduled task configuration.',
            'data' => $snapshot,
        ]);
    }

    public function syncInventoryByLeague(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'tournament' => ['required', 'string', 'max:255'],
            'mode' => ['nullable', 'in:incremental,full'],
            'future_only' => ['nullable', 'boolean'],
        ]);

        $result = $this->cronConfig->queueInventorySyncByLeague(
            tournament: $validated['tournament'],
            mode: $validated['mode'] ?? 'full',
            futureOnly: array_key_exists('future_only', $validated)
                ? (bool) $validated['future_only']
                : true,
        );

        return response()->json([
            'message' => $result['queued'] > 0
                ? "Queued {$result['queued']} inventory sync job(s) for {$result['tournament']}."
                : "No matching events queued for {$result['tournament']}.",
            'data' => $result,
        ], $result['queued'] > 0 ? 202 : 200);
    }

    public function logs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Recent cron and sync activity.',
            'data' => $this->cronConfig->syncLogs(),
        ]);
    }

    public function stopAll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'stop_queues' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->cronControl->stopAll(
                stopQueues: array_key_exists('stop_queues', $validated)
                    ? (bool) $validated['stop_queues']
                    : true,
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cron' => [$exception->getMessage()],
            ]);
        }

        $queueDeleted = (int) ($result['queue']['jobs_deleted'] ?? 0);

        return response()->json([
            'message' => $queueDeleted > 0
                ? 'All crons disabled, low-load mode enabled, and queue workers signaled to stop. Removed '.$queueDeleted.' queued job(s). If CPU stays high, run the AWS emergency steps on the server (supervisor/systemd kill).'
                : 'All crons disabled and low-load mode enabled. New scheduled tasks will not run until you start them again. Stop supervisor/systemd workers on AWS if load remains high.',
            'data' => [
                ...$result,
                'snapshot' => $this->cronConfig->snapshot(),
            ],
        ]);
    }

    public function toggleSbOrderSync(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $result = $this->cronControl->setCronEnabled('xs2-sb-order-sync', (bool) $validated['enabled']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'cron_job_id' => [$exception->getMessage()],
            ]);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cron' => [$exception->getMessage()],
            ]);
        }

        $enabled = (bool) $result['enabled'];

        return response()->json([
            'message' => $enabled
                ? 'SB order sync enabled.'
                : 'SB order sync disabled.',
            'data' => $result,
        ]);
    }

    public function setStartAll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $result = $this->cronControl->setStartAllEnabled((bool) $validated['enabled']);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cron' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'message' => $validated['enabled']
                ? 'Start All enabled. The safe startup pipeline was queued.'
                : 'Start All disabled. Only individually enabled crons will run.',
            'data' => [
                ...$result,
                'snapshot' => $this->cronConfig->snapshot(),
            ],
        ]);
    }

    public function toggleCron(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'cron_job_id' => ['required', 'string', 'max:128'],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $result = $this->cronControl->setCronEnabled(
                (string) $validated['cron_job_id'],
                (bool) $validated['enabled'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'cron_job_id' => [$exception->getMessage()],
            ]);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cron' => [$exception->getMessage()],
            ]);
        }

        $enabled = (bool) $result['enabled'];

        return response()->json([
            'message' => $enabled
                ? "Cron {$validated['cron_job_id']} enabled."
                : "Cron {$validated['cron_job_id']} disabled.",
            'data' => [
                ...$result,
                'snapshot' => $this->cronConfig->snapshot(),
            ],
        ]);
    }

    public function startAll(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        try {
            $result = $this->cronControl->startAll();
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cron' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'message' => $result['scheduler_enabled']
                ? 'Scheduled crons re-enabled and the safe startup pipeline was queued (inventory → publish → SB qty sync). Queue workers process jobs in the background.'
                : 'Cron settings updated, but the scheduler master switch remains off.',
            'data' => [
                ...$result,
                'snapshot' => $this->cronConfig->snapshot(),
            ],
        ]);
    }
}
