<?php

namespace App\Http\Requests\Xs2;

use App\Models\Xs2Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventMappingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:mapped,pending,created,ignored'],
            'mapping_method' => ['nullable', 'in:automatic,manual,created,exact'],
            'sport' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'minimum_score' => ['nullable', 'numeric', 'between:0,100'],
            'maximum_score' => ['nullable', 'numeric', 'between:0,100'],
            'has_local_event' => ['nullable', 'in:true,false,0,1'],
            'has_tickets' => ['nullable', 'in:true,false,0,1'],
            'has_ticket_flags' => ['nullable', 'in:true,false,0,1'],
            'ticket_flags' => ['nullable', 'array', 'max:'.count(Xs2Ticket::KNOWN_FLAGS)],
            'ticket_flags.*' => ['string', Rule::in(Xs2Ticket::KNOWN_FLAGS)],
            'has_guest_validation' => ['nullable', 'in:true,false,0,1'],
            'local_event_ids' => ['nullable', 'array', 'max:100'],
            'local_event_ids.*' => ['integer', 'min:1'],
            'venue_id' => ['nullable', 'string', 'max:100'],
            'tournament' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort' => ['nullable', 'in:id,match_score,created_at,updated_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
