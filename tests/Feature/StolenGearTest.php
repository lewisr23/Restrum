<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Report;
use App\Models\StolenReport;
use App\Models\User;
use App\Notifications\StolenGearSpotted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * The stolen gear register.
 *
 * Two things are protected here and they pull in opposite directions. A
 * buyer must not be able to pay for a guitar whose serial matches one
 * reported stolen. And an honest seller whose serial happens to collide
 * with a report must not be publicly accused, or held up for ever.
 */
class StolenGearTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();
        Notification::fake();
    }

    private function reportStolen(User $owner, string $serial, array $extra = []): StolenReport
    {
        $this->actingAs($owner)
            ->postJson('/api/stolen', array_merge([
                'serial_number' => $serial,
                'brand' => 'Fender',
                'description' => 'Sunburst Stratocaster, taken from my van',
                'stolen_on' => '2026-09-01',
                'location' => 'Leeds',
                'police_reference' => '13/123456/26',
            ], $extra))
            ->assertCreated();

        return StolenReport::latest('id')->firstOrFail();
    }

    private function listWithSerial(User $seller, string $serial, string $brand = 'Fender'): Listing
    {
        $id = $this->actingAs($seller)
            ->postJson('/api/listings', [
                'title' => 'Fender Stratocaster',
                'description' => 'Plays well.',
                'price' => 500,
                'location' => 'Leeds',
                'category' => 'solid-body-electric-guitars',
                'brand' => $brand,
                'serial_number' => $serial,
            ])
            ->assertCreated()
            ->json('data.id');

        return Listing::findOrFail($id);
    }

    private function openStolenFlags(Listing $listing): int
    {
        return Report::whereNull('reporter_id')->where('listing_id', $listing->id)
            ->where('reason', 'STOLEN')->where('status', 'OPEN')->count();
    }

    public function test_listing_gear_that_was_reported_stolen_flags_it_and_holds_checkout(): void
    {
        $owner = User::factory()->create();
        $this->reportStolen($owner, 'MX21012345');

        $listing = $this->listWithSerial(User::factory()->payoutReady()->create(), 'MX21012345');

        $this->assertSame(1, $this->openStolenFlags($listing));
        Notification::assertSentTo($owner, StolenGearSpotted::class);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/listings/{$listing->id}/checkout")
            ->assertStatus(422)
            ->assertJsonPath('errors.listing.0', 'This listing is being checked by our team and cannot be bought right now. Try again later.');
    }

    /** The guitar is often listed before its owner has noticed it is gone. */
    public function test_reporting_a_theft_flags_a_listing_that_is_already_live(): void
    {
        $listing = $this->listWithSerial(User::factory()->payoutReady()->create(), 'MX21012345');
        $this->assertSame(0, $this->openStolenFlags($listing));

        $owner = User::factory()->create();
        $this->reportStolen($owner, 'MX21012345');

        $this->assertSame(1, $this->openStolenFlags($listing));
        Notification::assertSentTo($owner, StolenGearSpotted::class);
    }

    public function test_a_serial_matches_however_it_was_typed(): void
    {
        $this->reportStolen(User::factory()->create(), 'mx21-012345');

        $listing = $this->listWithSerial(User::factory()->payoutReady()->create(), ' MX21 012345 ');

        $this->assertSame(1, $this->openStolenFlags($listing));
    }

    /** A Fender and a Yamaha sharing a serial is a coincidence. */
    public function test_a_shared_serial_across_different_brands_is_not_a_match(): void
    {
        $this->reportStolen(User::factory()->create(), 'MX21012345', ['brand' => 'Yamaha']);

        $listing = $this->listWithSerial(User::factory()->payoutReady()->create(), 'MX21012345');

        $this->assertSame(0, $this->openStolenFlags($listing));
    }

    public function test_a_serial_too_short_to_identify_anything_is_never_matched(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/stolen', ['serial_number' => '1234', 'description' => 'Bass'])
            ->assertStatus(422);

        $this->getJson('/api/stolen/check?serial=1234')->assertStatus(422);
    }

    /**
     * Resolving the moderator report is what releases the listing. There is
     * no second switch to forget, and a dismissed match is not raised again
     * when the seller next edits their listing.
     */
    public function test_a_moderator_dismissing_the_match_releases_checkout(): void
    {
        $this->reportStolen(User::factory()->create(), 'MX21012345');
        $seller = User::factory()->payoutReady()->create();
        $listing = $this->listWithSerial($seller, 'MX21012345');

        Report::where('listing_id', $listing->id)->update(['status' => 'DISMISSED']);

        $this->actingAs($seller)->putJson("/api/listings/{$listing->id}", ['serial_number' => 'MX21012345'])->assertOk();
        $this->assertSame(0, $this->openStolenFlags($listing));

        $this->actingAs(User::factory()->create())
            ->postJson("/api/listings/{$listing->id}/checkout")
            ->assertSuccessful();
    }

    public function test_the_public_check_describes_the_item_but_not_who_reported_it(): void
    {
        $owner = User::factory()->create(['username' => 'victim123']);
        $this->reportStolen($owner, 'MX21012345');

        $body = $this->getJson('/api/stolen/check?serial=mx21 012345')
            ->assertOk()
            ->assertJsonPath('reported', true)
            ->assertJsonPath('reports.0.brand', 'Fender')
            ->assertJsonPath('reports.0.police_reported', true)
            ->getContent();

        $this->assertStringNotContainsString('13/123456/26', $body);
        $this->assertStringNotContainsString('victim123', $body);
        $this->assertStringNotContainsString($owner->email, $body);
    }

    public function test_recovered_gear_stops_matching_and_only_its_owner_can_say_so(): void
    {
        $owner = User::factory()->create();
        $report = $this->reportStolen($owner, 'MX21012345');

        $this->actingAs(User::factory()->create())
            ->postJson("/api/stolen/{$report->id}/recovered")
            ->assertNotFound();

        $this->actingAs($owner)->postJson("/api/stolen/{$report->id}/recovered")->assertOk();

        $this->getJson('/api/stolen/check?serial=MX21012345')->assertJsonPath('reported', false);

        $listing = $this->listWithSerial(User::factory()->payoutReady()->create(), 'MX21012345');
        $this->assertSame(0, $this->openStolenFlags($listing));
    }

    /**
     * The public page says "not on the register" only when that is true, and
     * says nothing at all about a match: an accusation on a public page
     * before a moderator has looked is not something to publish.
     */
    public function test_the_passport_says_clear_only_when_nothing_matches(): void
    {
        $clean = $this->listWithSerial(User::factory()->payoutReady()->create(), 'US12345678');

        $this->getJson("/api/listings/{$clean->id}/passport")
            ->assertOk()
            ->assertJsonPath('data.serial_number', 'US12345678')
            ->assertJsonPath('data.stolen_check', 'clear');

        $this->reportStolen(User::factory()->create(), 'MX21012345');
        $flagged = $this->listWithSerial(User::factory()->payoutReady()->create(), 'MX21012345');

        $body = $this->getJson("/api/listings/{$flagged->id}/passport")
            ->assertOk()
            ->assertJsonPath('data.stolen_check', null)
            ->getContent();

        $this->assertStringNotContainsStringIgnoringCase('stolen report', $body);
    }

    public function test_a_serial_can_be_cleared_from_a_listing(): void
    {
        $seller = User::factory()->payoutReady()->create();
        $listing = $this->listWithSerial($seller, 'US12345678');

        $this->actingAs($seller)->putJson("/api/listings/{$listing->id}", ['serial_number' => null])->assertOk();

        $this->assertNull($listing->passport()->first()->serial_number);
    }

    public function test_editing_a_listing_without_mentioning_the_serial_leaves_it_alone(): void
    {
        $seller = User::factory()->payoutReady()->create();
        $listing = $this->listWithSerial($seller, 'US12345678');

        $this->actingAs($seller)->putJson("/api/listings/{$listing->id}", ['price' => 450])->assertOk();

        $this->assertSame('US12345678', $listing->passport()->first()->serial_number);
    }

    public function test_reporting_needs_an_account(): void
    {
        $this->postJson('/api/stolen', ['serial_number' => 'MX21012345', 'description' => 'Strat'])
            ->assertStatus(401);
    }
}
