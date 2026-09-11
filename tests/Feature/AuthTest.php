<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_register_and_receives_a_working_token(): void
    {
        $response = $this->postJson('/api/register', [
            'username' => 'gearhead',
            'email' => 'gearhead@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'location' => 'Newcastle',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.username', 'gearhead')
            ->assertJsonStructure(['token', 'user' => ['id', 'username', 'email']]);

        $this->assertDatabaseHas('users', ['username' => 'gearhead']);

        // The token is only useful if it actually authenticates a later
        // request - asserting it exists in the response says nothing.
        $this->withToken($response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('username', 'gearhead');
    }

    public function test_the_password_is_stored_hashed_and_never_returned(): void
    {
        $response = $this->postJson('/api/register', [
            'username' => 'gearhead',
            'email' => 'gearhead@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('user.password');

        $stored = User::where('username', 'gearhead')->sole();
        $this->assertNotSame('password123', $stored->password);
        $this->assertTrue(password_verify('password123', $stored->password));
    }

    public function test_registration_rejects_a_duplicate_username_or_email(): void
    {
        User::factory()->create(['username' => 'taken', 'email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'username' => 'taken',
            'email' => 'fresh@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('username');

        $this->postJson('/api/register', [
            'username' => 'fresh',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        $this->postJson('/api/register', [
            'username' => 'gearhead',
            'email' => 'gearhead@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/register', [
            'username' => 'gearhead',
            'email' => 'gearhead@example.com',
            'password' => 'password123',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * The login field deliberately accepts either identifier, so both paths
     * need covering - a regression in the orWhere would only break one.
     */
    public function test_a_user_can_log_in_with_either_username_or_email(): void
    {
        User::factory()->create([
            'username' => 'gearhead',
            'email' => 'gearhead@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/login', ['login' => 'gearhead', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.username', 'gearhead');

        $this->postJson('/api/login', ['login' => 'gearhead@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.username', 'gearhead');
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        User::factory()->create(['username' => 'gearhead', 'password' => 'password123']);

        $this->postJson('/api/login', ['login' => 'gearhead', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_logging_out_invalidates_the_token_that_was_used(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $token = $this->postJson('/api/login', [
            'login' => $user->username, 'password' => 'password123',
        ])->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        // The guard memoises the resolved user for the lifetime of the test's
        // application instance, so without forgetting it the next call would
        // pass on the cached identity and this assertion would prove nothing.
        // A real second HTTP request re-resolves the (now deleted) token.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_protected_routes_reject_an_unauthenticated_request(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->postJson('/api/listings', [])->assertUnauthorized();
    }
}
