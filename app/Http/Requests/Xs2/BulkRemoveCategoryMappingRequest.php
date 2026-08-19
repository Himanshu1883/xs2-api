<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class BulkRemoveCategoryMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'stadium_id' => ['required', 'integer', 'min:1'],
            'category_name' => ['required', 'string'],
            'external_venue_id' => ['nullable', 'string'],
        ];
    }
}
