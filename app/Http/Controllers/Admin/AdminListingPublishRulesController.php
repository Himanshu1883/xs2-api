<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventMapping;
use App\Services\Xs2\ListingPublishRuleService;
use App\Services\Xs2\ListingPublishRuleSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminListingPublishRulesController extends Controller
{
    public function __construct(
        private readonly ListingPublishRuleSettingService $settings,
        private readonly ListingPublishRuleService $rules,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        return response()->json([
            'message' => 'Listing publish rules.',
            'data' => $this->rules->settingsPayload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'default_price_increment_type' => ['sometimes', 'string', 'in:percentage,fixed'],
            'default_price_increment_value' => ['sometimes', 'numeric', 'min:0'],
            'rules' => ['sometimes', 'array', 'min:1'],
            'rules.*.id' => ['required_with:rules', 'string', 'max:64'],
            'rules.*.label' => ['required_with:rules', 'string', 'max:255'],
            'rules.*.enabled' => ['sometimes', 'boolean'],
            'rules.*.priority' => ['sometimes', 'integer', 'min:0'],
            'rules.*.conditions' => ['required_with:rules', 'array', 'min:1'],
            'rules.*.conditions.*.field' => ['required', 'string', 'in:stock'],
            'rules.*.conditions.*.operator' => ['required', 'string', 'in:between,gte,lte,gt,lt,eq'],
            'rules.*.conditions.*.min' => ['nullable', 'integer', 'min:0'],
            'rules.*.conditions.*.max' => ['nullable', 'integer', 'min:0'],
            'rules.*.conditions.*.value' => ['nullable', 'integer', 'min:0'],
            'rules.*.action.mode' => ['required_with:rules', 'string', 'in:single,split'],
            'rules.*.action.listing_quantity' => ['nullable', 'integer', 'min:1'],
            'rules.*.action.listing_quantity_cap_to_stock' => ['sometimes', 'boolean'],
            'rules.*.action.split_size' => ['nullable', 'integer', 'min:1'],
            'rules.*.action.pairs_only' => ['sometimes', 'boolean'],
        ]);

        $current = $this->settings->get();
        $merged = array_replace_recursive($current, $validated);
        $saved = $this->settings->save($merged);

        return response()->json([
            'message' => 'Listing publish rules saved.',
            'data' => array_merge($saved, [
                'is_overridden' => true,
                'examples' => $this->rules->examplePreviews(),
            ]),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EventMapping::class);

        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        return response()->json([
            'message' => 'Listing publish rule preview.',
            'data' => $this->rules->previewForStock((int) $validated['stock']),
        ]);
    }
}
