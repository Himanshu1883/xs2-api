<?php

namespace App\Http\Resources;

use App\Models\Xs2CategoryMappingDetail;
use App\Models\Xs2TicketMappingState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class Xs2CategoryMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->category;
        $context = $category?->context;
        $pending = Schema::hasTable('xs2_ticket_mapping_states')
            ? Xs2TicketMappingState::query()
                ->where('xs2_category_mapping_id', $this->id)
                ->where('mapping_status', 'pending_category_mapping')
                ->count()
            : 0;
        $rawCategoryName = $category?->category_name;
        [$categoryName, $categorySection] = $this->categoryNameParts($rawCategoryName);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'confidence_score' => $this->confidence_score === null ? null : (float) $this->confidence_score,
            'mapping_method' => $this->mapping_method,
            'matched_fields' => $this->matched_fields ?? [],
            'candidate_scores' => $this->candidate_scores ?? [],
            'manually_confirmed' => $this->manually_confirmed,
            'mapping_error' => $this->mapping_error,
            'mapped_at' => $this->mapped_at,
            'category' => [
                'external_category_id' => $category?->external_category_id,
                'name' => $categoryName,
                'section' => $categorySection,
                'raw_name' => $rawCategoryName,
                'type' => $context?->category_type,
                'external_venue_id' => $context?->external_venue_id,
            ],
            'stadium_id' => $this->stadium_id,
            'details_count' => $this->relationLoaded('details') ? $this->details->count() : $this->whenCounted('details'),
            'details' => $this->whenLoaded('details', fn () => $this->details->map(fn (Xs2CategoryMappingDetail $detail): array => [
                'id' => $detail->id,
                'stadium_detail_id' => $detail->stadium_detail_id,
                'stadium_seat_id' => $detail->stadium_seat_id,
                'block' => $detail->block,
                'section' => $detail->section,
                'name' => $detail->name,
                'stadium_seat_name' => $detail->stadium_seat_name,
            ])->values()),
            'pending_tickets' => $pending,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function categoryNameParts(?string $name): array
    {
        if ($name === null) {
            return [null, null];
        }

        [$category, $section] = array_pad(explode('_', $name, 2), 2, null);

        return [trim($category), filled($section) ? trim((string) $section) : null];
    }
}
