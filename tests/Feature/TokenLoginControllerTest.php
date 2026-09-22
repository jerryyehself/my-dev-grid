<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TokenLoginControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_and_returns_a_token_without_creating_a_session()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['id' => $user->id])
            ->assertJsonStructure(['data', 'token']);
        // Auth::once() 不建立 session——這支端點跟 SessionAuthController
        // 的差異就在這裡。不用 assertGuest()：那個斷言看的是這個測試
        // process 裡 Auth guard 目前記憶體中的解析結果，Auth::once() 在
        // 剛才那個 request 內部本來就會把它設成「已登入」（只是不落地），
        // 檢查它會誤判成失敗。真正要驗證的是「沒有留下可以延續的 session
        // 資料」，直接查 sessions 資料表（SESSION_DRIVER=database）才準。
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_wrong_password()
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_unknown_email()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_requires_email_and_password()
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_issued_token_authenticates_subsequent_requests()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);
        $token = $login->json('token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $response->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
    }

    public function test_logout_revokes_the_token()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);
        $token = $user->createToken('my-dev-grid-front')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $response->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // 這個測試在同一個 process 裡對同一支 token 打了兩次請求——Laravel
        // 測試環境不會在模擬請求之間重建整個 container，Auth guard 會把
        // 上一次（logout 那次）解析出來的 user 記在記憶體裡，不會為了這次
        // 請求重新查一次資料庫。不強制重置就會誤判成「還是登入的」，
        // 明明 personal_access_tokens 那筆已經真的刪掉了。
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_logout_requires_a_token()
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertUnauthorized();
    }
}
