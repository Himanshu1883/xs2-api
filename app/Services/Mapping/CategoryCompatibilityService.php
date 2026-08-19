<?php

namespace App\Services\Mapping;

use App\Models\Xs2Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CategoryCompatibilityService
{
    /** @var list<string> */
    private const FOOTBALL_SPORTS_GROUP = ['football', 'sports'];

    /** @var array<string, string> */
    private const XS2_SPORT_TYPE_ALIASES = [
        'soccer' => 'football',
    ];

    /** @return list<string> */
    public function getCompatibleCategories(string $xs2Category): array
    {
        $normalized = $this->normalizeCategoryName($xs2Category);
        if ($normalized === '') {
            return [];
        }

        if ($this->isFootballSportsCategory($normalized)) {
            return ['Football', 'Sports'];
        }

        return [$this->displayCategoryName($xs2Category)];
    }

    public function isCompatibleCategory(string $xs2Category, string $localCategory): bool
    {
        $xs2 = $this->normalizeCategoryName($xs2Category);
        $local = $this->normalizeCategoryName($localCategory);

        if ($xs2 === '' || $local === '') {
            return false;
        }

        if ($xs2 === $local) {
            return true;
        }

        return $this->isFootballSportsCategory($xs2) && $this->isFootballSportsCategory($local);
    }

    /** @return list<int> */
    public function compatibleCategoryIdsForCategoryId(int $categoryId): array
    {
        if ($categoryId < 1 || ! Schema::hasTable('game_category')) {
            return [$categoryId];
        }

        $categoryName = $this->categoryNameForId($categoryId);
        if ($categoryName === null) {
            return [$categoryId];
        }

        return $this->categoryIdsForCompatibleNames($this->getCompatibleCategories($categoryName));
    }

    /**
     * Resolve compatible local category IDs for an XS2 event.
     *
     * @return list<int>|null Null when the XS2 category cannot be resolved and
     *                         the candidate search should remain unchanged.
     */
    public function compatibleCategoryIdsForXs2Event(Xs2Event $xs2Event): ?array
    {
        $categoryName = $this->resolveXs2EventCategory($xs2Event);
        if ($categoryName === null) {
            return null;
        }

        if (! Schema::hasTable('game_category') || ! Schema::hasColumn('match_info', 'category')) {
            return null;
        }

        $ids = $this->categoryIdsForCompatibleNames($this->getCompatibleCategories($categoryName));

        return $ids === [] ? null : $ids;
    }

    public function resolveXs2EventCategory(Xs2Event $xs2Event): ?string
    {
        $sportType = trim((string) ($xs2Event->sport_type ?? ''));
        if ($sportType === '') {
            return null;
        }

        $normalized = $this->normalizeCategoryName($sportType);
        $normalized = self::XS2_SPORT_TYPE_ALIASES[$normalized] ?? $normalized;

        return $this->displayCategoryName($normalized);
    }

    public function categoryNameForId(int $categoryId): ?string
    {
        if ($categoryId < 1 || ! Schema::hasTable('game_category')) {
            return null;
        }

        $hasTranslations = Schema::hasTable('game_category_lang')
            && Schema::hasColumns('game_category_lang', ['game_cat_id', 'category_name', 'language']);
        $query = DB::table('game_category as categories')
            ->where('categories.id', $categoryId);

        if ($hasTranslations) {
            $query->leftJoin('game_category_lang as labels', function ($join): void {
                $join->on('labels.game_cat_id', '=', 'categories.id')
                    ->where('labels.language', '=', 'en');
            })->selectRaw("COALESCE(NULLIF(labels.category_name, ''), NULLIF(categories.category_name, '')) as name");
        } else {
            $query->selectRaw("NULLIF(categories.category_name, '') as name");
        }

        $name = $query->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    public function normalizeCategoryName(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))));
    }

    /** @param list<string> $names @return list<int> */
    private function categoryIdsForCompatibleNames(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $normalized = $this->normalizeCategoryName($name);
            if ($normalized === '') {
                continue;
            }

            $query = DB::table('game_category')->select('id');
            if (Schema::hasColumn('game_category', 'status')) {
                $query->where('status', 1);
            }

            $matched = $query
                ->whereRaw('LOWER(category_name) = ?', [$normalized])
                ->pluck('id');

            foreach ($matched as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function isFootballSportsCategory(string $normalizedCategory): bool
    {
        return in_array($normalizedCategory, self::FOOTBALL_SPORTS_GROUP, true);
    }

    private function displayCategoryName(string $value): string
    {
        $normalized = $this->normalizeCategoryName($value);

        return match ($normalized) {
            'football' => 'Football',
            'sports' => 'Sports',
            default => Str::title($normalized),
        };
    }
}
