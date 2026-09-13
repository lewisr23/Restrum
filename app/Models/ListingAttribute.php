<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one of a category's filter questions, for one listing.
 *
 * A row per answer rather than a column per attribute, because there are
 * eighty odd attributes across the tree and any given listing uses six of
 * them. A wide table would be eighty columns that are null ninety percent of
 * the time, and every new filter would be a schema change.
 *
 * The cost is that filtering on two attributes means two joins, which is why
 * the facet index exists and why the counting query is written the way it is
 * in ListingFilter. At marketplace scale that trade is the right way round.
 */
#[Fillable(['name', 'value'])]
class ListingAttribute extends Model
{
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
