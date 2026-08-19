<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VenueIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'tournament_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'performer' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:name,id'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
