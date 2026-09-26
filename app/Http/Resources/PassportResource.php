<?php

namespace App\Http\Resources;

use App\Services\Safety\SerialNumber;
use App\Services\Safety\StolenGearRegister;
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
            'stolen_check' => $this->stolenCheck(),
            'entries' => PassportEntryResource::collection($this->whenLoaded('entries')),
        ];
    }

    /**
     * 'clear' when the serial has been checked and nothing matches, and null
     * in every other case, including a match.
     *
     * A match is deliberately indistinguishable from "no serial" here. Saying
     * "possibly stolen" on a public page would accuse a seller before anyone
     * has looked, and the protection a buyer needs is already in place:
     * checkout is on hold while a moderator reviews it.
     */
    private function stolenCheck(): ?string
    {
        if (! SerialNumber::isMatchable($this->serial_normalized) || $this->listing === null) {
            return null;
        }

        $register = app(StolenGearRegister::class);

        if ($register->reportsFor($this->serial_number)->isNotEmpty() || $register->isHeld($this->listing)) {
            return null;
        }

        return 'clear';
    }
}
