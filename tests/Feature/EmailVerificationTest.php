<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Confirming that an address belongs to whoever typed it.
 *
 * Auth was register, login and logout, so nothing ever checked that the
 * address on an account was real. That made a suspension free to walk away
 * from and left no way to reach a seller when a sale went wrong.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** The link Stripe-style: build it the way the notification does. */
    protected function verificationUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => $hash ?? sha1($user->email),
            ],
        );
    }

    public function test_registering_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'username' => 'newseller',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertNull($user->email_verified_at, 'A new account must start unverified.');
        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }

    /**
     * Follows the link out of the rendered email rather than one this test
     * built itself.
     *
     * Every other case here constructs the URL the same way the notification
     * does, which would pass just as happily if the notification pointed at
     * the wrong route or hashed the wrong thing. This is the one that would
     * actually notice.
     */
    public function test_the_link_in_the_email_works(): void
    {
        $user = User::factory()->unverified()->create();

        $mail = (new VerifyEmailAddress)->toMail($user);

        $this->assertSame('Confirm your email address for Restrum', $mail->subject);

        $url = $mail->actionUrl;
        $this->assertStringContainsString('/api/email/verify/'.$user->id.'/'.sha1($user->email), $url);

        // Hit it as a browser would, with the query string the signature
        // lives in, but against this test server rather than APP_URL.
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertRedirect(config('app.frontend_url').'/email-verified?status=verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_signed_link_verifies_the_address(): void
    {
        Event::fake();

        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user))
            ->assertRedirect(config('app.frontend_url').'/email-verified?status=verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    /**
     * The hash is of the email, so a link sent to an old address stops
     * working once the address changes. Without this check, a signed link
     * would verify whatever address the account happened to hold later.
     */
    public function test_a_link_whose_hash_does_not_match_the_address_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user, sha1('someone-else@example.com')))
            ->assertRedirect(config('app.frontend_url').'/email-verified?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get("/api/email/verify/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->email)],
        );

        $this->travel(61)->minutes();

        $this->get($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verifying_twice_is_harmless(): void
    {
        $user = User::factory()->create();

        $this->get($this->verificationUrl($user))
            ->assertRedirect(config('app.frontend_url').'/email-verified?status=already');
    }

    public function test_a_member_can_ask_for_the_link_again(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/email/verification-notification')
            ->assertOk();

        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }

    public function test_resending_is_not_open_to_strangers(): void
    {
        $this->postJson('/api/email/verification-notification')->assertUnauthorized();
    }

    public function test_resending_to_a_verified_account_sends_nothing(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/email/verification-notification')
            ->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * The switch in config/features.php. Off, an unverified account behaves
     * exactly as it did before this feature existed - which is what makes it
     * safe to deploy the whole thing while MAIL_MAILER is still "log".
     */
    public function test_an_unverified_member_is_unblocked_while_the_switch_is_off(): void
    {
        config(['features.require_email_verification' => false]);

        $seller = User::factory()->unverified()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($seller)
            ->postJson("/api/listings/{$listing->id}/messages", ['content' => 'Still for sale?'])
            ->assertCreated();
    }

    public function test_an_unverified_member_cannot_message_while_the_switch_is_on(): void
    {
        config(['features.require_email_verification' => true]);

        $buyer = User::factory()->unverified()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($buyer)
            ->postJson("/api/listings/{$listing->id}/messages", ['content' => 'Still for sale?'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'email_unverified');
    }

    public function test_an_unverified_member_cannot_list_an_instrument_while_the_switch_is_on(): void
    {
        config(['features.require_email_verification' => true]);

        $this->actingAs(User::factory()->unverified()->create())
            ->postJson('/api/listings', [])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'email_unverified');
    }

    public function test_a_verified_member_is_unaffected_by_the_switch(): void
    {
        config(['features.require_email_verification' => true]);

        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($buyer)
            ->postJson("/api/listings/{$listing->id}/messages", ['content' => 'Still for sale?'])
            ->assertCreated();
    }
}
