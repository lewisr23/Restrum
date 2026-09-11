<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Search\ListingIndex;
use Elastic\Elasticsearch\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Elasticsearch backed search.
 *
 * These tests need a real cluster: the whole point of the feature is the
 * analyzers and the query DSL, and a mocked client would assert that this
 * code builds the request it builds rather than that search works. When no
 * cluster is reachable they skip, so a clone with no Docker still gets a
 * green suite. CI runs them properly against a service container.
 */
class ListingSearchTest extends TestCase
{
    use RefreshDatabase;

    private ListingIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        // A separate index from the development one, so running the tests
        // never wipes the listings someone is looking at in the browser.
        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.index' => 'listings_test',
        ]);

        if (! $this->clusterIsUp()) {
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

    private function clusterIsUp(): bool
    {
        try {
            return $this->app->make(Client::class)->ping()->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Indexes listings and waits for them to be searchable. The observer
     * already indexes on save; this only forces the refresh, since a test
     * asserting on a result cannot wait a second for the next one.
     */
    private function indexed(array $attributes): Listing
    {
        $listing = Listing::factory()->create($attributes);
        $this->index->refresh();

        return $listing;
    }

    private function search(string $term): array
    {
        return $this->getJson('/api/listings?search='.urlencode($term))
            ->assertOk()
            ->json('data');
    }

    private function titles(array $data): array
    {
        return array_map(static fn ($row) => $row['title'], $data);
    }

    public function test_a_shortened_model_name_finds_the_full_one(): void
    {
        $this->indexed(['title' => 'Fender Stratocaster Sunburst']);
        $this->indexed(['title' => 'Pearl Export Drum Kit']);

        // The behaviour LIKE '%strat%' happens to get right, included so a
        // regression in the prefix analyzer is caught alongside the rest.
        $this->assertSame(['Fender Stratocaster Sunburst'], $this->titles($this->search('strat')));
    }

    public function test_a_misspelled_search_still_finds_the_listing(): void
    {
        $this->indexed(['title' => 'Fender Telecaster Butterscotch']);

        // LIKE cannot do this at all.
        $this->assertSame(['Fender Telecaster Butterscotch'], $this->titles($this->search('telecastor')));
    }

    public function test_a_model_number_matches_however_it_is_spaced(): void
    {
        $this->indexed(['title' => 'Shure SM58 Dynamic Microphone']);

        $this->assertCount(1, $this->search('sm58'));
        $this->assertCount(1, $this->search('sm 58'));
    }

    public function test_slang_finds_the_proper_name(): void
    {
        $this->indexed(['title' => 'Shure SM58 Dynamic Microphone']);

        $this->assertSame(['Shure SM58 Dynamic Microphone'], $this->titles($this->search('mic')));
    }

    public function test_searching_a_category_name_finds_that_category(): void
    {
        // Nothing in the title says "synth", so this can only match through
        // the analysed copy of the category.
        $this->indexed(['title' => 'Roland Juno 106', 'category' => 'SYNTHS']);
        $this->indexed(['title' => 'Pearl Export Drum Kit', 'category' => 'DRUMS']);

        $this->assertSame(['Roland Juno 106'], $this->titles($this->search('synth')));
    }

    public function test_a_title_match_outranks_a_description_match(): void
    {
        $this->indexed(['title' => 'Boss DD-7 Digital Delay', 'description' => 'A pedal.']);
        $this->indexed(['title' => 'Fender Amp', 'description' => 'Pairs well with a digital delay pedal.']);

        $this->assertSame('Boss DD-7 Digital Delay', $this->titles($this->search('digital delay'))[0]);
    }

    public function test_category_and_price_filters_apply_to_search_results(): void
    {
        $this->indexed(['title' => 'Fender Stratocaster', 'category' => 'GUITAR', 'price' => 400]);
        $this->indexed(['title' => 'Fender Bass Amp', 'category' => 'AUDIO_EQUIPMENT', 'price' => 900]);

        $this->assertSame(
            ['Fender Stratocaster'],
            $this->titles($this->getJson('/api/listings?search=fender&category=GUITAR')->assertOk()->json('data'))
        );

        $this->assertSame(
            ['Fender Stratocaster'],
            $this->titles($this->getJson('/api/listings?search=fender&max_price=500')->assertOk()->json('data'))
        );
    }

    public function test_sold_listings_rank_below_available_ones(): void
    {
        $this->indexed(['title' => 'Fender Stratocaster Sold', 'status' => 'SOLD']);
        $this->indexed(['title' => 'Fender Stratocaster Available', 'status' => 'ACTIVE']);

        $this->assertSame('Fender Stratocaster Available', $this->titles($this->search('stratocaster'))[0]);
    }

    public function test_the_response_shape_is_unchanged_by_the_search_path(): void
    {
        // The React frontend reads data, links and meta. A search that
        // returns the right listings in the wrong envelope is still broken.
        $this->indexed(['title' => 'Fender Stratocaster']);

        $this->getJson('/api/listings?search=fender')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'price', 'status', 'seller', 'media']],
                'links',
                'meta' => ['current_page', 'total', 'per_page'],
            ]);
    }

    public function test_saving_a_listing_updates_it_in_the_index(): void
    {
        $listing = $this->indexed(['title' => 'Korg Minilogue']);

        $this->assertCount(1, $this->search('minilogue'));

        // Deliberately not a near miss like "Monologue": fuzzy matching
        // tolerates two edits, so the old title would still match and the
        // test would be asserting the wrong thing.
        $listing->update(['title' => 'Yamaha Reface CS']);
        $this->index->refresh();

        $this->assertCount(0, $this->search('minilogue'));
        $this->assertCount(1, $this->search('reface'));
    }

    public function test_deleting_a_listing_removes_it_from_the_index(): void
    {
        $listing = $this->indexed(['title' => 'Korg Minilogue']);
        $this->assertCount(1, $this->search('minilogue'));

        $listing->delete();
        $this->index->refresh();

        $this->assertCount(0, $this->search('minilogue'));
    }

    public function test_results_are_paginated(): void
    {
        Listing::factory()->count(25)->create(['title' => 'Fender Stratocaster']);
        $this->index->refresh();

        $first = $this->getJson('/api/listings?search=stratocaster')->assertOk();
        $first->assertJsonCount(20, 'data');
        $this->assertSame(25, $first->json('meta.total'));

        $this->getJson('/api/listings?search=stratocaster&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }
}
