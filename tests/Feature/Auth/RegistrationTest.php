<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ]);
    }

    public function test_registration_returns_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type'],
            ])
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // The plain text token issued should authenticate future requests.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_registration_normalizes_email_address(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => '  ADA@Example.COM  ',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => '  ADA@Example.COM  ',
        ]);
    }

    public function test_required_registration_fields_are_validated(): void
    {
        $response = $this->postJson('/api/register', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'not-an-email',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_confirmation_is_required(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_is_never_exposed_in_registration_response(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }
}
