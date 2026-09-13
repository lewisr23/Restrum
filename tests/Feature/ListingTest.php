<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_browse_listings_without_logging_in(): void
    {
        Listing::factory()->count(3)->create();

        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'title', 'price', 'status', 'seller', 'media']], 'links', 'meta']);
    }

    /**
     * media is rendered with whenLoaded(), so a missing eager load makes the
     * key vanish from the payload rather than come back empty - the frontend
     * then silently shows a placeholder for a listing that has photos.
     */
    public function test_every_listing_endpoint_includes_the_media_key(): void
    {
        $seller = User::factory()->create();
        $listing = Listing::factory()->for($seller, 'seller')->create();

        $this->getJson('/api/listings')->assertOk()->assertJsonStructure(['data' => [['media']]]);
        $this->getJson("/api/listings/{$listing->id}")->assertOk()->assertJsonStructure(['data' => ['media']]);

        $created = $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Fender Telecaster',
            'description' => 'Lovely thing',
            'price' => 520,
            'location' => 'Newcastle',
            'category' => 'solid-body-electric-guitars',
        ]);
        $created->assertCreated()->assertJsonStructure(['data' => ['media']]);

        $this->actingAs($seller)
            ->putJson("/api/listings/{$listing->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['media']]);
    }

    public function test_a_signed_in_user_can_post_a_listing_which_starts_active(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Fender Telecaster',
            'description' => 'Ash body, maple neck.',
            'price' => 520,
            'location' => 'Newcastle',
            'category' => 'solid-body-electric-guitars',
            'condition' => 'EXCELLENT',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.condition', 'EXCELLENT')
            ->assertJsonPath('data.seller.username', $seller->username);
    }

    public function test_condition_defaults_to_good_when_not_given(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Boss DD-7',
            'description' => 'Delay pedal',
            'price' => 85,
            'location' => 'Leeds',
            'category' => 'delay-pedals',
        ])
            ->assertCreated()
            ->assertJsonPath('data.condition', 'GOOD');
    }

    /**
     * status is outside the fillable list precisely so request input can
     * never drive it - passing it must be ignored, not honoured.
     */
    public function test_a_seller_cannot_post_a_listing_that_is_already_sold(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Sneaky',
            'description' => 'Trying to set status directly',
            'price' => 100,
            'location' => 'Leeds',
            'category' => 'solid-body-electric-guitars',
            'status' => 'SOLD',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_posting_a_listing_validates_its_fields(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/listings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'description', 'price', 'location', 'category']);

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Banjo',
            'description' => 'Not a category that exists',
            'price' => 100,
            'location' => 'Leeds',
            'category' => 'not-a-real-category',
        ])->assertStatus(422)->assertJsonValidationErrors('category');

        // A real category, but a branch rather than a leaf. Listing on a
        // branch would put the item outside every filter underneath it.
        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Vague',
            'description' => 'Filed on a branch rather than a leaf',
            'price' => 100,
            'location' => 'Leeds',
            'category' => 'electric-guitars',
        ])->assertStatus(422)->assertJsonValidationErrors('category');

        $this->actingAs($seller)->postJson('/api/listings', [
            'title' => 'Cheap',
            'description' => 'Negative price',
            'price' => -5,
            'location' => 'Leeds',
            'category' => 'solid-body-electric-guitars',
        ])->assertStatus(422)->assertJsonValidationErrors('price');
    }

    public function test_only_the_owner_can_edit_a_listing(): void
    {
        $listing = Listing::factory()->create(['title' => 'Original']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->putJson("/api/listings/{$listing->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Original', $listing->fresh()->title);

        $this->actingAs($listing->seller)
            ->putJson("/api/listings/{$listing->id}", ['title' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated');
    }

    public function test_listings_can_be_filtered_by_search_category_and_price(): void
    {
        Listing::factory()->create(['title' => 'Roland Juno-106', 'category_id' => $this->categoryId('analogue-synthesisers'), 'price' => 1200]);
        Listing::factory()->create(['title' => 'Shure SM58', 'category_id' => $this->categoryId('dynamic-microphones'), 'price' => 75]);
        Listing::factory()->create(['title' => 'Pearl Export Kit', 'category_id' => $this->categoryId('rock-fusion-drum-kits'), 'price' => 430]);

        $this->getJson('/api/listings?search=juno')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Roland Juno-106');

        $this->getJson('/api/listings?category=dynamic-microphones')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Shure SM58');

        // A whole department, which is the thing the old five value enum
        // could not express: everything under Studio & Recording, however
        // deep it is filed.
        $this->getJson('/api/listings?category=studio-recording')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Shure SM58');

        $this->getJson('/api/listings?min_price=100&max_price=500')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Pearl Export Kit');

        // A category that does not exist is a wrong URL, not a bad filter.
        $this->getJson('/api/listings?category=not-a-real-category')->assertNotFound();
    }

    public function test_search_also_matches_the_description(): void
    {
        Listing::factory()->create(['title' => 'Mystery pedal', 'description' => 'A rare flanger circuit']);
        Listing::factory()->create(['title' => 'Other thing', 'description' => 'Nothing like it']);

        $this->getJson('/api/listings?search=flanger')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Mystery pedal');
    }

    // Buying a listing moved to CheckoutTest when it started involving money.
    // It is no longer a property of a listing that it can be sold, it is a
    // conversation with Stripe, and testing it here would have meant every
    // listing test carrying a payment gateway.

    public function test_saving_a_listing_toggles_and_shows_up_in_saved(): void
    {
        $listing = Listing::factory()->create();
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/save")
            ->assertOk()->assertJsonPath('saved', true);

        $this->actingAs($buyer)->getJson('/api/listings/saved')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $listing->id);

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/save")
            ->assertOk()->assertJsonPath('saved', false);

        $this->actingAs($buyer)->getJson('/api/listings/saved')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * The saved route is registered before /listings/{listing}; if that order
     * is ever lost, "saved" is swallowed as a listing id and this 404s.
     */
    public function test_the_saved_route_is_not_captured_as_a_listing_id(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/listings/saved')
            ->assertOk();
    }

    public function test_saved_by_viewer_reflects_who_is_asking(): void
    {
        $listing = Listing::factory()->create();
        $saver = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($saver)->postJson("/api/listings/{$listing->id}/save")->assertOk();

        $this->actingAs($saver)->getJson("/api/listings/{$listing->id}")
            ->assertJsonPath('data.saved_by_viewer', true);

        $this->actingAs($other)->getJson("/api/listings/{$listing->id}")
            ->assertJsonPath('data.saved_by_viewer', false);
    }

    public function test_a_seller_can_upload_an_image_to_their_listing(): void
    {
        Storage::fake('public');
        $listing = Listing::factory()->create();

        $this->actingAs($listing->seller)
            ->postJson("/api/listings/{$listing->id}/media", [
                'file' => UploadedFile::fake()->image('guitar.jpg'),
                'media_type' => 'IMAGE',
            ])
            ->assertCreated()
            ->assertJsonPath('media_type', 'IMAGE');

        $this->getJson("/api/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.media');
    }

    public function test_only_the_owner_can_upload_media_to_a_listing(): void
    {
        Storage::fake('public');
        $listing = Listing::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/listings/{$listing->id}/media", [
                'file' => UploadedFile::fake()->image('guitar.jpg'),
                'media_type' => 'IMAGE',
            ])
            ->assertForbidden();
    }

    public function test_a_missing_listing_returns_not_found(): void
    {
        $this->getJson('/api/listings/999')->assertNotFound();
    }
}
