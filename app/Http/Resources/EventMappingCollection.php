<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class EventMappingCollection extends ResourceCollection
{
    public $collects = EventMappingResource::class;
}
