<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Getting back into an account, which was previously impossible.
 *
 * Register, login and logout were the only auth routes, so a forgotten
 * password cost someone their order history and their feedback permanently.
 * That also made a ban meaningless, because an account nobody can recover is
 * an account nobody minds losing.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_is_sent_to_a_real_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'seller@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'seller@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * The answer must not reveal whether the address is registered. On a
     * marketplace that would tell a stranger which addresses are worth
     * targeting.
     */
    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $this->assertSame(
            'If that email has an account, a reset link is on its way.',
            $known->json('message')
        );

        Notification::assertNothingSent();
    }

    public function test_a_valid_token_changes_the_password(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'seller@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'seller@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'seller@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_a_made_up_token_is_refused(): void
    {
        User::factory()->create(['email' => 'seller@example.com']);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'seller@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422);
    }

    /**
     * Resetting a password you believe was stolen has to evict whoever had
     * it, or the reset achieves nothing.
     */
    public function test_resetting_invalidates_existing_api_tokens(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'seller@example.com']);
        $user->createToken('session');

        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/forgot-password', ['email' => 'seller@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'seller@example.com',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_the_link_points_at_the_react_app_not_the_api(): void
    {
        Notification::fake();
        config()->set('app.frontend_url', 'https://restrum.uk');

        $user = User::factory()->create(['email' => 'seller@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'seller@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://restrum.uk/reset-password?token=');
        });
    }
}
