<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class BulkConfirmCategoryMappingRequest extends FormRequest
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
            'stadium_seat_id' => ['required_without:stadium_detail_id', 'integer', 'min:1'],
            'stadium_detail_id' => ['required_without:stadium_seat_id', 'integer', 'min:1'],
        ];
    }
}
