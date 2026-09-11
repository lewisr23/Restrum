<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PassportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'serial_number' => $this->serial_number,
            'year_manufactured' => $this->year_manufactured,
            'entries' => PassportEntryResource::collection($this->whenLoaded('entries')),
        ];
    }
}
