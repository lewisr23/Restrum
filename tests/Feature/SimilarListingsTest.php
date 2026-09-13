<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Search\ListingIndex;
use Elastic\Elasticsearch\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "you might also like" rail, built on more_like_this over the same
 * index that backs search. Skips without a cluster, like ListingSearchTest.
 */
class SimilarListingsTest extends TestCase
{
    use RefreshDatabase;

    private ListingIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.index' => 'listings_test',
        ]);

        try {
            $up = $this->app->make(Client::class)->ping()->asBool();
        } catch (\Throwable) {
            $up = false;
        }

        if (! $up) {
            $this->markTestSkipped('No Elasticsearch cluster at '.config('elasticsearch.host'));
        }

        $this->index = $this->app->make(ListingIndex::class);
        $this->index->create(dropExisting: true);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->index->delete();
        }

        parent::tearDown();
    }

    private function similar(Listing $listing): array
    {
        $this->index->refresh();

        return array_map(
            static fn ($row) => $row['title'],
            $this->getJson("/api/listings/{$listing->id}/similar")->assertOk()->json('data'),
        );
    }

    public function test_it_suggests_listings_that_share_distinctive_words(): void
    {
        $strat = Listing::factory()->create([
            'title' => 'Fender Stratocaster Sunburst',
            'description' => 'Classic single coil strat tone, maple neck.',
            'category_id' => $this->categoryId('solid-body-electric-guitars'),
        ]);
        Listing::factory()->create([
            'title' => 'Fender Stratocaster Olympic White',
            'description' => 'Another single coil strat, rosewood neck.',
            'category_id' => $this->categoryId('solid-body-electric-guitars'),
        ]);
        Listing::factory()->create([
            'title' => 'Yamaha FG800 Acoustic',
            'description' => 'Solid top dreadnought acoustic.',
            'category_id' => $this->categoryId('solid-body-electric-guitars'),
        ]);

        $suggestions = $this->similar($strat);

        $this->assertNotEmpty($suggestions);
        $this->assertSame('Fender Stratocaster Olympic White', $suggestions[0]);
    }

    public function test_it_never_suggests_the_listing_being_viewed(): void
    {
        $listing = Listing::factory()->create([
            'title' => 'Fender Stratocaster Sunburst',
            'category_id' => $this->categoryId('solid-body-electric-guitars'),
        ]);
        Listing::factory()->create([
            'title' => 'Fender Stratocaster Olympic White',
            'category_id' => $this->categoryId('solid-body-electric-guitars'),
        ]);

        $this->assertNotContains('Fender Stratocaster Sunburst', $this->similar($listing));
    }

    public function test_it_stays_within_the_category(): void
    {
        $pedal = Listing::factory()->create([
            'title' => 'Boss DD-7 Digital Delay',
            'description' => 'Delay pedal in excellent condition.',
            'category_id' => $this->categoryId('usb-audio-interfaces'),
        ]);
        // Shares wording, but suggesting a drum kit under a delay pedal
        // would be worse than suggesting nothing.
        Listing::factory()->create([
            'title' => 'Pearl Export Kit',
            'description' => 'Delay pedal not included, excellent condition.',
            'category_id' => $this->categoryId('rock-fusion-drum-kits'),
        ]);

        $this->assertNotContains('Pearl Export Kit', $this->similar($pedal));
    }

    public function test_it_does_not_suggest_sold_listings(): void
    {
        $strat = Listing::factory()->create([
            'title' => 'Fender Stratocaster Sunburst',
            'category_id' => $this->categoryId('solid-body-electric-guitars'),
        ]);
        Listing::factory()->create([
            'title' => 'Fender Stratocaster Olympic White',
            'category_id' => $this->categoryId('solid-body-electric-guitars'),
            'status' => 'SOLD',
        ]);

        $this->assertNotContains('Fender Stratocaster Olympic White', $this->similar($strat));
    }

    public function test_it_returns_an_empty_list_rather_than_failing_when_nothing_is_similar(): void
    {
        $lonely = Listing::factory()->create([
            'title' => 'Theremin',
            'category_id' => $this->categoryId('analogue-synthesisers'),
        ]);

        $this->assertSame([], $this->similar($lonely));
    }

    // What happens with the cluster switched off moved to
    // SimilarListingsFallbackTest. It used to belong here, when the answer
    // was "nothing happens"; now that there is a real SQL fallback it
    // deserves to run on every test run rather than only on the ones where a
    // cluster happens to be up.
}
