<?php

namespace App\Services\Mapping;

use App\Models\Xs2Category;
use App\Models\Xs2CategoryMapping;
use App\Models\Xs2CategoryMappingDetail;
use App\Models\Xs2StadiumMapping;
use Illuminate\Support\Facades\DB;

class StadiumCategoryMappingService
{
    public function __construct(
        private readonly LegacyMasterDataSchema $schema,
        private readonly StadiumDetailGroupMatcher $matcher,
    ) {}

    public function resolve(
        Xs2Category $category,
        Xs2StadiumMapping $stadiumMapping,
        ?float $autoMapThreshold = null,
    ): Xs2CategoryMapping {
        return DB::transaction(function () use ($category, $stadiumMapping, $autoMapThreshold): Xs2CategoryMapping {
            $mapping = Xs2CategoryMapping::query()
                ->where('xs2_category_id', $category->id)
                ->lockForUpdate()
                ->firstOrNew(['xs2_category_id' => $category->id]);

            $belongsToStadium = $mapping->exists && $this->belongsToStadium($mapping, $stadiumMapping);
            if ($mapping->exists && $mapping->manually_confirmed && $mapping->status === 'ignored') {
                if (! $belongsToStadium) {
                    return $this->save($mapping, [
                        'xs2_stadium_mapping_id' => $stadiumMapping->id,
                        'stadium_id' => $stadiumMapping->stadium_id,
                    ], []);
                }

                return $mapping;
            }

            if ($mapping->exists && $mapping->manually_confirmed && $belongsToStadium) {
                return $mapping;
            }

            $base = [
                'xs2_stadium_mapping_id' => $stadiumMapping->id,
                'stadium_id' => $stadiumMapping->stadium_id,
            ];
            if ($mapping->exists && $mapping->manually_confirmed && ! $belongsToStadium) {
                $base['manually_confirmed'] = false;
            }
            if ($stadiumMapping->status !== 'mapped' || ! $stadiumMapping->stadium_id) {
                return $this->save($mapping, [...$base,
                    'status' => 'pending_stadium_mapping',
                    'confidence_score' => null,
                    'mapping_method' => null,
                    'matched_fields' => [],
                    'candidate_scores' => [],
                    'mapped_at' => null,
                    'mapping_error' => 'The venue does not yet have a confirmed stadium mapping.',
                    'stadium_seat_id' => null,
                    'stadium_detail_id' => null,
                ], []);
            }

            $category->loadMissing('context');
            $type = strtolower((string) ($category->context?->category_type ?? ''));
            if ($this->isUnsupportedType($type)) {
                return $this->save($mapping, [...$base,
                    'status' => 'unsupported',
                    'confidence_score' => null,
                    'mapping_method' => 'category_type',
                    'matched_fields' => ['category_type' => $type],
                    'candidate_scores' => [],
                    'mapped_at' => null,
                    'mapping_error' => 'The XS2 category type is not ordinary ticket inventory.',
                    'stadium_seat_id' => null,
                    'stadium_detail_id' => null,
                ], []);
            }

            $rawDetails = $this->schema->stadiumDetailsForStadium((int) $stadiumMapping->stadium_id);
            $result = $this->matcher->match($category, $rawDetails);
            if (! $result) {
                return $this->save($mapping, [...$base,
                    'status' => 'unmatched',
                    'confidence_score' => null,
                    'mapping_method' => null,
                    'matched_fields' => [],
                    'candidate_scores' => [],
                    'mapped_at' => null,
                    'mapping_error' => 'The mapped stadium has no compatible stadium details.',
                ], []);
            }

            $attributes = [...$base,
                'confidence_score' => $result->score,
                'mapping_method' => $result->method,
                'matched_fields' => $result->matchedFields,
                'candidate_scores' => $result->candidateGroups,
                'mapped_at' => null,
                'mapping_error' => null,
                ...$this->scopeAttributesForDetails($result->details, $result->method),
            ];

            if ($this->detailsClaimedElsewhere($result->details, $mapping->id, (int) $stadiumMapping->stadium_id, (int) $category->xs2_event_id)) {
                return $this->save($mapping, [...$attributes,
                    'status' => 'pending_category_mapping',
                    'mapping_error' => 'One or more matched stadium details are already claimed by another category mapping for this stadium.',
                ], $result->details);
            }

            if ($result->score >= $this->autoMapThreshold($autoMapThreshold)) {
                return $this->save($mapping, [...$attributes, 'status' => 'mapped', 'mapped_at' => now()], $result->details);
            }

            if ($result->score >= (float) config('xs2.mapping.category_pending_threshold', 80)) {
                return $this->save($mapping, [...$attributes, 'status' => 'pending_category_mapping', 'mapping_error' => 'Administrator confirmation is required.'], $result->details);
            }

            return $this->save($mapping, [...$attributes,
                'status' => 'unmatched',
                'mapping_error' => 'No stadium detail met the pending confidence threshold.',
                'stadium_seat_id' => null,
                'stadium_detail_id' => null,
            ], []);
        });
    }

    /**
     * Category-level matches (exact seat category / seat id) keep stadium_seat_id
     * and leave stadium_detail_id null so the admin UI can show "category only".
     * Section/block matches pin both IDs to the chosen stadium_details row.
     *
     * @param  list<array<string,mixed>>  $details
     * @return array{stadium_seat_id:?int,stadium_detail_id:?int}
     */
    public function scopeAttributesForDetails(array $details, ?string $method = null): array
    {
        if ($details === []) {
            return ['stadium_seat_id' => null, 'stadium_detail_id' => null];
        }

        $seatId = isset($details[0]['stadium_seat_id']) && is_numeric($details[0]['stadium_seat_id'])
            ? (int) $details[0]['stadium_seat_id']
            : null;
        $detailId = isset($details[0]['stadium_detail_id']) && is_numeric($details[0]['stadium_detail_id'])
            ? (int) $details[0]['stadium_detail_id']
            : null;

        if (in_array($method, ['exact_seat_category', 'exact_seat_id', 'manual_category'], true)) {
            return ['stadium_seat_id' => $seatId, 'stadium_detail_id' => null];
        }

        if (in_array($method, ['manual_section'], true) || count($details) === 1) {
            return ['stadium_seat_id' => $seatId, 'stadium_detail_id' => $detailId];
        }

        return ['stadium_seat_id' => $seatId, 'stadium_detail_id' => null];
    }

    private function isUnsupportedType(string $type): bool
    {
        if ($type === '' || in_array($type, ['grandstand', 'generaladmission', 'hospitality', 'offsite_hospitality'], true)) {
            return false;
        }

        return true;
    }

    private function belongsToStadium(Xs2CategoryMapping $mapping, Xs2StadiumMapping $stadiumMapping): bool
    {
        return (int) $mapping->xs2_stadium_mapping_id === (int) $stadiumMapping->id
            && $this->sameNullableId($mapping->stadium_id, $stadiumMapping->stadium_id);
    }

    private function sameNullableId(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }

    /** @param  list<array<string,mixed>>  $details */
    private function detailsClaimedElsewhere(array $details, ?int $excludeMappingId, int $stadiumId, int $eventId): bool
    {
        $detailIds = array_column($details, 'stadium_detail_id');
        if ($detailIds === []) {
            return false;
        }

        return Xs2CategoryMappingDetail::query()
            ->whereIn('stadium_detail_id', $detailIds)
            ->whereHas('categoryMapping', function ($query) use ($excludeMappingId, $stadiumId, $eventId): void {
                $query->where('stadium_id', $stadiumId);
                if ($excludeMappingId !== null) {
                    $query->where('id', '!=', $excludeMappingId);
                }
                $query->whereHas('category', fn ($category) => $category->where('xs2_event_id', $eventId));
            })
            ->exists();
    }

    /** @param  array<string,mixed>  $attributes */
    private function save(Xs2CategoryMapping $mapping, array $attributes, array $details): Xs2CategoryMapping
    {
        if ($details === []) {
            $attributes += ['stadium_seat_id' => null, 'stadium_detail_id' => null];
        }

        $mapping->fill($attributes);
        $mapping->save();
        $this->replaceDetails($mapping, $details);

        return $mapping;
    }

    /** @param  list<array<string,mixed>>  $details */
    public function replaceDetails(Xs2CategoryMapping $mapping, array $details): void
    {
        $mapping->details()->delete();
        if ($details === []) {
            return;
        }

        $now = now();
        $rows = array_map(static fn (array $detail): array => [
            'xs2_category_mapping_id' => $mapping->id,
            'stadium_detail_id' => $detail['stadium_detail_id'],
            'stadium_seat_id' => $detail['stadium_seat_id'] ?? null,
            'block' => $detail['block'] ?? null,
            'section' => $detail['section'] ?? null,
            'name' => $detail['name'] ?? null,
            'stadium_seat_name' => $detail['stadium_seat_name'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $details);
        DB::table('xs2_category_mapping_details')->insert($rows);
    }

    private function autoMapThreshold(?float $override): float
    {
        return $override ?? (float) config('xs2.mapping.category_auto_map_threshold', 95);
    }
}
