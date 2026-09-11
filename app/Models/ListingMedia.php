<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_type', 'url', 'label'])]
class ListingMedia extends Model
{
    // Only uploaded_at exists on this table - no updated_at. Pointing
    // CREATED_AT at it (rather than switching timestamps off entirely) keeps
    // Eloquent writing it on the app's clock; see the Message model for why
    // the DB's useCurrent() default is the wrong source for it.
    const CREATED_AT = 'uploaded_at';

    const UPDATED_AT = null;

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
