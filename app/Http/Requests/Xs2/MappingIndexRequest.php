<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class MappingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'venue_id' => ['nullable', 'string', 'max:100'],
            'stadium_id' => ['nullable', 'integer', 'min:1'],
            'stadium_search' => ['nullable', 'string', 'max:255'],
            // League/tournament filter, used only by the stadium-mappings endpoint to
            // find venues that host events in a given XS2 tournament.
            'tournament' => ['nullable', 'string', 'max:255'],
            // Exact-match filter used only by the category-mappings endpoint when
            // drilling from a grouped category into its underlying per-event rows.
            'category_name' => ['nullable', 'string', 'max:255'],
            'group' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
