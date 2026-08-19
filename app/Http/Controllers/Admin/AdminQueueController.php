<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Services\Admin\QueueAuditLogger;
use App\Services\Admin\QueueFailedJobsService;
use App\Services\Admin\QueueLiveStatsService;
use App\Services\Admin\QueueManagementService;
use App\Services\Admin\QueueProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminQueueController extends Controller
{
    public function __construct(
        private readonly QueueManagementService $queues,
        private readonly QueueLiveStatsService $liveStats,
        private readonly QueueProfileService $profiles,
        private readonly QueueFailedJobsService $failedJobs,
        private readonly QueueAuditLogger $audit,
    ) {}

    public function liveStats(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Live queue and sync statistics retrieved successfully.',
            'data' => $this->liveStats->snapshot(),
        ]);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Queue job counts retrieved successfully.',
            'data' => $this->queues->snapshot(),
        ]);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        $queue = isset($validated['queue']) && trim((string) $validated['queue']) !== ''
            ? trim((string) $validated['queue'])
            : null;

        if ($queue !== null && ! in_array($queue, $this->allowedQueueNames(), true)) {
            throw ValidationException::withMessages([
                'queue' => ["Unknown queue “{$queue}”. Refresh the page and choose a listed queue name."],
            ]);
        }

        $result = $this->failedJobs->list(
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 20),
            queue: $queue,
        );

        return response()->json([
            'message' => $result['available']
                ? 'Failed queue jobs retrieved successfully.'
                : 'Failed jobs table is not available.',
            'data' => $result,
        ]);
    }

    public function retryFailedJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        if (! preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            throw ValidationException::withMessages([
                'uuid' => ['Invalid failed job identifier.'],
            ]);
        }

        try {
            $result = $this->failedJobs->retry($uuid);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'uuid' => [$exception->getMessage()],
            ]);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'failed_jobs' => [$exception->getMessage()],
            ]);
        }

        $this->audit->log('failed_job.retry', $this->actorId($request), [
            'uuid' => $uuid,
            'retried' => $result['retried'],
        ]);

        return response()->json([
            'message' => $result['retried'] > 0
                ? 'Failed job queued for retry.'
                : 'Failed job could not be retried.',
            'data' => [
                ...$result,
                'snapshot' => $this->queues->snapshot(),
            ],
        ]);
    }

    public function retryAllFailedJobs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        $queue = isset($validated['queue']) && trim((string) $validated['queue']) !== ''
            ? trim((string) $validated['queue'])
            : null;

        if ($queue !== null && ! in_array($queue, $this->allowedQueueNames(), true)) {
            throw ValidationException::withMessages([
                'queue' => ["Unknown queue “{$queue}”."],
            ]);
        }

        try {
            $result = $this->failedJobs->retryAll($queue);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'failed_jobs' => [$exception->getMessage()],
            ]);
        }

        $label = $queue ?? 'all queues';
        $this->audit->log('failed_job.retry_all', $this->actorId($request), [
            'queue' => $queue,
            'retried' => $result['retried'],
        ]);

        return response()->json([
            'message' => $result['retried'] > 0
                ? "Retried {$result['retried']} failed job(s) on {$label}."
                : "No failed jobs to retry on {$label}.",
            'data' => [
                ...$result,
                'queue' => $queue,
                'snapshot' => $this->queues->snapshot(),
            ],
        ]);
    }

    public function deleteFailedJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        if (! preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            throw ValidationException::withMessages([
                'uuid' => ['Invalid failed job identifier.'],
            ]);
        }

        try {
            $this->failedJobs->delete($uuid);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'uuid' => [$exception->getMessage()],
            ]);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'failed_jobs' => [$exception->getMessage()],
            ]);
        }

        $this->audit->log('failed_job.delete', $this->actorId($request), ['uuid' => $uuid]);

        return response()->json([
            'message' => 'Failed job record deleted.',
            'data' => [
                'uuid' => $uuid,
                'snapshot' => $this->queues->snapshot(),
            ],
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        $queue = isset($validated['queue']) && trim((string) $validated['queue']) !== ''
            ? trim((string) $validated['queue'])
            : null;

        if ($queue !== null && ! in_array($queue, $this->allowedQueueNames(), true)) {
            throw ValidationException::withMessages([
                'queue' => ["Unknown queue “{$queue}”. Refresh the page and choose a listed queue name."],
            ]);
        }

        try {
            $result = $this->queues->clearPending($queue);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'queue' => [$exception->getMessage()],
            ]);
        }

        $label = $queue ?? 'all queues';
        $this->audit->log('queue.clear_pending', $this->actorId($request), [
            'queue' => $queue,
            'deleted' => $result['deleted'],
        ]);

        return response()->json([
            'message' => $result['deleted'] > 0
                ? "Removed {$result['deleted']} pending job(s) from {$label}."
                : "No pending jobs to remove from {$label}.",
            'data' => [
                ...$result,
                'snapshot' => $this->queues->snapshot(),
            ],
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        $queue = isset($validated['queue']) && trim((string) $validated['queue']) !== ''
            ? trim((string) $validated['queue'])
            : null;

        if ($queue !== null && ! in_array($queue, $this->allowedQueueNames(), true)) {
            throw ValidationException::withMessages([
                'queue' => ["Unknown queue “{$queue}”. Refresh the page and choose a listed queue name."],
            ]);
        }

        try {
            $result = $this->queues->stopAll($queue);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'queue' => [$exception->getMessage()],
            ]);
        }

        $label = $queue ?? 'all queues';
        $this->audit->log('queue.stop', $this->actorId($request), [
            'queue' => $queue,
            'jobs_deleted' => $result['jobs_deleted'],
            'failed_deleted' => $result['failed_deleted'],
            'workers_restarted' => $result['workers_restarted'],
        ]);

        return response()->json([
            'message' => sprintf(
                'Stopped queue workers and removed %d job(s) from %s%s.',
                $result['jobs_deleted'],
                $label,
                $result['failed_deleted'] > 0 ? " (plus {$result['failed_deleted']} failed job record(s))" : '',
            ),
            'data' => [
                ...$result,
                'snapshot' => $this->queues->snapshot(),
            ],
        ]);
    }

    public function promoteDelayed(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:255'],
        ]);

        $queue = isset($validated['queue']) && trim((string) $validated['queue']) !== ''
            ? trim((string) $validated['queue'])
            : null;

        if ($queue !== null && ! in_array($queue, $this->allowedQueueNames(), true)) {
            throw ValidationException::withMessages([
                'queue' => ["Unknown queue “{$queue}”."],
            ]);
        }

        try {
            $result = $this->queues->promoteDelayed($queue);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'queue' => [$exception->getMessage()],
            ]);
        }

        $label = $queue ?? 'all queues';
        $this->audit->log('queue.promote_delayed', $this->actorId($request), [
            'queue' => $queue,
            'promoted' => $result['promoted'],
        ]);

        return response()->json([
            'message' => $result['promoted'] > 0
                ? "Promoted {$result['promoted']} delayed job(s) on {$label}."
                : "No delayed jobs to promote on {$label}.",
            'data' => [
                ...$result,
                'snapshot' => $this->queues->snapshot(),
            ],
        ]);
    }

    public function applyProfile(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'profile' => ['required', 'string', 'in:minimal,balanced,throughput'],
        ]);

        $previousProfile = $this->profiles->activeProfileId();

        try {
            $result = $this->profiles->applyProfile((string) $validated['profile']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'profile' => [$exception->getMessage()],
            ]);
        }

        $this->audit->log('profile.apply', $this->actorId($request), [
            'previous_profile' => $previousProfile,
            'profile' => $result['profile'],
            'applied' => $result['applied'],
        ]);

        return response()->json([
            'message' => 'Queue profile “'.$result['applied']['label'].'” applied. Restart supervisor workers on AWS to pick up new worker counts.',
            'data' => [
                ...$result,
                'snapshot' => $this->queues->snapshot(),
            ],
        ]);
    }

    public function supervisorConfig(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Supervisor configuration generated successfully.',
            'data' => [
                'profile' => $this->profiles->activeProfileId(),
                'config' => $this->profiles->supervisorConfig(),
                'install_path' => '/etc/supervisor/conf.d/seatsbroker-provider.conf',
                'deployment_steps' => [
                    'Apply the desired queue profile in admin (Minimal load recommended for low CPU).',
                    'Copy the generated config to '.$this->supervisorInstallPath().' on the server.',
                    'Run: sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status',
                    'Verify worker processes appear under group seatsbroker-workers.',
                ],
            ],
        ]);
    }

    /** @return list<string> */
    private function allowedQueueNames(): array
    {
        $snapshot = $this->queues->snapshot();

        $names = array_map(
            static fn (array $worker): string => (string) ($worker['value'] ?? ''),
            $snapshot['queues'] ?? [],
        );

        foreach ($snapshot['other_queues'] ?? [] as $row) {
            $names[] = (string) ($row['queue'] ?? '');
        }

        return array_values(array_filter(array_unique($names)));
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    private function supervisorInstallPath(): string
    {
        return '/etc/supervisor/conf.d/seatsbroker-provider.conf';
    }
}
