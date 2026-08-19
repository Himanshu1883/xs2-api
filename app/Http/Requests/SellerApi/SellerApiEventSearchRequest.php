<?php

namespace App\Http\Requests\SellerApi;

use Illuminate\Foundation\Http\FormRequest;

class SellerApiEventSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'environment' => ['nullable', 'in:sandbox,production'],
        ];
    }
}
