<?php

namespace Tests\Feature;

use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceInsightTest extends TestCase
{
    use RefreshDatabase;

    private function insightFor(Listing $listing): array
    {
        return $this->getJson("/api/listings/{$listing->id}")->assertOk()->json('price_insight');
    }

    /**
     * The insight sits alongside data, not inside it - the frontend merges
     * the two, so a change of nesting here breaks the fair-price badge.
     */
    public function test_the_insight_is_returned_beside_the_listing_payload(): void
    {
        $listing = Listing::factory()->create();

        $this->getJson("/api/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'price_insight' => ['category_average', 'category_sample_size', 'comparison', 'reference_label', 'reference_price'],
            ]);
    }

    /**
     * Running a category average per row on a paginated browse page would be
     * a query per listing, so the insight is deliberately show()-only.
     */
    public function test_the_browse_list_does_not_carry_price_insight(): void
    {
        Listing::factory()->create();

        $this->getJson('/api/listings')->assertOk()->assertJsonMissingPath('price_insight');
    }

    /**
     * Regression: labels used to be derived with ucwords() on the lowercase
     * match key, which mangled every model number - "sm58" came back as
     * "Sm58" and "ddj-400" as "Ddj-400".
     */
    public function test_reference_labels_keep_the_gear_names_real_capitalisation(): void
    {
        $cases = [
            'Shure SM58 Dynamic Microphone' => 'SM58',
            'Pioneer DDJ-400 Controller' => 'DDJ-400',
            'Rode NT1-A Condenser' => 'NT1-A',
            'Boss DD-7 Delay Pedal' => 'DD-7',
            'Fender Stratocaster Sunburst' => 'Stratocaster',
            'Gibson Les Paul Standard' => 'Les Paul',
        ];

        foreach ($cases as $title => $expectedLabel) {
            $listing = Listing::factory()->create(['title' => $title]);

            $this->assertSame(
                $expectedLabel,
                $this->insightFor($listing)['reference_label'],
                "Reference label for '{$title}' should read as the gear actually does",
            );
        }
    }

    public function test_the_reference_match_ignores_case_in_the_listing_title(): void
    {
        $listing = Listing::factory()->create(['title' => 'shure sm58 vocal mic']);

        $insight = $this->insightFor($listing);

        $this->assertSame('SM58', $insight['reference_label']);
        $this->assertEquals(90, $insight['reference_price']);
    }

    public function test_an_unrecognised_title_has_no_reference_price(): void
    {
        $listing = Listing::factory()->create(['title' => 'Homemade cigar box thing']);

        $insight = $this->insightFor($listing);

        $this->assertNull($insight['reference_label']);
        $this->assertNull($insight['reference_price']);
    }

    public function test_a_listing_with_no_peers_has_no_category_comparison(): void
    {
        $listing = Listing::factory()->create(['category' => 'SYNTHS', 'price' => 800]);

        $insight = $this->insightFor($listing);

        $this->assertNull($insight['category_average']);
        $this->assertNull($insight['comparison']);
        $this->assertSame(0, $insight['category_sample_size']);
    }

    public function test_a_price_close_to_the_category_average_reads_as_typical(): void
    {
        Listing::factory()->count(2)->create(['category' => 'GUITAR', 'price' => 500]);
        $listing = Listing::factory()->create(['category' => 'GUITAR', 'price' => 510]);

        $insight = $this->insightFor($listing);

        $this->assertSame('typical', $insight['comparison']);
        $this->assertEquals(500, $insight['category_average']);
        $this->assertSame(2, $insight['category_sample_size']);
    }

    public function test_a_price_well_under_the_category_average_reads_as_below(): void
    {
        Listing::factory()->count(2)->create(['category' => 'GUITAR', 'price' => 500]);
        $listing = Listing::factory()->create(['category' => 'GUITAR', 'price' => 300]);

        $this->assertSame('below', $this->insightFor($listing)['comparison']);
    }

    public function test_a_price_well_over_the_category_average_reads_as_above(): void
    {
        Listing::factory()->count(2)->create(['category' => 'GUITAR', 'price' => 500]);
        $listing = Listing::factory()->create(['category' => 'GUITAR', 'price' => 900]);

        $this->assertSame('above', $this->insightFor($listing)['comparison']);
    }

    /**
     * Averaging in sold stock would drag the comparison toward historic
     * prices rather than what the listing is competing against right now.
     */
    public function test_sold_listings_are_left_out_of_the_category_average(): void
    {
        Listing::factory()->create(['category' => 'GUITAR', 'price' => 500]);
        Listing::factory()->sold()->create(['category' => 'GUITAR', 'price' => 2000]);

        $listing = Listing::factory()->create(['category' => 'GUITAR', 'price' => 520]);

        $insight = $this->insightFor($listing);

        $this->assertEquals(500, $insight['category_average']);
        $this->assertSame(1, $insight['category_sample_size']);
    }

    public function test_other_categories_do_not_affect_the_average(): void
    {
        Listing::factory()->create(['category' => 'GUITAR', 'price' => 500]);
        Listing::factory()->create(['category' => 'DRUMS', 'price' => 5000]);

        $listing = Listing::factory()->create(['category' => 'GUITAR', 'price' => 520]);

        $this->assertEquals(500, $this->insightFor($listing)['category_average']);
        $this->assertSame(1, $this->insightFor($listing)['category_sample_size']);
    }
}
