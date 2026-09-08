<?php

namespace Tests\Feature;

use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SocialAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_rejects_unknown_provider()
    {
        $response = $this->get('/auth/facebook/redirect');

        $response->assertNotFound();
    }

    public function test_redirect_delegates_to_socialite_for_google()
    {
        Socialite::fake('google');

        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('google', $response->headers->get('Location'));
    }

    public function test_callback_rejects_unknown_provider()
    {
        $response = $this->get('/auth/facebook/callback');

        $response->assertNotFound();
    }

    public function test_callback_logs_in_when_oauth_identity_already_exists()
    {
        $user = User::factory()->create();
        $identity = OauthIdentity::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-123',
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => $identity->provider_user_id,
            'email' => $identity->provider_email,
            'name' => $user->name,
        ]));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_rejects_unknown_oauth_identity_without_auto_registration()
    {
        Log::spy();

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-unknown-999',
            'email' => 'stranger@example.com',
            'name' => 'Stranger',
        ]));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/?auth_error=not_authorized');
        $this->assertGuest();
        $this->assertDatabaseCount('oauth_identities', 0);
        $this->assertDatabaseCount('users', 0);

        // provider id/email 只能出現在 log，不能出現在 HTTP 回應裡。
        $this->assertStringNotContainsString('google-unknown-999', $response->getContent() ?: '');
        $this->assertStringNotContainsString('stranger@example.com', $response->getContent() ?: '');
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $context['provider_user_id'] === 'google-unknown-999'
                && $context['provider_email'] === 'stranger@example.com')
            ->once();
    }

    public function test_callback_rejects_unknown_identity_for_line_provider_too()
    {
        Socialite::fake('line', SocialiteUser::fake([
            'id' => 'line-unknown-999',
            'email' => null,
            'name' => 'Stranger',
        ]));

        $response = $this->get('/auth/line/callback');

        $response->assertRedirect('/?auth_error=not_authorized');
        $this->assertGuest();
    }
}
