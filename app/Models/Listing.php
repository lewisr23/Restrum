<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// status is deliberately NOT fillable - a listing starts ACTIVE and only
// moves to SOLD via the offer-accept flow, never by direct request input.
#[Fillable(['title', 'description', 'price', 'location', 'category', 'condition'])]
class Listing extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ListingMedia::class);
    }

    public function passport(): HasOne
    {
        return $this->hasOne(InstrumentPassport::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_listings')
            ->withTimestamps();
    }
}
