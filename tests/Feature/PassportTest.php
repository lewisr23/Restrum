<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A listing with no gear history is an ordinary state, not an error - the
     * detail page asks for the passport on every load.
     */
    public function test_a_listing_with_no_history_returns_null_rather_than_404(): void
    {
        $listing = Listing::factory()->create();

        $this->getJson("/api/listings/{$listing->id}/passport")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_gear_history_is_readable_without_logging_in(): void
    {
        $listing = Listing::factory()->create();
        $this->addEntry($listing, ['description' => 'New pickups fitted']);

        $this->getJson("/api/listings/{$listing->id}/passport")
            ->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.description', 'New pickups fitted');
    }

    public function test_the_first_entry_creates_the_passport_without_a_separate_step(): void
    {
        $listing = Listing::factory()->create();

        $this->assertNull($listing->passport);

        $this->addEntry($listing)->assertCreated();

        $this->assertNotNull($listing->fresh()->passport);
    }

    /**
     * Regression: event_date used to serialise as a full ISO-8601 datetime
     * via the 'date' cast. It is a calendar date, and the edit form binds it
     * to an <input type="date">, which renders blank for anything that is
     * not exactly YYYY-MM-DD.
     */
    public function test_an_event_date_is_returned_as_a_plain_calendar_date(): void
    {
        $listing = Listing::factory()->create();

        $this->addEntry($listing, ['event_date' => '2026-03-14'])
            ->assertCreated()
            ->assertJsonPath('data.event_date', '2026-03-14');

        $this->getJson("/api/listings/{$listing->id}/passport")
            ->assertOk()
            ->assertJsonPath('data.entries.0.event_date', '2026-03-14');
    }

    public function test_an_entry_without_a_date_is_allowed(): void
    {
        $listing = Listing::factory()->create();

        $this->addEntry($listing, ['event_date' => null])
            ->assertCreated()
            ->assertJsonPath('data.event_date', null);
    }

    public function test_only_the_owner_can_add_gear_history(): void
    {
        $listing = Listing::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/listings/{$listing->id}/passport/entries", [
                'entry_type' => 'SERVICE',
                'description' => 'Not mine to log',
            ])
            ->assertForbidden();
    }

    public function test_entry_type_must_be_one_of_the_known_kinds(): void
    {
        $listing = Listing::factory()->create();

        $this->actingAs($listing->seller)
            ->postJson("/api/listings/{$listing->id}/passport/entries", [
                'entry_type' => 'EXORCISM',
                'description' => 'Not a real entry type',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entry_type');
    }

    public function test_an_owner_can_correct_an_entry(): void
    {
        $listing = Listing::factory()->create();
        $entryId = $this->addEntry($listing, ['description' => 'Typo here'])->json('data.id');

        $this->actingAs($listing->seller)
            ->putJson("/api/listings/{$listing->id}/passport/entries/{$entryId}", [
                'description' => 'Corrected text',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Corrected text');
    }

    /**
     * Entries bind globally by id, so an entry belonging to a different
     * listing must not be reachable through this listing's URL.
     */
    public function test_an_entry_cannot_be_edited_through_another_listings_url(): void
    {
        $seller = User::factory()->create();
        $mine = Listing::factory()->for($seller, 'seller')->create();
        $other = Listing::factory()->for($seller, 'seller')->create();

        $otherEntryId = $this->addEntry($other, ['description' => 'Belongs elsewhere'])->json('data.id');

        $this->actingAs($seller)
            ->putJson("/api/listings/{$mine->id}/passport/entries/{$otherEntryId}", [
                'description' => 'Sneaky edit',
            ])
            ->assertNotFound();
    }

    /** Both endpoints must wrap in data, or the frontend unwraps one of them wrongly. */
    public function test_adding_and_editing_return_the_same_response_shape(): void
    {
        $listing = Listing::factory()->create();

        $added = $this->addEntry($listing)->assertCreated();
        $added->assertJsonStructure(['data' => ['id', 'entry_type', 'description', 'event_date', 'created_at']]);

        $this->actingAs($listing->seller)
            ->putJson("/api/listings/{$listing->id}/passport/entries/{$added->json('data.id')}", [
                'description' => 'Edited',
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'entry_type', 'description', 'event_date', 'created_at']]);
    }

    private function addEntry(Listing $listing, array $overrides = [])
    {
        return $this->actingAs($listing->seller)
            ->postJson("/api/listings/{$listing->id}/passport/entries", array_merge([
                'entry_type' => 'SERVICE',
                'description' => 'Serviced and set up',
            ], $overrides));
    }
}
