<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\BulkConfirmCategoryMappingRequest;
use App\Http\Requests\Xs2\BulkRemoveCategoryMappingRequest;
use App\Http\Requests\Xs2\ConfirmCategoryMappingRequest;
use App\Http\Requests\Xs2\MappingIndexRequest;
use App\Http\Resources\Xs2CategoryMappingResource;
use App\Jobs\ResolvePendingXs2Listings;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2StadiumMapping;
use App\Services\Mapping\LegacyMasterDataSchema;
use App\Services\Mapping\StadiumCategoryMappingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Xs2CategoryMappingController extends Controller
{
    public function index(MappingIndexRequest $request, LegacyMasterDataSchema $schema)
    {
        $filters = $request->validated();

        // XS2 sends categories scoped per event, so the same physical stadium
        // category (e.g. "Box Hospitality Shortside") gets one row per event
        // at that venue. Grouping is the default view so the operator sees one
        // entry per unique category per stadium instead of one per event; the
        // frontend drills into the ungrouped, per-event rows (group=0 +
        // category_name) to actually confirm/change/ignore a mapping.
        if ($request->boolean('group', true)) {
            return $this->groupedIndex($filters, $schema, $request);
        }

        $query = Xs2CategoryMapping::query()->with(['category.context'])->withCount('details');
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['stadium_id'] ?? null, fn ($query, $id) => $query->where('stadium_id', $id));
        $query->when($filters['stadium_search'] ?? null, fn ($query, $search) => $query->whereIn('stadium_id', $schema->stadiumIdsMatchingName($search) ?: [0]));
        $query->when($filters['venue_id'] ?? null, fn ($query, $id) => $query->whereHas('category.context', fn ($context) => $context->where('external_venue_id', $id)));
        $query->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('category', fn ($category) => $category->where('category_name', 'like', "%{$search}%")));
        $query->when($filters['category_name'] ?? null, fn ($query, $name) => $query->whereHas('category', fn ($category) => $category->where('category_name', $name)));

        return Xs2CategoryMappingResource::collection($query->latest()->paginate($filters['per_page'] ?? 20));
    }

    /** @param  array<string,mixed>  $filters */
    private function groupedIndex(array $filters, LegacyMasterDataSchema $schema, MappingIndexRequest $request): JsonResponse
    {
        $base = DB::table('xs2_category_mappings as m')
            ->join('xs2_categories as c', 'c.id', '=', 'm.xs2_category_id')
            ->leftJoin('xs2_category_contexts as ctx', 'ctx.xs2_category_id', '=', 'c.id')
            ->leftJoin('xs2_venues as v', 'v.external_venue_id', '=', 'ctx.external_venue_id');

        // Only tickets blocked on category mapping. pending_stadium_mapping can
        // linger after the venue is already mapped (async resolve lag), which
        // made venue detail look "pending" while the category page showed Mapped.
        if (Schema::hasTable('xs2_ticket_mapping_states')) {
            $pending = DB::table('xs2_ticket_mapping_states')
                ->select('xs2_category_mapping_id', DB::raw('COUNT(*) as pending'))
                ->where('mapping_status', 'pending_category_mapping')
                ->groupBy('xs2_category_mapping_id');
            $base->leftJoinSub($pending, 'pts', 'pts.xs2_category_mapping_id', '=', 'm.id');
        }

        $base->when($filters['status'] ?? null, fn ($query, $status) => $query->where('m.status', $status));
        $base->when($filters['stadium_id'] ?? null, fn ($query, $id) => $query->where('m.stadium_id', $id));
        $base->when($filters['stadium_search'] ?? null, fn ($query, $search) => $query->whereIn('m.stadium_id', $schema->stadiumIdsMatchingName($search) ?: [0]));
        $base->when($filters['venue_id'] ?? null, fn ($query, $id) => $query->where('ctx.external_venue_id', $id));
        $base->when($filters['search'] ?? null, fn ($query, $search) => $query->where('c.category_name', 'like', "%{$search}%"));

        $base->selectRaw('m.stadium_id, ctx.external_venue_id, c.category_name')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN m.status = 'mapped' THEN 1 ELSE 0 END) as mapped_count")
            ->selectRaw("SUM(CASE WHEN m.status = 'pending_category_mapping' THEN 1 ELSE 0 END) as pending_category_count")
            ->selectRaw("SUM(CASE WHEN m.status = 'pending_stadium_mapping' THEN 1 ELSE 0 END) as pending_stadium_count")
            ->selectRaw("SUM(CASE WHEN m.status = 'unmatched' THEN 1 ELSE 0 END) as unmatched_count")
            ->selectRaw("SUM(CASE WHEN m.status = 'ignored' THEN 1 ELSE 0 END) as ignored_count")
            ->selectRaw("SUM(CASE WHEN m.status = 'unsupported' THEN 1 ELSE 0 END) as unsupported_count")
            ->selectRaw('MAX(ctx.category_type) as category_type')
            ->selectRaw('MAX(v.venue_name) as venue_name')
            ->selectRaw(Schema::hasTable('xs2_ticket_mapping_states') ? 'COALESCE(SUM(pts.pending), 0) as pending_tickets' : '0 as pending_tickets')
            ->groupBy('m.stadium_id', 'ctx.external_venue_id', 'c.category_name');

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        $total = DB::query()->fromSub($base, 'grouped')->count();
        $rows = (clone $base)->orderBy('c.category_name')->forPage($page, $perPage)->get();

        $stadiumIds = $rows->pluck('stadium_id')->filter()->unique()->values();
        $stadiumNames = $stadiumIds->mapWithKeys(function ($id) use ($schema) {
            $stadium = $schema->stadiumById((int) $id);

            return [(int) $id => $stadium ? $schema->stadiumName($stadium) : null];
        });

        $mappedBlocks = $this->mappedBlocksForGroups($rows);

        $data = $rows->map(function (object $row) use ($stadiumNames, $mappedBlocks): array {
            $stadiumId = $row->stadium_id !== null ? (int) $row->stadium_id : null;
            $blocks = $mappedBlocks[$this->groupKey($stadiumId, $row->external_venue_id, $row->category_name)] ?? ['category' => null, 'block' => null, 'sections' => [], 'scope' => null];

            return [
                'stadium_id' => $stadiumId,
                'stadium_name' => $stadiumId !== null ? ($stadiumNames[$stadiumId] ?? null) : null,
                'external_venue_id' => $row->external_venue_id,
                'venue_name' => $row->venue_name,
                'category_name' => $row->category_name,
                'category_type' => $row->category_type,
                'total_count' => (int) $row->total_count,
                'mapped_count' => (int) $row->mapped_count,
                'pending_category_count' => (int) $row->pending_category_count,
                'pending_stadium_count' => (int) $row->pending_stadium_count,
                'unmatched_count' => (int) $row->unmatched_count,
                'ignored_count' => (int) $row->ignored_count,
                'unsupported_count' => (int) $row->unsupported_count,
                'pending_tickets' => (int) $row->pending_tickets,
                'mapped_category' => $blocks['category'],
                'mapped_block' => $blocks['block'],
                'mapped_sections' => $blocks['sections'],
                'mapped_scope' => $blocks['scope'],
            ];
        })->values();

        $lastPage = $perPage > 0 ? (int) max(1, ceil($total / $perPage)) : 1;

        return response()->json([
            'data' => $data,
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * A category mapping can span several stadium_details rows (e.g. a tier
     * covering multiple named blocks). For the grouped list we distinguish:
     * - category scope (stadium_seat_id set, stadium_detail_id null) → show
     *   the seatsbroker category only, never a forced "main" block
     * - section scope (stadium_detail_id set) → show that block/section
     * - legacy rows with neither column set → keep the previous "main block"
     *   heuristic for already-stored mappings
     *
     * @param  Collection<int,object>  $rows
     * @return array<string,array{category:?string,block:?string,sections:string[],scope:?string}>
     */
    private function mappedBlocksForGroups($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $detailRows = DB::table('xs2_category_mapping_details as d')
            ->join('xs2_category_mappings as m', 'm.id', '=', 'd.xs2_category_mapping_id')
            ->join('xs2_categories as c', 'c.id', '=', 'm.xs2_category_id')
            ->leftJoin('xs2_category_contexts as ctx', 'ctx.xs2_category_id', '=', 'c.id')
            ->where(function ($outer) use ($rows) {
                foreach ($rows as $row) {
                    $outer->orWhere(function ($inner) use ($row) {
                        $row->stadium_id === null ? $inner->whereNull('m.stadium_id') : $inner->where('m.stadium_id', $row->stadium_id);
                        $row->external_venue_id === null ? $inner->whereNull('ctx.external_venue_id') : $inner->where('ctx.external_venue_id', $row->external_venue_id);
                        $inner->where('c.category_name', $row->category_name);
                    });
                }
            })
            ->select(
                'm.stadium_id',
                'm.stadium_seat_id as mapping_stadium_seat_id',
                'm.stadium_detail_id as mapping_stadium_detail_id',
                'm.mapping_method',
                'ctx.external_venue_id',
                'c.category_name',
                'd.stadium_seat_name',
                'd.stadium_detail_id',
                'd.block',
                'd.section',
            )
            ->distinct()
            ->get();

        $byGroup = [];
        foreach ($detailRows as $row) {
            $stadiumId = $row->stadium_id !== null ? (int) $row->stadium_id : null;
            $groupKey = $this->groupKey($stadiumId, $row->external_venue_id, $row->category_name);
            $byGroup[$groupKey]['rows'][] = $row;
            $byGroup[$groupKey]['category'] ??= $row->stadium_seat_name;
        }

        $result = [];
        foreach ($byGroup as $groupKey => $group) {
            $rowsForGroup = $group['rows'];
            $explicitSection = collect($rowsForGroup)->first(fn ($row) => $row->mapping_stadium_detail_id !== null);
            $explicitCategory = collect($rowsForGroup)->first(
                fn ($row) => $row->mapping_stadium_seat_id !== null && $row->mapping_stadium_detail_id === null
            );
            $legacyExactCategory = collect($rowsForGroup)->contains(
                fn ($row) => $row->mapping_stadium_seat_id === null
                    && $row->mapping_stadium_detail_id === null
                    && in_array($row->mapping_method, ['exact_seat_category', 'exact_seat_id'], true)
            );

            if ($explicitSection) {
                $sectionRow = collect($rowsForGroup)->first(
                    fn ($row) => (int) $row->stadium_detail_id === (int) $explicitSection->mapping_stadium_detail_id
                ) ?? $explicitSection;
                $result[$groupKey] = [
                    'category' => $sectionRow->stadium_seat_name ?? $group['category'],
                    'block' => $sectionRow->block,
                    'sections' => $sectionRow->section ? [$sectionRow->section] : [],
                    'scope' => 'section',
                ];

                continue;
            }

            if ($explicitCategory || $legacyExactCategory) {
                $categoryName = ($explicitCategory ?? $rowsForGroup[0])->stadium_seat_name ?? $group['category'];
                $result[$groupKey] = [
                    'category' => $categoryName,
                    'block' => null,
                    'sections' => [],
                    'scope' => 'category',
                ];

                continue;
            }

            // Legacy rows that predate stadium_seat_id / stadium_detail_id scope.
            $byBlock = [];
            foreach ($rowsForGroup as $row) {
                $blockKey = $row->block ?? '';
                $byBlock[$blockKey] ??= ['category' => $row->stadium_seat_name, 'block' => $row->block, 'sections' => []];
                if ($row->section) {
                    $byBlock[$blockKey]['sections'][] = $row->section;
                }
            }
            $blocks = array_values($byBlock);
            usort($blocks, fn (array $a, array $b) => count($b['sections']) <=> count($a['sections']) ?: strcmp((string) $a['block'], (string) $b['block']));
            $main = $blocks[0];

            $result[$groupKey] = [
                'category' => $main['category'],
                'block' => $main['block'],
                'sections' => array_values(array_unique($main['sections'])),
                'scope' => $main['block'] ? 'section' : 'category',
            ];
        }

        return $result;
    }

    private function groupKey(?int $stadiumId, ?string $externalVenueId, ?string $categoryName): string
    {
        return implode('|', [$stadiumId ?? 'null', $externalVenueId ?? 'null', $categoryName ?? 'null']);
    }

    public function show(Xs2CategoryMapping $mapping): Xs2CategoryMappingResource
    {
        return new Xs2CategoryMappingResource($mapping->load(['category.context', 'details']));
    }

    public function confirm(ConfirmCategoryMappingRequest $request, Xs2CategoryMapping $mapping, LegacyMasterDataSchema $schema, StadiumCategoryMappingService $mappings)
    {
        if (! $mapping->stadium_id || $mapping->stadiumMapping?->status !== 'mapped') {
            throw ValidationException::withMessages(['stadium_seat_id' => ['Confirm the parent stadium mapping before choosing a seatsbroker category.']]);
        }

        // Neither field in the request means "confirm the currently resolved
        // set as-is". stadium_seat_id replaces the set with every
        // stadium_details row under the chosen seatsbroker category;
        // stadium_detail_id replaces it with just that one section.
        $override = null;
        $scope = null;
        if ($request->filled('stadium_detail_id')) {
            $detailId = (int) $request->validated('stadium_detail_id');
            $override = collect($schema->stadiumDetailsForStadium((int) $mapping->stadium_id))
                ->where('stadium_detail_id', $detailId)
                ->values();
            if ($override->isEmpty()) {
                throw ValidationException::withMessages(['stadium_detail_id' => ['Select a section belonging to the mapped stadium.']]);
            }
            $scope = $mappings->scopeAttributesForDetails($override->all(), 'manual_section');
        } elseif ($request->filled('stadium_seat_id')) {
            $seatId = (int) $request->validated('stadium_seat_id');
            $override = collect($schema->stadiumDetailsForStadium((int) $mapping->stadium_id))
                ->where('stadium_seat_id', $seatId)
                ->values();
            if ($override->isEmpty()) {
                throw ValidationException::withMessages(['stadium_seat_id' => ['Select a seatsbroker category belonging to the mapped stadium.']]);
            }
            $scope = $mappings->scopeAttributesForDetails($override->all(), 'manual_category');
        } elseif ($mapping->details()->doesntExist()) {
            throw ValidationException::withMessages(['stadium_seat_id' => ['Select a seatsbroker category belonging to the mapped stadium.']]);
        }

        $mapping = DB::transaction(function () use ($mapping, $mappings, $override, $scope): Xs2CategoryMapping {
            $mapping = Xs2CategoryMapping::query()->lockForUpdate()->findOrFail($mapping->id);
            if ($override !== null) {
                $mappings->replaceDetails($mapping, $override->all());
            }
            $mapping->update([
                'status' => 'mapped',
                'mapping_method' => 'manual',
                'manually_confirmed' => true,
                'mapped_at' => now(),
                'mapping_error' => null,
                ...($scope ?? []),
            ]);

            return $mapping;
        });
        ResolvePendingXs2Listings::dispatchAfterMappingChange('category', $mapping->id);

        return (new Xs2CategoryMappingResource($mapping->load(['category.context', 'details'])))
            ->additional(['message' => 'Category mapping confirmed successfully.']);
    }

    public function change(ConfirmCategoryMappingRequest $request, Xs2CategoryMapping $mapping, LegacyMasterDataSchema $schema, StadiumCategoryMappingService $mappings)
    {
        if (! $request->filled('stadium_seat_id') && ! $request->filled('stadium_detail_id')) {
            throw ValidationException::withMessages(['stadium_seat_id' => ['A replacement seatsbroker category or section is required.']]);
        }

        return $this->confirm($request, $mapping, $schema, $mappings);
    }

    /**
     * Apply one seatsbroker category to every eligible per-event mapping in
     * a grouped-list row at once, instead of confirming each event's mapping
     * individually. Mirrors the same (stadium_id, external_venue_id,
     * category_name) grouping key used by groupedIndex().
     */
    public function bulkConfirm(BulkConfirmCategoryMappingRequest $request, LegacyMasterDataSchema $schema, StadiumCategoryMappingService $mappings): JsonResponse
    {
        $data = $request->validated();
        $stadiumId = (int) $data['stadium_id'];
        $categoryName = (string) $data['category_name'];
        $externalVenueId = $data['external_venue_id'] ?? null;

        $matchingCategory = fn (Builder $query) => $this->constrainCategoryGroup($query, $categoryName, $externalVenueId);

        $stadiumMapped = Xs2StadiumMapping::query()
            ->where('stadium_id', $stadiumId)
            ->where('status', 'mapped')
            ->exists();
        if (! $stadiumMapped) {
            throw ValidationException::withMessages(['stadium_seat_id' => ['Confirm the parent stadium mapping before choosing a seatsbroker category.']]);
        }

        if (isset($data['stadium_detail_id'])) {
            $details = collect($schema->stadiumDetailsForStadium($stadiumId))->where('stadium_detail_id', (int) $data['stadium_detail_id'])->values();
            if ($details->isEmpty()) {
                throw ValidationException::withMessages(['stadium_detail_id' => ['Select a section belonging to the mapped stadium.']]);
            }
            $scope = $mappings->scopeAttributesForDetails($details->all(), 'manual_section');
        } else {
            $details = collect($schema->stadiumDetailsForStadium($stadiumId))->where('stadium_seat_id', (int) $data['stadium_seat_id'])->values();
            if ($details->isEmpty()) {
                throw ValidationException::withMessages(['stadium_seat_id' => ['Select a seatsbroker category belonging to the mapped stadium.']]);
            }
            $scope = $mappings->scopeAttributesForDetails($details->all(), 'manual_category');
        }

        // Include rows still pending_stadium_mapping with a null stadium_id when
        // the parent venue mapping is already confirmed — that lag used to hide
        // the SB dropdown and made bulk confirm miss the group entirely.
        $mappingIds = Xs2CategoryMapping::query()
            ->where(function (Builder $query) use ($stadiumId): void {
                $query->where('stadium_id', $stadiumId)
                    ->orWhere(function (Builder $pending) use ($stadiumId): void {
                        $pending->whereNull('stadium_id')
                            ->whereHas('stadiumMapping', fn (Builder $mapping) => $mapping
                                ->where('stadium_id', $stadiumId)
                                ->where('status', 'mapped'));
                    });
            })
            ->whereHas('category', $matchingCategory)
            ->whereNotIn('status', ['ignored', 'unsupported'])
            ->pluck('id');
        if ($mappingIds->isEmpty()) {
            throw ValidationException::withMessages(['stadium_seat_id' => ['No eligible category mappings were found for this group.']]);
        }

        DB::transaction(function () use ($mappingIds, $details, $mappings, $scope, $stadiumId): void {
            Xs2CategoryMapping::query()->whereIn('id', $mappingIds)->lockForUpdate()->get()->each(function (Xs2CategoryMapping $mapping) use ($details, $mappings, $scope, $stadiumId): void {
                $mappings->replaceDetails($mapping, $details->all());
                $mapping->update([
                    'stadium_id' => $stadiumId,
                    'status' => 'mapped',
                    'mapping_method' => 'manual',
                    'manually_confirmed' => true,
                    'mapped_at' => now(),
                    'mapping_error' => null,
                    ...$scope,
                ]);
            });
        });

        foreach ($mappingIds as $mappingId) {
            ResolvePendingXs2Listings::dispatchAfterMappingChange('category', $mappingId);
        }

        $count = $mappingIds->count();

        return response()->json([
            'message' => "Mapped {$count} event".($count === 1 ? '' : 's').' to this seatsbroker category.',
            'meta' => ['updated_count' => $count],
        ]);
    }

    /**
     * Clear a seatsbroker category from every eligible per-event mapping in a
     * grouped-list row at once, reopening them for re-mapping.
     * Mirrors the same grouping key used by bulkConfirm()/groupedIndex().
     *
     * Targets both confirmed (`mapped`) rows and pending rows that still hold
     * suggested stadium-detail rows — the grouped UI surfaces those details as
     * the current SB category/block, so Remove must clear them too.
     */
    public function bulkRemove(BulkRemoveCategoryMappingRequest $request, StadiumCategoryMappingService $mappings): JsonResponse
    {
        $data = $request->validated();
        $stadiumId = (int) $data['stadium_id'];
        $categoryName = (string) $data['category_name'];
        $externalVenueId = $data['external_venue_id'] ?? null;

        $matchingCategory = fn (Builder $query) => $this->constrainCategoryGroup($query, $categoryName, $externalVenueId);

        $mappingIds = Xs2CategoryMapping::query()
            ->where('stadium_id', $stadiumId)
            ->whereHas('category', $matchingCategory)
            ->where(function (Builder $query): void {
                $query->where('status', 'mapped')
                    ->orWhereHas('details');
            })
            ->pluck('id');
        if ($mappingIds->isEmpty()) {
            throw ValidationException::withMessages(['stadium_seat_id' => ['No mapped events were found for this group.']]);
        }

        DB::transaction(function () use ($mappingIds, $mappings): void {
            Xs2CategoryMapping::query()->whereIn('id', $mappingIds)->lockForUpdate()->get()->each(function (Xs2CategoryMapping $mapping) use ($mappings): void {
                $mappings->replaceDetails($mapping, []);
                $mapping->update([
                    'status' => 'pending_category_mapping',
                    'mapping_method' => 'manual',
                    'manually_confirmed' => false,
                    'mapped_at' => null,
                    'mapping_error' => null,
                    'stadium_seat_id' => null,
                    'stadium_detail_id' => null,
                ]);
            });
        });

        foreach ($mappingIds as $mappingId) {
            ResolvePendingXs2Listings::dispatchAfterMappingChange('category', $mappingId);
        }

        $count = $mappingIds->count();

        return response()->json([
            'message' => "Removed the seatsbroker category mapping from {$count} event".($count === 1 ? '' : 's').'.',
            'meta' => ['updated_count' => $count],
        ]);
    }

    private function constrainCategoryGroup(Builder $query, string $categoryName, ?string $externalVenueId): void
    {
        $query->where('category_name', $categoryName);

        if ($externalVenueId !== null) {
            $query->whereHas('context', fn (Builder $context) => $context->where('external_venue_id', $externalVenueId));

            return;
        }

        $query->where(function (Builder $query): void {
            $query->whereDoesntHave('context')
                ->orWhereHas('context', fn (Builder $context) => $context->whereNull('external_venue_id'));
        });
    }

    public function ignore(ConfirmCategoryMappingRequest $request, Xs2CategoryMapping $mapping, StadiumCategoryMappingService $mappings)
    {
        $mapping = DB::transaction(function () use ($mapping, $mappings): Xs2CategoryMapping {
            $mapping = Xs2CategoryMapping::query()->lockForUpdate()->findOrFail($mapping->id);
            $mappings->replaceDetails($mapping, []);
            $mapping->update([
                'status' => 'ignored',
                'mapping_method' => 'manual',
                'manually_confirmed' => true,
                'mapped_at' => now(),
                'mapping_error' => null,
                'stadium_seat_id' => null,
                'stadium_detail_id' => null,
            ]);

            return $mapping;
        });
        ResolvePendingXs2Listings::dispatchAfterMappingChange('category', $mapping->id);

        return (new Xs2CategoryMappingResource($mapping->load(['category.context', 'details'])))
            ->additional(['message' => 'Category mapping ignored successfully.']);
    }
}
