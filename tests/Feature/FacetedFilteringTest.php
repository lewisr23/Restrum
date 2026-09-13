<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browsing the category tree, and the filters that hang off it.
 *
 * The counts get as much attention here as the results, because a filter
 * panel is only trustworthy if the number beside an option is the number of
 * listings you get when you tick it. A panel that says "Fender (4)" and then
 * shows three is worse than a panel with no counts at all.
 */
class FacetedFilteringTest extends TestCase
{
    use RefreshDatabase;

    /** Creates a listing in a category with its filter answers filled in. */
    private function listing(string $categorySlug, array $attributes = [], array $overrides = []): Listing
    {
        $listing = Listing::factory()->inCategory($categorySlug)->create($overrides);

        if ($attributes !== []) {
            $listing->syncAttributes($attributes);
        }

        return $listing;
    }

    /** @return array<int, string> */
    private function titles($response): array
    {
        return array_map(static fn (array $row) => $row['title'], $response->json('data'));
    }

    /** The count beside one option of one facet, or null if it is not offered. */
    private function countFor($response, string $facet, string $value): ?int
    {
        foreach ($response->json("facets.{$facet}") ?? [] as $row) {
            if ($row['value'] === $value) {
                return $row['count'];
            }
        }

        return null;
    }

    public function test_the_catalog_endpoint_serves_the_whole_tree(): void
    {
        $response = $this->getJson('/api/catalog')->assertOk();

        $departments = $response->json('categories');

        // Fourteen departments, each with children, and the tree nested
        // rather than flat: the sell form walks it directly.
        $this->assertGreaterThan(10, count($departments));
        $this->assertNotEmpty($departments[0]['children']);
        $this->assertArrayHasKey('guitars', $response->json('brands'));
    }

    public function test_a_category_reports_the_filters_that_apply_to_it(): void
    {
        $response = $this->getJson('/api/catalog/categories/12-albums-lps')->assertOk();

        $names = array_column($response->json('attributes'), 'name');

        // Filters a record has.
        $this->assertContains('vinyl_size', $names);
        $this->assertContains('record_grading', $names);
        $this->assertContains('music_genre', $names);

        // Filters a record very much does not have.
        $this->assertNotContains('pickup_configuration', $names);
        $this->assertNotContains('amp_wattage', $names);
    }

    public function test_the_filters_on_a_guitar_are_not_the_filters_on_a_cable(): void
    {
        $guitar = array_column($this->getJson('/api/catalog/categories/solid-body-electric-guitars')->json('attributes'), 'name');
        $cable = array_column($this->getJson('/api/catalog/categories/jack-to-jack-instrument-cables')->json('attributes'), 'name');

        $this->assertContains('pickup_configuration', $guitar);
        $this->assertContains('guitar_strings_count', $guitar);

        $this->assertContains('cable_length', $cable);
        $this->assertContains('connector_a', $cable);
        $this->assertNotContains('cable_length', $guitar);
        $this->assertNotContains('pickup_configuration', $cable);
    }

    /**
     * The thing the old five value enum could not do at all.
     */
    public function test_browsing_a_department_includes_everything_beneath_it(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Stratocaster']);
        $this->listing('delay-pedals', overrides: ['title' => 'DD-7']);
        $this->listing('guitar-straps', overrides: ['title' => 'Leather strap']);
        $this->listing('12-albums-lps', overrides: ['title' => 'Kind of Blue']);

        $titles = $this->titles($this->getJson('/api/listings?category=guitars')->assertOk());

        $this->assertCount(3, $titles);
        $this->assertContains('Stratocaster', $titles);
        $this->assertContains('DD-7', $titles);
        $this->assertContains('Leather strap', $titles);
        $this->assertNotContains('Kind of Blue', $titles);
    }

    public function test_a_department_reports_how_many_listings_each_branch_holds(): void
    {
        $this->listing('solid-body-electric-guitars');
        $this->listing('semi-hollow-electric-guitars');
        $this->listing('delay-pedals');

        $response = $this->getJson('/api/listings?category=guitars')->assertOk();

        $counts = collect($response->json('facets.subcategories'))->pluck('count', 'slug');

        $this->assertSame(2, $counts['electric-guitars']);
        $this->assertSame(1, $counts['effects-pedals']);

        // A branch with nothing in it is still listed, at zero. A category
        // that disappears when empty is one nobody can be first to list in.
        $this->assertSame(0, $counts['guitar-parts']);
    }

    public function test_listings_can_be_filtered_by_brand(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Strat', 'brand' => 'Fender']);
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Les Paul', 'brand' => 'Gibson']);

        $titles = $this->titles(
            $this->getJson('/api/listings?category=guitars&brands[]=Fender')->assertOk()
        );

        $this->assertSame(['Strat'], $titles);
    }

    public function test_several_brands_widen_the_results_rather_than_narrowing_them(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Strat', 'brand' => 'Fender']);
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Les Paul', 'brand' => 'Gibson']);
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'RG', 'brand' => 'Ibanez']);

        $titles = $this->titles(
            $this->getJson('/api/listings?category=guitars&brands[]=Fender&brands[]=Gibson')->assertOk()
        );

        sort($titles);
        $this->assertSame(['Les Paul', 'Strat'], $titles);
    }

    public function test_listings_can_be_filtered_by_a_category_attribute(): void
    {
        $this->listing('12-albums-lps', ['vinyl_size' => '12 inch'], ['title' => 'Album']);
        $this->listing('7-singles', ['vinyl_size' => '7 inch'], ['title' => 'Single']);

        $titles = $this->titles(
            $this->getJson('/api/listings?category=vinyl-records&attributes[vinyl_size][]=7 inch')->assertOk()
        );

        $this->assertSame(['Single'], $titles);
    }

    /**
     * Within one filter the values are alternatives; between filters they
     * stack. Getting this backwards is the classic faceted search bug: every
     * second tick empties the page.
     */
    public function test_attributes_stack_but_values_within_one_are_alternatives(): void
    {
        $this->listing('12-albums-lps', ['vinyl_size' => '12 inch', 'vinyl_speed' => '33 1/3 RPM'], ['title' => 'LP']);
        $this->listing('12-albums-lps', ['vinyl_size' => '12 inch', 'vinyl_speed' => '45 RPM'], ['title' => 'Maxi']);
        $this->listing('7-singles', ['vinyl_size' => '7 inch', 'vinyl_speed' => '45 RPM'], ['title' => 'Single']);

        // Two values of one attribute: both come back.
        $both = $this->titles($this->getJson(
            '/api/listings?category=vinyl-records&attributes[vinyl_speed][]=33 1/3 RPM&attributes[vinyl_speed][]=45 RPM'
        )->assertOk());
        $this->assertCount(3, $both);

        // Two different attributes: only what satisfies both.
        $narrowed = $this->titles($this->getJson(
            '/api/listings?category=vinyl-records&attributes[vinyl_size][]=12 inch&attributes[vinyl_speed][]=45 RPM'
        )->assertOk());
        $this->assertSame(['Maxi'], $narrowed);
    }

    /**
     * A facet is counted with every filter applied except its own, so
     * choosing one brand does not collapse the brand list to that brand.
     * Without this the panel dead-ends after a single click.
     */
    public function test_a_facet_still_shows_its_other_options_once_one_is_picked(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['brand' => 'Fender', 'condition' => 'MINT']);
        $this->listing('solid-body-electric-guitars', overrides: ['brand' => 'Gibson', 'condition' => 'MINT']);
        $this->listing('solid-body-electric-guitars', overrides: ['brand' => 'Gibson', 'condition' => 'FAIR']);

        $response = $this->getJson('/api/listings?category=guitars&brands[]=Fender')->assertOk();

        // The brand panel still offers Gibson, and says how many you would
        // get by switching to it.
        $this->assertSame(1, $this->countFor($response, 'brands', 'Fender'));
        $this->assertSame(2, $this->countFor($response, 'brands', 'Gibson'));

        // The condition panel, which is not the facet being excluded, has
        // narrowed to the one Fender.
        $this->assertSame(1, $this->countFor($response, 'conditions', 'MINT'));
        $this->assertNull($this->countFor($response, 'conditions', 'FAIR'));
    }

    public function test_a_count_beside_an_option_is_what_ticking_it_returns(): void
    {
        $this->listing('12-albums-lps', ['record_grading' => 'Near Mint (NM)']);
        $this->listing('12-albums-lps', ['record_grading' => 'Near Mint (NM)']);
        $this->listing('12-albums-lps', ['record_grading' => 'Very Good (VG)']);

        $response = $this->getJson('/api/listings?category=12-albums-lps')->assertOk();

        $counts = collect($response->json('facets.attributes.record_grading'))->pluck('count', 'value');
        $this->assertSame(2, $counts['Near Mint (NM)']);

        $filtered = $this->getJson('/api/listings?category=12-albums-lps&attributes[record_grading][]=Near Mint (NM)')
            ->assertOk();

        $this->assertCount(2, $filtered->json('data'));
    }

    /**
     * A size filter sorted by popularity is a size filter nobody can scan.
     */
    public function test_attribute_options_come_back_in_their_own_order_not_by_popularity(): void
    {
        $this->listing('12-albums-lps', ['vinyl_size' => '12 inch']);
        $this->listing('12-albums-lps', ['vinyl_size' => '12 inch']);
        $this->listing('12-albums-lps', ['vinyl_size' => '12 inch']);
        $this->listing('7-singles', ['vinyl_size' => '7 inch']);
        $this->listing('10-records', ['vinyl_size' => '10 inch']);

        $response = $this->getJson('/api/listings?category=vinyl-records')->assertOk();

        $order = array_column($response->json('facets.attributes.vinyl_size'), 'value');

        $this->assertSame(['7 inch', '10 inch', '12 inch'], $order);
    }

    public function test_only_filters_belonging_to_the_category_are_offered(): void
    {
        $this->listing('12-albums-lps', ['vinyl_size' => '12 inch']);

        $facets = $this->getJson('/api/listings?category=12-albums-lps')->assertOk()->json('facets.attributes');

        $this->assertArrayHasKey('vinyl_size', $facets);
        $this->assertArrayNotHasKey('pickup_configuration', $facets);
    }

    public function test_price_bounds_describe_the_filtered_set(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['price' => 200]);
        $this->listing('solid-body-electric-guitars', overrides: ['price' => 1500]);
        $this->listing('12-albums-lps', overrides: ['price' => 5]);

        $bounds = $this->getJson('/api/listings?category=guitars')->assertOk()->json('facets.price');

        // assertEquals, not assertSame: json_encode drops the zero fraction
        // on a whole number, so 200.0 arrives as an int and the value is
        // right even though the type is not what the API sent.
        $this->assertEquals(200, $bounds['min']);
        $this->assertEquals(1500, $bounds['max']);
    }

    public function test_results_can_be_sorted_by_price(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Dear', 'price' => 900]);
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Cheap', 'price' => 100]);

        $this->assertSame(
            ['Cheap', 'Dear'],
            $this->titles($this->getJson('/api/listings?category=guitars&sort=price_asc')->assertOk()),
        );

        $this->assertSame(
            ['Dear', 'Cheap'],
            $this->titles($this->getJson('/api/listings?category=guitars&sort=price_desc')->assertOk()),
        );
    }

    public function test_sold_listings_can_be_hidden_and_otherwise_sink_to_the_bottom(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Gone', 'status' => 'SOLD']);
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Available']);

        // Shown by default, but last.
        $this->assertSame(
            ['Available', 'Gone'],
            $this->titles($this->getJson('/api/listings?category=guitars')->assertOk()),
        );

        $this->assertSame(
            ['Available'],
            $this->titles($this->getJson('/api/listings?category=guitars&availability=available')->assertOk()),
        );
    }

    public function test_a_listing_can_be_posted_with_its_attributes(): void
    {
        $seller = User::factory()->create();

        $response = $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Kind of Blue',
            'description' => 'Original Columbia pressing.',
            'price' => 120,
            'location' => 'Newcastle',
            'category' => '12-albums-lps',
            'brand' => 'Columbia',
            'attributes' => [
                'vinyl_size' => '12 inch',
                'vinyl_speed' => '33 1/3 RPM',
                'record_grading' => 'Very Good Plus (VG+)',
            ],
        ])->assertCreated();

        $this->assertSame('12 inch', $response->json('data.attributes.vinyl_size'));
        $this->assertSame('Columbia', $response->json('data.brand'));
        $this->assertSame('12" Albums & LPs', $response->json('data.category.name'));
    }

    public function test_an_attribute_that_does_not_belong_to_the_category_is_refused(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'A cable',
            'description' => 'With a record grading, somehow.',
            'price' => 15,
            'location' => 'Leeds',
            'category' => 'jack-to-jack-instrument-cables',
            'attributes' => ['record_grading' => 'Near Mint (NM)'],
        ])->assertStatus(422)->assertJsonValidationErrors('attributes');
    }

    public function test_a_value_outside_an_attributes_options_is_refused(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'A record',
            'description' => 'Of an unusual size.',
            'price' => 15,
            'location' => 'Leeds',
            'category' => '12-albums-lps',
            'attributes' => ['vinyl_size' => '14 inch'],
        ])->assertStatus(422)->assertJsonValidationErrors('attributes');
    }

    public function test_an_unknown_brand_is_refused(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'A guitar',
            'description' => 'By nobody in particular.',
            'price' => 150,
            'location' => 'Leeds',
            'category' => 'solid-body-electric-guitars',
            'brand' => 'Definitely Not A Real Brand',
        ])->assertStatus(422)->assertJsonValidationErrors('brand');
    }

    /**
     * A body shape left over from when this was a guitar is meaningless once
     * it is filed under cables, and worse than meaningless in a filter.
     */
    public function test_moving_a_listing_to_another_category_clears_its_old_attributes(): void
    {
        $listing = $this->listing('12-albums-lps', ['vinyl_size' => '12 inch', 'record_grading' => 'Mint (M)']);

        $this->actingAs($listing->seller)->putJson("/api/listings/{$listing->id}", [
            'category' => 'jack-to-jack-instrument-cables',
            'attributes' => ['cable_length' => '3m'],
        ])->assertOk();

        $attributes = $listing->fresh()->load('attributeValues')->attributeMap();

        $this->assertSame(['cable_length' => '3m'], $attributes);
    }

    public function test_filters_apply_on_top_of_a_text_search(): void
    {
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Fender Stratocaster', 'brand' => 'Fender']);
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Fender Telecaster', 'brand' => 'Fender']);
        $this->listing('solid-body-electric-guitars', overrides: ['title' => 'Gibson Les Paul', 'brand' => 'Gibson']);

        // Without a cluster this is the SQL LIKE fallback, which is the path
        // the site runs on by default, so it is the one worth covering here.
        $titles = $this->titles(
            $this->getJson('/api/listings?search=fender&brands[]=Fender&category=guitars')->assertOk()
        );

        sort($titles);
        $this->assertSame(['Fender Stratocaster', 'Fender Telecaster'], $titles);
    }
}
