<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    /** Endorsing requires a prior conversation, so stage a real one. */
    private function messageEachOther(User $buyer, User $seller): void
    {
        $listing = Listing::factory()->for($seller, 'seller')->create();

        $this->actingAs($buyer)
            ->postJson("/api/listings/{$listing->id}/messages", ['content' => 'Hello'])
            ->assertCreated();
    }

    public function test_a_profile_is_public_and_shows_the_sellers_active_listings(): void
    {
        $seller = User::factory()->create(['location' => 'Newcastle']);
        Listing::factory()->for($seller, 'seller')->create(['title' => 'On sale']);
        Listing::factory()->for($seller, 'seller')->sold()->create(['title' => 'Already gone']);

        $this->getJson("/api/users/{$seller->id}")
            ->assertOk()
            ->assertJsonPath('data.username', $seller->username)
            ->assertJsonPath('data.location', 'Newcastle')
            ->assertJsonCount(1, 'data.listings')
            ->assertJsonPath('data.listings.0.title', 'On sale')
            ->assertJsonStructure(['data' => ['member_since', 'endorsement_count', 'follower_count']]);
    }

    public function test_viewer_context_is_absent_for_anonymous_visitors(): void
    {
        $seller = User::factory()->create();

        $this->getJson("/api/users/{$seller->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.viewer_context');
    }

    public function test_viewer_context_is_absent_on_your_own_profile(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->getJson("/api/users/{$seller->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.viewer_context');
    }

    public function test_viewer_context_is_present_for_a_signed_in_visitor(): void
    {
        $seller = User::factory()->create();

        $this->actingAs(User::factory()->create())->getJson("/api/users/{$seller->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['viewer_context' => ['am_i_following', 'have_i_endorsed', 'can_endorse']]]);
    }

    public function test_you_can_only_endorse_someone_you_have_actually_messaged(): void
    {
        $seller = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/users/{$seller->id}")
            ->assertJsonPath('data.viewer_context.can_endorse', false);

        $this->actingAs($stranger)->postJson("/api/users/{$seller->id}/endorse")
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        $this->messageEachOther($stranger, $seller);

        $this->actingAs($stranger)->getJson("/api/users/{$seller->id}")
            ->assertJsonPath('data.viewer_context.can_endorse', true);

        $this->actingAs($stranger)->postJson("/api/users/{$seller->id}/endorse")
            ->assertOk()
            ->assertJsonPath('data.endorsement_count', 1);
    }

    public function test_you_cannot_endorse_yourself(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/users/{$user->id}/endorse")
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');
    }

    public function test_the_same_person_cannot_endorse_twice(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $this->messageEachOther($buyer, $seller);

        $this->actingAs($buyer)->postJson("/api/users/{$seller->id}/endorse")->assertOk();

        $this->actingAs($buyer)->postJson("/api/users/{$seller->id}/endorse")
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        $this->assertSame(1, $seller->endorsementsReceived()->count());
    }

    /**
     * The badge is earned, not set: it must stay off at one endorsement and
     * flip on at the second.
     */
    public function test_a_seller_becomes_community_verified_at_the_second_endorsement(): void
    {
        $seller = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->messageEachOther($first, $seller);
        $this->messageEachOther($second, $seller);

        $this->actingAs($first)->postJson("/api/users/{$seller->id}/endorse")
            ->assertOk()
            ->assertJsonPath('data.community_verified', false);

        $this->actingAs($second)->postJson("/api/users/{$seller->id}/endorse")
            ->assertOk()
            ->assertJsonPath('data.community_verified', true)
            ->assertJsonPath('data.endorsement_count', 2);

        $this->assertTrue($seller->fresh()->community_verified);
    }

    public function test_following_toggles_and_is_reflected_in_the_follower_count(): void
    {
        $seller = User::factory()->create();
        $follower = User::factory()->create();

        $this->actingAs($follower)->postJson("/api/users/{$seller->id}/follow")
            ->assertOk()->assertJsonPath('following', true);

        $this->actingAs($follower)->getJson("/api/users/{$seller->id}")
            ->assertJsonPath('data.follower_count', 1)
            ->assertJsonPath('data.viewer_context.am_i_following', true);

        $this->actingAs($follower)->postJson("/api/users/{$seller->id}/follow")
            ->assertOk()->assertJsonPath('following', false);

        $this->actingAs($follower)->getJson("/api/users/{$seller->id}")
            ->assertJsonPath('data.follower_count', 0)
            ->assertJsonPath('data.viewer_context.am_i_following', false);
    }

    public function test_following_does_not_require_a_prior_conversation(): void
    {
        $seller = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/users/{$seller->id}/follow")
            ->assertOk();
    }

    public function test_a_profile_never_exposes_the_email_or_password(): void
    {
        $seller = User::factory()->create();

        $response = $this->getJson("/api/users/{$seller->id}")->assertOk();

        $response->assertJsonMissingPath('data.email');
        $response->assertJsonMissingPath('data.password');
    }

    public function test_a_missing_profile_returns_not_found(): void
    {
        $this->getJson('/api/users/999')->assertNotFound();
    }
}
