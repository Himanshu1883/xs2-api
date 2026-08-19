<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\AdminEventSearchRequest;
use App\Http\Resources\AdminEventSearchResource;
use App\Models\EventMapping;
use App\Services\Xs2\AdminEventSearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminEventSearchController extends Controller
{
    public function __construct(private readonly AdminEventSearchService $eventSearch) {}

    public function index(AdminEventSearchRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EventMapping::class);

        return AdminEventSearchResource::collection($this->eventSearch->search($request->validated()));
    }
}
