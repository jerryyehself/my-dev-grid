<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_with_correct_email_and_password()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_wrong_password()
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_login_rejects_unknown_email()
    {
        $response = $this->postJson('/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_login_requires_email_and_password()
    {
        $response = $this->postJson('/auth/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_logout_clears_session()
    {
        $user = User::factory()->create();
        $this->actingAsOwner($user);

        $response = $this->postJson('/auth/logout');

        $response->assertOk();
        $this->assertGuest();
    }

    public function test_api_user_endpoint_rejects_unauthenticated_request()
    {
        $response = $this->getJson('/api/user');

        $response->assertUnauthorized();
    }

    public function test_api_user_endpoint_returns_authenticated_user()
    {
        $user = $this->actingAsOwner();

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonFragment(['id' => $user->id, 'email' => $user->email]);
    }
}
