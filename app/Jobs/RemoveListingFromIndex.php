<?php

namespace App\Jobs;

use App\Search\ListingIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Takes one listing back out of the search index.
 *
 * Carries the id rather than the model, because by the time this runs the
 * row is gone and there is nothing left to refetch.
 */
class RemoveListingFromIndex implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $listingId) {}

    public function handle(ListingIndex $index): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        $index->remove($this->listingId);
    }
}
