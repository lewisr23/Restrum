<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Models\User;
use App\Services\Drafting\DraftUnavailable;
use App\Services\Drafting\ListingDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Photo to listing.
 *
 * Claude itself is not called: a scripted drafter stands in for it and
 * returns whatever submit_draft input the test wants. What is under test is
 * everything around the model, and above all that a draft can be wrong but
 * never invalid, because a sell form pre-filled with something the listing
 * endpoint then refuses is worse than an empty one.
 */
class ListingDraftTest extends TestCase
{
    use RefreshDatabase;

    private ?array $scripted = null;

    private bool $unreachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        $this->app->instance(ListingDrafter::class, new class($test) extends ListingDrafter
        {
            public array $sent = [];

            public function __construct(private readonly ListingDraftTest $test)
            {
                // Any non-null client: requestDraft is overridden, so it is
                // never used, and null would report the feature as off.
                parent::__construct(new Client(apiKey: 'test'));
            }

            protected function requestDraft(array $images): ?array
            {
                $this->sent = $images;

                return $this->test->scriptedAnswer();
            }
        });
    }

    public function scriptedAnswer(): ?array
    {
        if ($this->unreachable) {
            throw new DraftUnavailable('down');
        }

        return $this->scripted;
    }

    private function answer(array $overrides = []): array
    {
        return array_merge([
            'identified' => true,
            'title' => 'Fender Player Stratocaster, Polar White',
            'brand' => 'Fender',
            'category' => 'solid-body-electric-guitars',
            'attributes' => [
                ['name' => 'guitar_body_shape', 'value' => 'Stratocaster style'],
                ['name' => 'pickup_configuration', 'value' => 'SSS (three single coils)'],
            ],
            'condition' => 'EXCELLENT',
            'description' => 'A Player Stratocaster in Polar White.',
            'price_low' => 450,
            'price_high' => 550,
            'serial_number' => 'MX21012345',
            'notes' => 'Confirm it plays and add a photo of the back.',
        ], $overrides);
    }

    private function draftFrom(?array $photos = null)
    {
        return $this->actingAs(User::factory()->create())
            ->post('/api/listings/draft', [
                'photos' => $photos ?? [UploadedFile::fake()->image('strat.jpg', 800, 600)],
            ], ['Accept' => 'application/json']);
    }

    public function test_a_recognised_item_comes_back_as_a_filled_in_draft(): void
    {
        $this->scripted = $this->answer();

        $this->draftFrom()
            ->assertOk()
            ->assertJsonPath('data.identified', true)
            ->assertJsonPath('data.title', 'Fender Player Stratocaster, Polar White')
            ->assertJsonPath('data.brand', 'Fender')
            ->assertJsonPath('data.category', 'solid-body-electric-guitars')
            ->assertJsonPath('data.attributes.guitar_body_shape', 'Stratocaster style')
            ->assertJsonPath('data.condition', 'EXCELLENT')
            ->assertJsonPath('data.price_low', 450)
            ->assertJsonPath('data.serial_number', 'MX21012345');
    }

    public function test_the_photos_reach_the_model_as_base64_images(): void
    {
        $this->scripted = $this->answer();

        $this->draftFrom([
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('headstock.png'),
        ])->assertOk();

        $sent = $this->app->make(ListingDrafter::class)->sent;
        $this->assertCount(2, $sent);
        $this->assertSame('image/jpeg', $sent[0]['media_type']);
        $this->assertSame('image/png', $sent[1]['media_type']);
        $this->assertNotFalse(base64_decode($sent[0]['data'], true));
    }

    /**
     * The whole reason clean() exists. Each of these would fail the listing
     * endpoint's validation, so each must be dropped, not passed through.
     */
    public function test_anything_the_listing_endpoint_would_refuse_is_dropped(): void
    {
        $this->scripted = $this->answer([
            'brand' => 'Fendr',
            'attributes' => [
                ['name' => 'guitar_body_shape', 'value' => 'Banana shaped'],
                ['name' => 'record_grading', 'value' => 'Mint (M)'],
                ['name' => 'pickup_configuration', 'value' => 'HSS'],
            ],
            'condition' => 'LIKE NEW',
        ]);

        $this->draftFrom()
            ->assertOk()
            ->assertJsonPath('data.brand', null)
            ->assertJsonPath('data.condition', null)
            ->assertJsonPath('data.attributes', ['pickup_configuration' => 'HSS']);
    }

    public function test_a_brand_is_returned_as_the_brand_list_spells_it(): void
    {
        $this->scripted = $this->answer(['brand' => 'FENDER']);

        $this->draftFrom()->assertOk()->assertJsonPath('data.brand', 'Fender');
    }

    /**
     * The sell form's brand dropdown lists the category's department only,
     * so a real brand from elsewhere would be sent while the form showed
     * "Not stated".
     */
    public function test_a_brand_from_another_department_is_dropped(): void
    {
        $this->scripted = $this->answer(['brand' => 'Technics']);

        $this->draftFrom()->assertOk()->assertJsonPath('data.brand', null);
    }

    /**
     * A listing must be filed in a leaf, so a branch is as useless as an
     * invented slug. Its fields go too, since they were chosen for it.
     */
    public function test_a_category_that_is_invented_or_not_a_leaf_is_dropped_with_its_fields(): void
    {
        foreach (['electric-guitars', 'made-up-category'] as $slug) {
            $this->scripted = $this->answer(['category' => $slug]);

            $this->draftFrom()
                ->assertOk()
                ->assertJsonPath('data.category', null)
                ->assertJsonPath('data.attributes', []);
        }
    }

    public function test_a_back_to_front_price_range_is_put_the_right_way_round(): void
    {
        $this->scripted = $this->answer(['price_low' => 600, 'price_high' => 400]);

        $this->draftFrom()
            ->assertOk()
            ->assertJsonPath('data.price_low', 400)
            ->assertJsonPath('data.price_high', 600);
    }

    public function test_an_item_it_cannot_identify_says_so_rather_than_guessing(): void
    {
        $this->scripted = $this->answer(['identified' => false, 'notes' => 'This looks like a photo of a cat.']);

        $this->draftFrom()
            ->assertOk()
            ->assertJsonPath('data.identified', false)
            ->assertJsonPath('data.notes', 'This looks like a photo of a cat.')
            ->assertJsonMissingPath('data.title');
    }

    public function test_no_answer_at_all_is_reported_as_not_identified(): void
    {
        $this->scripted = null;

        $this->draftFrom()
            ->assertOk()
            ->assertJsonPath('data.identified', false);
    }

    public function test_an_unreachable_model_is_a_503_the_seller_can_read(): void
    {
        $this->unreachable = true;

        $this->draftFrom()->assertStatus(503)->assertJsonStructure(['message']);
    }

    public function test_only_images_are_accepted_and_at_most_three(): void
    {
        $this->scripted = $this->answer();

        $this->draftFrom([UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertStatus(422);

        $this->draftFrom(array_fill(0, 4, UploadedFile::fake()->image('x.jpg')))
            ->assertStatus(422);
    }

    public function test_signed_out_visitors_cannot_spend_the_api_budget(): void
    {
        $this->post('/api/listings/draft', [], ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    /**
     * The search Claude uses. A branch name has to lead to the leaves under
     * it, since a listing cannot be filed in a branch and "electric guitar"
     * is the phrase anyone would search first.
     */
    public function test_searching_a_branch_returns_its_leaves_with_their_fields(): void
    {
        $out = $this->app->make(ListingDrafter::class)->findCategories('electric guitar');

        $this->assertStringContainsString('solid-body-electric-guitars:', $out);
        $this->assertStringContainsString('guitar_body_shape (Body shape): Stratocaster style', $out);
        $this->assertStringNotContainsString("\nelectric-guitars:", "\n".$out);
    }
}
