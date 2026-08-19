<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Xs2SyncState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_attempted_at' => 'datetime',
            'last_successful_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
