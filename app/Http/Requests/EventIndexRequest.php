<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventIndexRequest extends FormRequest
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
            'sport' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'country' => ['nullable', 'string', 'size:3'],
            'city' => ['nullable', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'tournament' => ['nullable', 'string', 'max:255'],
            'tournament_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'in:starts_at,name,id'],
            'direction' => ['nullable', 'in:asc,desc'],
            'has_inventory' => ['nullable', 'in:true,false,0,1'],
        ];
    }
}
