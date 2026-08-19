<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class VenueCollection extends ResourceCollection
{
    public $collects = VenueResource::class;
}
