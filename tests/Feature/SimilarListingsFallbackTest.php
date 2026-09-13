<?php

namespace Tests\Feature;

use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "You might also like", with no search cluster.
 *
 * This used to return nothing, deliberately: when a category was one of five
 * words, "other things in the same category" meant "other guitars", and a
 * recommendation that bad is worse than none.
 *
 * Against the category tree it means "other listings in this corner of the
 * shop", which is a real recommendation, so the fallback now does something.
 * Its own class rather than a case inside SimilarListingsTest because that
 * class skips itself without a cluster, and the whole point of this
 * behaviour is that it is what happens when there isn't one.
 */
class SimilarListingsFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['elasticsearch.enabled' => false]);
    }

    /** @return array<int, string> */
    private function similar(Listing $listing): array
    {
        return array_map(
            static fn (array $row) => $row['title'],
            $this->getJson("/api/listings/{$listing->id}/similar")->assertOk()->json('data'),
        );
    }

    public function test_it_suggests_other_listings_from_the_same_branch(): void
    {
        $strat = Listing::factory()->inCategory('solid-body-electric-guitars')
            ->create(['title' => 'Fender Stratocaster', 'price' => 500]);

        Listing::factory()->inCategory('solid-body-electric-guitars')
            ->create(['title' => 'Gibson Les Paul', 'price' => 520]);

        // A different leaf under the same branch still counts as nearby: a
        // buyer looking at solid bodies may well want a semi-hollow.
        Listing::factory()->inCategory('semi-hollow-electric-guitars')
            ->create(['title' => 'Epiphone Casino', 'price' => 540]);

        $suggestions = $this->similar($strat);

        $this->assertContains('Gibson Les Paul', $suggestions);
        $this->assertContains('Epiphone Casino', $suggestions);
        $this->assertNotContains('Fender Stratocaster', $suggestions);
    }

    public function test_it_does_not_wander_into_another_department(): void
    {
        $strat = Listing::factory()->inCategory('solid-body-electric-guitars')
            ->create(['title' => 'Fender Stratocaster']);

        Listing::factory()->inCategory('12-albums-lps')
            ->create(['title' => 'Kind of Blue LP']);

        $this->assertNotContains('Kind of Blue LP', $this->similar($strat));
    }

    /**
     * The same leaf first, then the rest of the branch. Someone looking at a
     * solid body wants another solid body before they want a hollow body.
     */
    public function test_the_same_leaf_is_suggested_before_the_wider_branch(): void
    {
        $strat = Listing::factory()->inCategory('solid-body-electric-guitars')
            ->create(['title' => 'Fender Stratocaster', 'price' => 500]);

        Listing::factory()->inCategory('semi-hollow-electric-guitars')
            ->create(['title' => 'Epiphone Casino', 'price' => 500]);

        Listing::factory()->inCategory('solid-body-electric-guitars')
            ->create(['title' => 'Gibson Les Paul', 'price' => 500]);

        $this->assertSame('Gibson Les Paul', $this->similar($strat)[0]);
    }

    public function test_it_does_not_suggest_sold_listings(): void
    {
        $strat = Listing::factory()->inCategory('solid-body-electric-guitars')
            ->create(['title' => 'Fender Stratocaster']);

        Listing::factory()->inCategory('solid-body-electric-guitars')->sold()
            ->create(['title' => 'Gibson Les Paul']);

        $this->assertNotContains('Gibson Les Paul', $this->similar($strat));
    }

    public function test_it_returns_an_empty_list_rather_than_failing_when_nothing_is_nearby(): void
    {
        $lonely = Listing::factory()->inCategory('didgeridoos')->create(['title' => 'Didgeridoo']);

        $this->assertSame([], $this->similar($lonely));
    }
}
