<?php

namespace App\Http\Requests\SellerApi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SellerApiEventBulkSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Rule>> */
    public function rules(): array
    {
        $rules = [
            'tournament_id' => ['required', 'integer', 'min:1'],
        ];

        if ($this->isMethod('POST')) {
            $rules['environment'] = ['required', 'string', Rule::in(['sandbox', 'production'])];
        }

        return $rules;
    }
}
