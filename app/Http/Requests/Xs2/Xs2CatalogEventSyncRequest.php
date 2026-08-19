<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class Xs2CatalogEventSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'external_event_id' => ['required_without:payload', 'string', 'max:128'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
