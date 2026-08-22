<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class SandboxGuestDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.first_name' => ['nullable', 'string', 'max:255'],
            'guests.*.last_name' => ['nullable', 'string', 'max:255'],
            'guests.*.passport_number' => ['nullable', 'string', 'max:64'],
            'guests.*.contact_email' => ['nullable', 'string', 'max:255'],
            'guests.*.email' => ['nullable', 'string', 'max:255'],
            'guests.*.contact_phone' => ['nullable', 'string', 'max:64'],
            'guests.*.phone' => ['nullable', 'string', 'max:64'],
            'guests.*.date_of_birth' => ['nullable', 'string', 'max:32'],
            'guests.*.dob' => ['nullable', 'string', 'max:32'],
            'guests.*.gender' => ['nullable', 'string', 'in:male,female,unknown', 'max:32'],
            'guests.*.country_of_residence' => ['nullable', 'string', 'max:3'],
            'guests.*.nationality' => ['nullable', 'string', 'max:64'],
            'guests.*.street_name' => ['nullable', 'string', 'max:255'],
            'guests.*.city' => ['nullable', 'string', 'max:255'],
            'guests.*.zip' => ['nullable', 'string', 'max:32'],
            'guests.*.guest_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
