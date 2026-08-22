<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class Xs2CatalogBulkSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.external_event_id' => ['required', 'string', 'max:128'],
            'events.*.payload' => ['nullable', 'array'],
        ];
    }
}
