<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TriggerXs2SyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $sports = array_values(array_filter(config('services.xs2.sports', [])));

        return [
            'sport' => ['required', 'string', 'max:50', Rule::in($sports)],
            'full' => ['nullable', 'boolean'],
        ];
    }
}
