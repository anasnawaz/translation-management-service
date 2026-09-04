<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'Password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'Password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type'],
            ]);

        $this->assertIsString($response->json('data.token'));
    }

    public function test_invalid_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'Password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unknown_email_is_rejected_without_exposing_sensitive_information(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        // The error message must not reveal whether the account exists.
        $message = $response->json('errors.email.0');
        $this->assertStringNotContainsStringIgnoringCase('does not exist', (string) $message);
        $this->assertStringNotContainsStringIgnoringCase('not found', (string) $message);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_authenticated_user_can_retrieve_their_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonMissingPath('data.password');
    }

    public function test_guest_cannot_access_protected_endpoints(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->getJson('/api/translations')->assertUnauthorized();
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout');

        $response->assertOk();
    }

    public function test_logged_out_token_can_no_longer_access_protected_endpoints(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')
            ->assertOk();

        // Sanctum's guard caches the resolved user for the lifetime of a
        // single test method's application instance. Forgetting the
        // resolved guards forces the next request to re-authenticate
        // against the (now deleted) token, exactly as a fresh HTTP
        // request against the real application would.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_authentication_rate_limiting_blocks_excessive_login_attempts(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'Password123',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->postJson('/api/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(422);
        }

        // The 6th attempt within the same window should be throttled.
        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
