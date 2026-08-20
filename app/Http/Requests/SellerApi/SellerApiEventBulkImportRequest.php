<?php

namespace App\Http\Requests\SellerApi;

use Illuminate\Foundation\Http\FormRequest;

class SellerApiEventBulkImportRequest extends FormRequest
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
            'events.*.event_id' => ['required', 'string', 'min:8', 'max:64'],
            'events.*.payload' => ['nullable', 'array'],
            'environment' => ['nullable', 'in:sandbox,production'],
        ];
    }
}
