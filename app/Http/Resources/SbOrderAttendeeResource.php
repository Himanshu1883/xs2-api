<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SbOrderAttendee */
class SbOrderAttendeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'dob' => $this->dob,
            'nationality' => $this->nationality,
            'province' => $this->province,
            'email' => $this->email,
            'phone' => $this->phone,
            'passport' => $this->passport,
            'gender' => $this->gender,
        ];
    }
}
