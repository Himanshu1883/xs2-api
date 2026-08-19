<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminEventCatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        if (! Schema::hasTable('game_category')) {
            return response()->json([
                'message' => 'Event categories retrieved successfully.',
                'data' => [],
            ]);
        }

        $hasTranslations = Schema::hasTable('game_category_lang')
            && Schema::hasColumns('game_category_lang', ['game_cat_id', 'category_name', 'language']);
        $query = DB::table('game_category as categories')->select('categories.id');

        if ($hasTranslations) {
            $query->leftJoin('game_category_lang as labels', function ($join): void {
                $join->on('labels.game_cat_id', '=', 'categories.id')
                    ->where('labels.language', '=', 'en');
            })->selectRaw("COALESCE(NULLIF(labels.category_name, ''), NULLIF(categories.category_name, ''), CONCAT('Category #', categories.id)) as name");
        } else {
            $query->selectRaw("COALESCE(NULLIF(categories.category_name, ''), CONCAT('Category #', categories.id)) as name");
        }

        if (Schema::hasColumn('game_category', 'status')) {
            $query->where('categories.status', 1);
        }

        return response()->json([
            'message' => 'Event categories retrieved successfully.',
            'data' => $query->orderBy('name')->get(),
        ]);
    }

    public function tournaments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);
        $categoryId = $request->integer('category_id');

        if ($categoryId < 1) {
            return response()->json([
                'message' => 'A valid event category is required.',
                'errors' => ['category_id' => ['A valid event category is required.']],
            ], 422);
        }

        if (! Schema::hasTable('tournament')) {
            return response()->json([
                'message' => 'Event tournaments retrieved successfully.',
                'data' => [],
            ]);
        }

        $hasTranslations = Schema::hasTable('tournament_lang')
            && Schema::hasColumns('tournament_lang', ['tournament_id', 'tournament_name', 'language']);
        $query = DB::table('tournament as tournaments')
            ->select('tournaments.t_id as id')
            ->where('tournaments.category', $categoryId);

        if ($hasTranslations) {
            $query->leftJoin('tournament_lang as labels', function ($join): void {
                $join->on('labels.tournament_id', '=', 'tournaments.t_id')
                    ->where('labels.language', '=', 'en');
            })->selectRaw("COALESCE(NULLIF(labels.tournament_name, ''), NULLIF(tournaments.tournament_name, ''), CONCAT('Tournament #', tournaments.t_id)) as name");
        } else {
            $query->selectRaw("COALESCE(NULLIF(tournaments.tournament_name, ''), CONCAT('Tournament #', tournaments.t_id)) as name");
        }

        if (Schema::hasColumn('tournament', 'status')) {
            $query->where('tournaments.status', 1);
        }

        return response()->json([
            'message' => 'Event tournaments retrieved successfully.',
            'data' => $query->orderBy('name')->get(),
        ]);
    }

    /**
     * Safe local reference choices for the create-event mapping workflow.
     * Supplier labels are intentionally never accepted as local foreign keys.
     */
    public function teams(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return $this->referenceOptions(
            $request,
            'teams',
            ['id', 'team_id'],
            ['team_name', 'name'],
            'Local teams retrieved successfully.',
        );
    }

    public function cities(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return $this->referenceOptions(
            $request,
            'cities',
            ['id', 'city_id'],
            ['name', 'city_name'],
            'Local cities retrieved successfully.',
        );
    }

    public function venues(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $table = Schema::hasTable('stadium')
            ? 'stadium'
            : (Schema::hasTable('stadiums') ? 'stadiums' : null);

        if ($table === null) {
            return response()->json([
                'message' => 'Local venues retrieved successfully.',
                'data' => [],
            ]);
        }

        return $this->referenceOptions(
            $request,
            $table,
            ['s_id', 'stadium_id', 'id'],
            ['stadium_name', 'name', 'title'],
            'Local venues retrieved successfully.',
            ['city_id', 'city'],
        );
    }

    /**
     * @param  list<string>  $idCandidates
     * @param  list<string>  $nameCandidates
     * @param  list<string>|null  $cityCandidates
     */
    private function referenceOptions(
        Request $request,
        string $table,
        array $idCandidates,
        array $nameCandidates,
        string $message,
        ?array $cityCandidates = null,
    ): JsonResponse {
        if (! Schema::hasTable($table)) {
            return response()->json(['message' => $message, 'data' => []]);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $columns = Schema::getColumnListing($table);
        $id = $this->firstColumn($columns, $idCandidates);
        $name = $this->firstColumn($columns, $nameCandidates);

        if ($id === null || $name === null) {
            return response()->json(['message' => $message, 'data' => []]);
        }

        $query = DB::table($table)
            ->select("{$id} as id", "{$name} as name")
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where($name, 'like', "%{$search}%"));

        if ($cityCandidates !== null && isset($validated['city_id'])) {
            $cityColumn = $this->firstColumn($columns, $cityCandidates);
            if ($cityColumn === null) {
                return response()->json(['message' => $message, 'data' => []]);
            }

            $query->where($cityColumn, $validated['city_id']);
        }

        if (in_array('status', $columns, true)) {
            $query->where('status', 1);
        }

        return response()->json([
            'message' => $message,
            'data' => $query->orderBy($name)->limit($validated['limit'] ?? 20)->get(),
        ]);
    }

    /** @param list<string> $columns @param list<string> $candidates */
    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
