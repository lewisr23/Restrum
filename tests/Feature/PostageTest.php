<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use App\Services\Payments\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * Postage, and where it lands in the money.
 *
 * The buyer pays one total that includes carriage, so the escrow covers it
 * and nobody has to settle up privately after the money moved. The platform
 * fee is charged on the item alone, because postage is a cost the seller is
 * recovering rather than income.
 */
class PostageTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    private User $buyer;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();

        $this->buyer = User::factory()->create();
        $this->seller = User::factory()->payoutReady()->create();
    }

    private function listing(array $overrides = []): Listing
    {
        $listing = Listing::factory()->for($this->seller, 'seller')->create(
            array_merge(['price' => 400.00], $overrides)
        );

        return $listing->fresh();
    }

    private function start(Listing $listing)
    {
        return app(CheckoutService::class)->start($this->buyer, $listing)->order;
    }

    public function test_postage_is_added_to_what_the_buyer_pays(): void
    {
        $order = $this->start($this->listing(['postage_price' => 15.00]));

        $this->assertSame('415.00', (string) $order->amount);
        $this->assertSame('15.00', (string) $order->postage);
        $this->assertSame(41500, $order->amountInPence());
    }

    /**
     * The fee is on the item, not the carriage. At 5% of £400 that is £20,
     * not £20.75.
     */
    public function test_the_platform_fee_ignores_postage(): void
    {
        $order = $this->start($this->listing(['postage_price' => 15.00]));

        $this->assertSame('20.00', (string) $order->platform_fee);
    }

    /** The seller keeps their postage money in full. */
    public function test_the_seller_is_paid_their_postage_on_top(): void
    {
        $order = $this->start($this->listing(['postage_price' => 15.00]));

        // 400 item - 20 fee + 15 postage
        $this->assertSame('395.00', $order->sellerProceeds());
        $this->assertSame(39500, $order->sellerProceedsInPence());
    }

    public function test_collection_only_charges_no_postage(): void
    {
        $order = $this->start($this->listing([
            'collection_only' => true,
            // Set, and deliberately ignored: collection means no carriage
            // whatever the field says.
            'postage_price' => 25.00,
        ]));

        $this->assertSame('400.00', (string) $order->amount);
        $this->assertSame('0.00', (string) $order->postage);
    }

    public function test_free_postage_is_a_postage_price_of_zero(): void
    {
        $order = $this->start($this->listing(['postage_price' => 0]));

        $this->assertSame('400.00', (string) $order->amount);
        $this->assertSame('0.00', (string) $order->postage);
        $this->assertSame('380.00', $order->sellerProceeds());
    }

    public function test_the_api_publishes_the_total_a_buyer_will_pay(): void
    {
        $listing = $this->listing(['postage_price' => 15.00]);

        $this->getJson("/api/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonPath('data.price', '400.00')
            ->assertJsonPath('data.postage_price', '15.00')
            ->assertJsonPath('data.total_price', '415.00')
            ->assertJsonPath('data.collection_only', false);
    }

    public function test_a_seller_can_set_postage_when_listing(): void
    {
        $category = Category::where('is_leaf', true)->firstOrFail();

        $this->actingAs($this->seller)
            ->postJson('/api/listings', [
                'title' => 'Amp with postage',
                'description' => 'Ships in its original box.',
                'price' => 250,
                'postage_price' => 12.50,
                'location' => 'Newcastle upon Tyne',
                'category' => $category->slug,
            ])
            ->assertCreated()
            ->assertJsonPath('data.postage_price', '12.50')
            ->assertJsonPath('data.total_price', '262.50');
    }

    public function test_an_absurd_postage_price_is_refused(): void
    {
        $category = Category::where('is_leaf', true)->firstOrFail();

        $this->actingAs($this->seller)
            ->postJson('/api/listings', [
                'title' => 'Fee dodge',
                'description' => 'Cheap item, enormous postage.',
                'price' => 1,
                'postage_price' => 9999,
                'location' => 'Newcastle upon Tyne',
                'category' => $category->slug,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('postage_price');
    }
}
