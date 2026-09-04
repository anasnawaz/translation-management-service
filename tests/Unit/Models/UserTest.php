<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_hashed_when_set(): void
    {
        $user = User::factory()->create([
            'password' => 'PlainTextPassword123',
        ]);

        $this->assertNotSame('PlainTextPassword123', $user->password);
        $this->assertTrue(Hash::check('PlainTextPassword123', $user->password));
    }

    public function test_password_and_remember_token_are_hidden_from_array_and_json(): void
    {
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
        $this->assertStringNotContainsString('password', $user->toJson());
    }
}
