<?php

namespace App\Http\Requests\SellerApi;

use Illuminate\Foundation\Http\FormRequest;

class SellerApiEventTournamentSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tournament_id' => ['required', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'environment' => ['nullable', 'in:sandbox,production'],
        ];
    }
}
