<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Services\Admin\CronJobManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCronJobController extends Controller
{
    public function __construct(private readonly CronJobManagementService $cronJobs) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Scheduled cron jobs retrieved successfully.',
            'data' => $this->cronJobs->listJobs(),
        ]);
    }

    public function logs(Request $request, string $cronJobId): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $limit = max(1, min(50, (int) $request->query('limit', 10)));

        return response()->json([
            'message' => 'Cron execution logs retrieved successfully.',
            'data' => $this->cronJobs->logsForJob($cronJobId, $limit),
        ]);
    }

    public function run(string $cronJobId): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $result = $this->cronJobs->runNow($cronJobId);

        return response()->json([
            'message' => (string) ($result['message'] ?? 'Cron job triggered successfully.'),
            'data' => $result,
        ], 202);
    }
}
