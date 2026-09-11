<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PassportEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_type' => $this->entry_type,
            'description' => $this->description,
            // Y-m-d, not the full ISO-8601 string the 'date' cast serializes
            // to by default. This is a calendar date ("serviced in March"),
            // never a moment in time, and the edit form binds it straight to
            // an <input type="date">, which only accepts YYYY-MM-DD and
            // silently renders blank for anything else.
            'event_date' => $this->event_date?->format('Y-m-d'),
            'created_at' => $this->created_at,
        ];
    }
}
