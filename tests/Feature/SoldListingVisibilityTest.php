<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where sold gear should and should not appear.
 *
 * The two are opposites and both matter. In search a sold listing is a row
 * the buyer has to read and reject, so it is noise. On a seller's profile it
 * is the only evidence a stranger has that this person has sold anything
 * before, so it is the whole point of clicking through.
 */
class SoldListingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create();
    }

    private function listing(string $status, string $title): Listing
    {
        $listing = Listing::factory()->for($this->seller, 'seller')->create(['title' => $title]);
        $listing->status = $status;
        $listing->save();

        return $listing;
    }

    public function test_browsing_does_not_show_sold_gear(): void
    {
        $this->listing('ACTIVE', 'Available Telecaster');
        $this->listing('SOLD', 'Sold Telecaster');

        $response = $this->getJson('/api/listings')->assertOk();

        $titles = collect($response->json('data'))->pluck('title');

        $this->assertContains('Available Telecaster', $titles);
        $this->assertNotContains('Sold Telecaster', $titles);
    }

    /**
     * The capability is kept rather than removed, because the profile needs
     * it and because a stale bookmark asking for it should still work.
     */
    public function test_sold_gear_can_still_be_asked_for_explicitly(): void
    {
        $this->listing('ACTIVE', 'Available Telecaster');
        $this->listing('SOLD', 'Sold Telecaster');

        $titles = collect(
            $this->getJson('/api/listings?availability=all')->assertOk()->json('data')
        )->pluck('title');

        $this->assertContains('Sold Telecaster', $titles);
    }

    public function test_a_sellers_profile_shows_what_they_have_sold(): void
    {
        $this->listing('ACTIVE', 'Available Telecaster');
        $this->listing('SOLD', 'Sold Telecaster');

        $response = $this->getJson("/api/users/{$this->seller->id}")->assertOk();

        $this->assertSame(
            ['Available Telecaster'],
            collect($response->json('data.listings'))->pluck('title')->all()
        );
        $this->assertSame(
            ['Sold Telecaster'],
            collect($response->json('data.sold_listings'))->pluck('title')->all()
        );
        $this->assertSame(1, $response->json('data.sold_count'));
    }

    public function test_a_seller_with_no_sales_reports_none(): void
    {
        $this->listing('ACTIVE', 'Available Telecaster');

        $response = $this->getJson("/api/users/{$this->seller->id}")->assertOk();

        $this->assertSame([], $response->json('data.sold_listings'));
        $this->assertSame(0, $response->json('data.sold_count'));
    }
}
