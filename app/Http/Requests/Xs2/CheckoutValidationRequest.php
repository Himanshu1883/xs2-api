<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'seller_reference' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'expected_price' => ['required', 'integer', 'min:0'],
            'expected_currency' => ['nullable', 'string', 'size:3'],
            'guests' => ['nullable', 'array'],
            'guests.*.first_name' => ['nullable', 'string', 'max:255'],
            'guests.*.last_name' => ['nullable', 'string', 'max:255'],
            'guests.*.nationality' => ['nullable', 'string', 'max:64'],
            'guests.*.country_of_residence' => ['nullable', 'string', 'max:64'],
            'guests.*.province' => ['nullable', 'string', 'max:255'],
            'guests.*.state' => ['nullable', 'string', 'max:255'],
            'guests.*.date_of_birth' => ['nullable', 'string', 'max:32'],
            'guests.*.dob' => ['nullable', 'string', 'max:32'],
            'guests.*.passport_number' => ['nullable', 'string', 'max:64'],
            'guests.*.gender' => ['nullable', 'string', 'max:32'],
            'guests.*.email' => ['nullable', 'string', 'max:255'],
            'guests.*.phone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
