<?php

namespace App\Http\Requests\SellerApi;

use Illuminate\Foundation\Http\FormRequest;

class SellerApiEventImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'min:8', 'max:64'],
            'payload' => ['nullable', 'array'],
            'environment' => ['nullable', 'in:sandbox,production'],
        ];
    }
}
