<?php

namespace App\Models;

use App\Services\Safety\SerialNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['serial_number', 'year_manufactured'])]
class InstrumentPassport extends Model
{
    protected static function booted(): void
    {
        // The twin the stolen register matches on, kept in step on every
        // save so no path that sets a serial can forget it.
        static::saving(function (InstrumentPassport $passport) {
            $passport->serial_normalized = SerialNumber::normalize($passport->serial_number);
        });
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PassportEntry::class, 'passport_id');
    }
}
