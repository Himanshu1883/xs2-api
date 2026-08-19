<?php

namespace App\Services\Mapping;

use App\DTOs\Xs2\CategoryGroupMatchResult;
use App\Models\Xs2Category;
use Illuminate\Support\Collection;

/**
 * An XS2 category maps to exactly one seatsbroker category (one
 * stadium_seats entry), never a swept-in family of several. Resolution
 * order, first match wins:
 *
 *   1. exact_seat_id      - category_name is numeric and equals a
 *                            stadium_seat_id directly.
 *   2. exact_seat_category - normalized category_name equals a seat-category
 *                            group's normalized name.
 *   3. fuzzy fallback      - StadiumDetailMatchScorer's single-best comparison
 *                            against individual detail rows, for categories
 *                            that are themselves specific block names rather
 *                            than tier labels.
 */
class StadiumDetailGroupMatcher
{
    public function __construct(
        private readonly MappingTextNormalizer $text,
        private readonly StadiumDetailMatchScorer $scorer,
    ) {}

    /** @param  list<array<string,mixed>>  $details  stadium-scoped rows from LegacyMasterDataSchema::stadiumDetailsForStadium() */
    public function match(Xs2Category $category, array $details): ?CategoryGroupMatchResult
    {
        if ($details === []) {
            return null;
        }

        [$requested] = explode('_', (string) $category->category_name, 2);
        $requested = trim($requested);
        $groups = $this->groupBySeatCategory($details);

        if ($requested !== '' && ctype_digit($requested)) {
            $byId = $groups->get((int) $requested);
            if ($byId !== null) {
                return $this->fromFamily(collect([$byId]), 100.0, 'exact_seat_id', ['exact_seat_id']);
            }
        }

        $requestedNormalized = $this->text->normalizeCategory($requested);
        if ($requestedNormalized !== '') {
            $exact = $groups->filter(fn (array $group): bool => $group['normalized'] === $requestedNormalized);
            if ($exact->isNotEmpty()) {
                return $this->fromFamily($exact, 100.0, 'exact_seat_category', ['exact_seat_category_name']);
            }
        }

        return $this->fuzzyFallback($category, $details);
    }

    /**
     * @param  list<array<string,mixed>>  $details
     * @return Collection<int, array{stadium_seat_id:int,stadium_seat_name:string,normalized:string,details:list<array<string,mixed>>}>
     */
    private function groupBySeatCategory(array $details): Collection
    {
        return collect($details)
            ->filter(fn (array $detail): bool => $detail['stadium_seat_id'] !== null && filled($detail['stadium_seat_name'] ?? null))
            ->groupBy('stadium_seat_id')
            ->map(function (Collection $rows, int|string $seatId): array {
                $name = (string) $rows->first()['stadium_seat_name'];

                return [
                    'stadium_seat_id' => (int) $seatId,
                    'stadium_seat_name' => $name,
                    'normalized' => $this->text->normalizeCategory($name),
                    'details' => $rows->all(),
                ];
            });
    }

    /**
     * @param  Collection<int, array{stadium_seat_id:int,stadium_seat_name:string,normalized:string,details:list<array<string,mixed>>}>  $family
     * @param  list<string>  $matchedFields
     */
    private function fromFamily(Collection $family, float $score, string $method, array $matchedFields): CategoryGroupMatchResult
    {
        $details = $family->flatMap(fn (array $group): array => $group['details'])->values()->all();

        return new CategoryGroupMatchResult(
            score: $score,
            method: $method,
            matchedFields: $matchedFields,
            details: $this->toDetailRows($details),
            candidateGroups: $family
                ->sortByDesc(fn (array $group): int => count($group['details']))
                ->map(fn (array $group): array => [
                    'stadium_detail_id' => null,
                    'stadium_seat_id' => $group['stadium_seat_id'],
                    'stadium_seat_name' => $group['stadium_seat_name'],
                    'score' => $score,
                    'mapping_method' => $method,
                    'matched_fields' => $matchedFields,
                    'detail_count' => count($group['details']),
                    'block' => data_get($group['details'], '0.block'),
                    'section' => data_get($group['details'], '0.section'),
                    'name' => data_get($group['details'], '0.name'),
                ])
                ->values()
                ->all(),
        );
    }

    /** @param  list<array<string,mixed>>  $details */
    private function fuzzyFallback(Xs2Category $category, array $details): ?CategoryGroupMatchResult
    {
        $scored = collect($details)
            ->map(fn (array $detail) => $this->scorer->score($category, $detail))
            ->sortByDesc(fn ($result) => $result->score)
            ->take(5)
            ->values();
        $top = $scored->first();
        if (! $top || $top->stadiumDetailId === null) {
            return null;
        }

        return new CategoryGroupMatchResult(
            score: $top->score,
            method: $top->method,
            matchedFields: $top->matchedFields,
            details: [[
                'stadium_detail_id' => $top->stadiumDetailId,
                'stadium_seat_id' => $top->stadiumSeatId,
                'block' => $top->candidate['block'] ?? null,
                'section' => $top->candidate['section'] ?? null,
                'name' => $top->candidate['name'] ?? null,
                'stadium_seat_name' => $top->candidate['stadium_seat_name'] ?? null,
            ]],
            candidateGroups: $scored->map(fn ($result) => [
                'stadium_detail_id' => $result->stadiumDetailId,
                'stadium_seat_id' => $result->stadiumSeatId,
                'stadium_seat_name' => $result->candidate['stadium_seat_name'] ?? null,
                'score' => $result->score,
                'mapping_method' => $result->method,
                'matched_fields' => $result->matchedFields,
                'detail_count' => 1,
                'block' => $result->candidate['block'] ?? null,
                'section' => $result->candidate['section'] ?? null,
                'name' => $result->candidate['name'] ?? null,
            ])->values()->all(),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $details
     * @return list<array<string,mixed>>
     */
    private function toDetailRows(array $details): array
    {
        return array_values(array_map(fn (array $detail): array => [
            'stadium_detail_id' => $detail['stadium_detail_id'],
            'stadium_seat_id' => $detail['stadium_seat_id'],
            'block' => $detail['block'] ?? null,
            'section' => $detail['section'] ?? null,
            'name' => $detail['name'] ?? null,
            'stadium_seat_name' => $detail['stadium_seat_name'] ?? null,
        ], $details));
    }
}
