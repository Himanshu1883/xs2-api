<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class AdminEventSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'sport' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'tournament_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'tournament' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ];
    }
}
