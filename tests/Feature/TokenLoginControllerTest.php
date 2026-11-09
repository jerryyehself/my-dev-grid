<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TokenLoginControllerTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('登入成功回傳 token，不建立 session')]
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

    #[TestDox('登入回應的使用者只有 id、name、email')]
    public function test_login_returns_only_the_listed_user_fields()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);
        // 模擬 users 表之後多了一個欄位：從資料庫讀出的 User 都多帶一個 extra。
        User::retrieved(fn (User $retrieved) => $retrieved->setAttribute('extra', 'x'));

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertExactJsonStructure(['data' => ['id', 'name', 'email'], 'token', 'expires_in'])
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

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[TestDox('email 不存在時登入回 422')]
    public function test_login_rejects_unknown_email()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[TestDox('登入必須提供 email 與密碼')]
    public function test_login_requires_email_and_password()
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    #[TestDox('發出的 token 可用於後續請求的認證')]
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

    #[TestDox('登出會撤銷 token')]
    public function test_logout_revokes_the_token()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ]);
        $token = $user->createToken('my-dev-grid-front')->plainTextToken;

        // 登出 2026-10-03 起要求 Origin＝FRONTEND_URL（EnsureFrontendOrigin），
        // 瀏覽器裡的前端一定會帶，這裡照實際請求補上。
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Origin', config('app.frontend_url'))
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

    #[TestDox('登出必須從前端網域發出')]
    public function test_logout_requires_the_frontend_origin()
    {
        // 登出不要求 token（refresh cookie 也能登出，見 RefreshTokenCookieTest），
        // 但一定要從前端網站發出
        $this->postJson('/api/auth/logout')->assertForbidden();

        $this->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/logout')
            ->assertOk();
    }
}
