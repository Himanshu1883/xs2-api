<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VenueEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'min:1'],
            'tournament_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'performer' => ['nullable', 'string', 'max:255'],
        ];
    }
}
