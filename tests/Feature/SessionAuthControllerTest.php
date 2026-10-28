<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SessionAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('email 與密碼正確時可登入')]
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

    #[TestDox('登入回應的使用者只有 id、name、email')]
    public function test_login_returns_only_the_listed_user_fields()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);
        // 模擬 users 表之後多了一個欄位：從資料庫讀出的 User 都多帶一個 extra。
        User::retrieved(fn (User $retrieved) => $retrieved->setAttribute('extra', 'x'));

        $response = $this->postJson('/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertExactJsonStructure(['data' => ['id', 'name', 'email']])
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email);
    }

    #[TestDox('密碼錯誤時登入回 422')]
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

    #[TestDox('email 不存在時登入回 422')]
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

    #[TestDox('登入必須提供 email 與密碼')]
    public function test_login_requires_email_and_password()
    {
        $response = $this->postJson('/auth/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    #[TestDox('登出會清除 session')]
    public function test_logout_clears_session()
    {
        $user = User::factory()->create();
        $this->actingAsOwner($user);

        $response = $this->postJson('/auth/logout');

        $response->assertOk();
        $this->assertGuest();
    }

    #[TestDox('取得目前使用者未登入回 401')]
    public function test_api_user_endpoint_rejects_unauthenticated_request()
    {
        $response = $this->getJson('/api/user');

        $response->assertUnauthorized();
    }

    #[TestDox('取得目前使用者回傳已登入者')]
    public function test_api_user_endpoint_returns_authenticated_user()
    {
        $user = $this->actingAsOwner();

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonFragment(['id' => $user->id, 'email' => $user->email]);
    }
}
