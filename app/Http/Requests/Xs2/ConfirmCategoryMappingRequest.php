<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmCategoryMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'stadium_seat_id' => ['sometimes', 'integer', 'min:1'],
            'stadium_detail_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
