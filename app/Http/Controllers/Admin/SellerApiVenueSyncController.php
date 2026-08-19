<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Services\SellerApi\SellerVenueCatalogSyncService;
use Illuminate\Http\JsonResponse;

class SellerApiVenueSyncController extends Controller
{
    public function sync(SellerVenueCatalogSyncService $sync): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $summary = $sync->sync();

        return response()->json([
            'message' => sprintf(
                'Synced Seatsbrokers catalogue: %d venue(s) seen (%d created, %d updated), %d categor%s created, %d section(s) created, %d section(s) updated.',
                $summary['venues_seen'],
                $summary['venues_created'],
                $summary['venues_updated'],
                $summary['categories_created'],
                $summary['categories_created'] === 1 ? 'y' : 'ies',
                $summary['sections_created'],
                $summary['sections_updated'],
            ),
            'data' => $summary,
        ]);
    }
}
