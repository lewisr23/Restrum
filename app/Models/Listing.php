<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// status is deliberately NOT fillable - a listing starts ACTIVE and only
// moves to SOLD when money has actually been taken for it (EscrowService, on
// the payment webhook) or a moderator removes it, never by direct request
// input. Accepting a price offer used to sell a listing too; it now agrees a
// price and leaves the sale to checkout like any other purchase.
#[Fillable(['title', 'description', 'price', 'postage_price', 'collection_only', 'location', 'category_id', 'brand', 'condition'])]
class Listing extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'postage_price' => 'decimal:2',
            'collection_only' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The answers to this category's filter questions.
     *
     * Named attributeValues rather than attributes because Eloquent already
     * owns $model->attributes, and shadowing it would break the model in ways
     * that surface a long way from here.
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ListingAttribute::class);
    }

    /**
     * The attributes as a name to value map, for rendering and for the API.
     *
     * Reads the loaded relation rather than querying, so a page that eager
     * loaded them does not go back to the database once per listing.
     *
     * @return array<string, string>
     */
    public function attributeMap(): array
    {
        return $this->attributeValues
            ->pluck('value', 'name')
            ->all();
    }

    /**
     * Replaces this listing's attributes with the given set.
     *
     * Whole-set replacement rather than merging: the form submits every
     * attribute the category has, so an attribute missing from the payload
     * means the seller cleared it, and merging would make it impossible to
     * unset anything.
     *
     * @param  array<string, string>  $values
     */
    public function syncAttributes(array $values): void
    {
        $this->attributeValues()->delete();

        if ($values === []) {
            $this->setRelation('attributeValues', collect());

            return;
        }

        $this->attributeValues()->createMany(
            collect($values)
                ->map(fn (string $value, string $name) => ['name' => $name, 'value' => $value])
                ->values()
                ->all()
        );

        $this->load('attributeValues');
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

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_listings')
            ->withTimestamps();
    }
}
