<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['media_type', 'path', 'label'])]
class ListingMedia extends Model
{
    // Only uploaded_at exists on this table - no updated_at. Pointing
    // CREATED_AT at it (rather than switching timestamps off entirely) keeps
    // Eloquent writing it on the app's clock; see the Message model for why
    // the DB's useCurrent() default is the wrong source for it.
    const CREATED_AT = 'uploaded_at';

    const UPDATED_AT = null;

    /**
     * url is not stored. The database records where the file lives, and the
     * URL is whatever the disk currently in use says that path resolves to:
     * a local /storage/... path in development, a bucket URL in the cloud.
     * Serialised alongside the real columns so the API shape is unchanged.
     */
    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => $this->path
            ? Storage::disk(config('media.disk'))->url($this->path)
            : null);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
