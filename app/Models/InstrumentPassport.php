<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['serial_number', 'year_manufactured'])]
class InstrumentPassport extends Model
{
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PassportEntry::class, 'passport_id');
    }
}
