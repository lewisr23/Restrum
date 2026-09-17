<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Taking a listing down.
 *
 * Sellers sell things elsewhere and change their minds, and until this
 * existed the only way to undo a listing was to email the operator. The
 * interesting half is what must NOT be deletable: orders take listing_id
 * with cascadeOnDelete, so removing a sold listing would silently take the
 * buyer's purchase history and the record behind a real card payment.
 */
class ListingWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['media.disk' => 'public']);

        $this->seller = User::factory()->create();
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create();
    }

    public function test_a_seller_can_take_their_own_listing_down(): void
    {
        $this->actingAs($this->seller)
            ->deleteJson("/api/listings/{$this->listing->id}")
            ->assertOk();

        $this->assertDatabaseMissing('listings', ['id' => $this->listing->id]);
    }

    public function test_somebody_else_cannot(): void
    {
        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/listings/{$this->listing->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('listings', ['id' => $this->listing->id]);
    }

    public function test_a_stranger_cannot(): void
    {
        $this->deleteJson("/api/listings/{$this->listing->id}")
            ->assertUnauthorized();
    }

    /**
     * The one that matters. Cascade delete means this would have taken the
     * order with it, and nothing would have said so.
     */
    public function test_a_listing_with_a_sale_against_it_cannot_be_deleted(): void
    {
        $order = Order::factory()
            ->forListing($this->listing, User::factory()->create())
            ->create(['status' => OrderStatus::PAID]);

        $this->actingAs($this->seller)
            ->deleteJson("/api/listings/{$this->listing->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('listings', ['id' => $this->listing->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    /**
     * A checkout somebody wandered away from is not a sale. Letting those
     * block deletion would mean one lapsed reservation froze a listing for
     * good.
     */
    public function test_a_cancelled_order_does_not_block_deletion(): void
    {
        Order::factory()
            ->forListing($this->listing, User::factory()->create())
            ->create(['status' => OrderStatus::CANCELLED]);

        $this->actingAs($this->seller)
            ->deleteJson("/api/listings/{$this->listing->id}")
            ->assertOk();

        $this->assertDatabaseMissing('listings', ['id' => $this->listing->id]);
    }

    /**
     * Files have to go too, or the upload cap is bounding a number that no
     * longer relates to anything on disk.
     */
    public function test_the_media_files_are_removed_from_disk(): void
    {
        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/media", [
                'file' => UploadedFile::fake()->image('guitar.jpg'),
                'media_type' => 'IMAGE',
            ])
            ->assertCreated();

        $path = $this->listing->media()->firstOrFail()->path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->seller)
            ->deleteJson("/api/listings/{$this->listing->id}")
            ->assertOk();

        Storage::disk('public')->assertMissing($path);
    }
}
