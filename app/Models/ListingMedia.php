<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_type', 'url', 'label'])]
class ListingMedia extends Model
{
    // Only uploaded_at exists on this table (set via DB useCurrent()) - no
    // updated_at, so Eloquent's default dual-timestamp management is off.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
