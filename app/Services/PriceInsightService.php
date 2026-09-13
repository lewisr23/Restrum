<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Listing;

/**
 * Deliberately not scraping live marketplace data (Reverb/eBay/Facebook
 * Marketplace) for pricing: no clean API exists, it's a ToS risk, and it's
 * unreliable to demo against. This uses a category-average comparison
 * (computed from this database's own listings) plus a small curated
 * reference-price table instead - the trade-off is documented, not hidden.
 *
 * Deliberately called only from Listing show(), not index() - running a
 * category AVG() query per row on a paginated browse page would be a real
 * N-queries-per-page performance mistake, not just an odd choice.
 */
class PriceInsightService
{
    /**
     * Lowercase match needle => [display label, typical price].
     *
     * The label is stored rather than derived from the needle: gear names
     * carry their own casing ("SM58", "NT1-A", "DDJ-400") that no
     * capitalisation rule recovers - ucwords() on the needle produced
     * "Sm58" and "Ddj-400".
     */
    private const REFERENCE_PRICES = [
        'stratocaster' => ['Stratocaster', 450.0],
        'telecaster' => ['Telecaster', 500.0],
        'les paul' => ['Les Paul', 1200.0],
        'jazz bass' => ['Jazz Bass', 500.0],
        'precision bass' => ['Precision Bass', 480.0],
        'sm58' => ['SM58', 90.0],
        'sm57' => ['SM57', 90.0],
        'nt1-a' => ['NT1-A', 180.0],
        'dd-7' => ['DD-7', 90.0],
        'ddj-400' => ['DDJ-400', 170.0],
        'h4n' => ['H4n', 150.0],
    ];

    /** How far from the category average still counts as "typical" pricing. */
    private const TYPICAL_BAND = 0.15;

    public function forListing(Listing $listing): array
    {
        $listing->loadMissing('category');

        if ($listing->category === null) {
            return $this->withoutPeers($listing);
        }

        // Siblings, not just the exact leaf. The tree is deep enough now that
        // a leaf can hold three listings, and an average of three is not an
        // average of anything. The branch above it is still narrow enough to
        // be a fair comparison - other solid body electrics, not "other
        // guitars" - while being wide enough to have a sample in it.
        $branch = $listing->category->parent?->path ?? $listing->category->path;

        $peers = Listing::query()
            ->whereIn('category_id', Category::withinPath($branch)->select('id'))
            ->where('status', 'ACTIVE')
            ->where('id', '!=', $listing->id);

        // One query for both figures rather than a separate avg() and
        // count() - the sample size is what tells the frontend tooltip
        // whether "average" means anything yet.
        $categoryAverage = (clone $peers)->avg('price');
        $sampleSize = $peers->count();

        $comparison = null;
        if ($categoryAverage) {
            $ratio = $listing->price / $categoryAverage;
            $comparison = match (true) {
                $ratio < 1 - self::TYPICAL_BAND => 'below',
                $ratio > 1 + self::TYPICAL_BAND => 'above',
                default => 'typical',
            };
        }

        [$referenceLabel, $referencePrice] = $this->matchReference($listing->title);

        return [
            'category_average' => $categoryAverage ? round((float) $categoryAverage, 2) : null,
            'category_sample_size' => $sampleSize,
            'comparison' => $comparison,
            'reference_label' => $referenceLabel,
            'reference_price' => $referencePrice,
        ];
    }

    /**
     * A listing with no category still gets its reference price.
     *
     * Only reachable for a listing whose category was removed out from under
     * it, which the schema makes hard and does not make impossible. Returning
     * the half of the answer that still works beats a page that will not
     * render.
     *
     * @return array<string, mixed>
     */
    private function withoutPeers(Listing $listing): array
    {
        [$referenceLabel, $referencePrice] = $this->matchReference($listing->title);

        return [
            'category_average' => null,
            'category_sample_size' => 0,
            'comparison' => null,
            'reference_label' => $referenceLabel,
            'reference_price' => $referencePrice,
        ];
    }

    /** @return array{0: ?string, 1: ?float} */
    private function matchReference(string $title): array
    {
        $titleLower = strtolower($title);

        foreach (self::REFERENCE_PRICES as $needle => [$label, $price]) {
            if (str_contains($titleLower, $needle)) {
                return [$label, $price];
            }
        }

        return [null, null];
    }
}
