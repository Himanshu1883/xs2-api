<?php

namespace App\Http\Requests\Xs2;

use Illuminate\Foundation\Http\FormRequest;

class CreateEventFromXs2Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'min:1'],
            'tournament_id' => ['nullable', 'integer', 'min:1'],
            // These are legacy catalog IDs, never supplier IDs or names. Their
            // existence is checked schema-safely by LocalEventCreator because
            // legacy installations do not all expose the same master tables.
            'home_team_id' => ['nullable', 'integer', 'min:1'],
            'away_team_id' => ['nullable', 'integer', 'min:1'],
            'city_id' => ['nullable', 'integer', 'min:1'],
            'venue_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
