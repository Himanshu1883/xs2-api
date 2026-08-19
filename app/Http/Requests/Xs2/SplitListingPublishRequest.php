<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SplitListingPublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'split_quantity' => ['required', 'integer', 'min:1'],
            'price_increment_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'price_increment_value' => ['required', 'numeric', 'min:0'],
            'base_price' => ['nullable', 'numeric', 'gt:0'],
            'sync' => ['sometimes', 'boolean'],
        ];
    }
}
