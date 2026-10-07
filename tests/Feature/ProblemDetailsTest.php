<?php

namespace Tests\Feature;

use App\Http\RefreshTokenCookie;
use App\Models\Documentation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * API 的 4xx／5xx 回應是 RFC 9457 problem details（App\Http\ProblemDetails），
 * 同時保留 Laravel 原本的 `message`、`errors`——前端（my-dev-grid-front）跟 Triple
 * 讀的就是這兩個欄位跟狀態碼，這支測試同時釘住「新格式」跟「舊欄位還在」。
 */
class ProblemDetailsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 每一種錯誤共同的檢查：Content-Type、五個 problem details 欄位、`message`。
     */
    private function assertProblem(TestResponse $response, int $status, string $instance): TestResponse
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', Response::$statusTexts[$status])
            ->assertJsonPath('status', $status)
            ->assertJsonPath('instance', $instance);

        $this->assertIsString($response->json('message'), '向下相容：message 一定要在。');
        $this->assertNotSame('', $response->json('detail'));

        return $response;
    }

    public function test_validation_error_keeps_message_and_errors()
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/techniques', []);

        $this->assertProblem($response, 422, '/api/techniques')
            ->assertJsonValidationErrors(['title']);

        $this->assertSame($response->json('message'), $response->json('detail'));
    }

    /**
     * StoreScopeRequest／StoreRelationRequest 以前自己組 422（只有 `errors`，沒有 `message`），
     * 現在跟其他 FormRequest 一樣走 Laravel 的驗證錯誤，`errors` 形狀不變、多了 `message`。
     */
    public function test_scope_and_relation_validation_errors_use_the_same_format()
    {
        $this->actingAsOwner();

        $this->assertProblem($this->postJson('/api/scopes', []), 422, '/api/scopes')
            ->assertJsonValidationErrors(['parent_class', 'name', 'comment']);

        $this->assertProblem($this->postJson('/api/relations', []), 422, '/api/relations')
            ->assertJsonValidationErrors(['subject_id', 'object_id', 'name']);
    }

    public function test_validation_error_is_json_even_without_accept_header()
    {
        $this->actingAsOwner();

        // 不帶 Accept: application/json 時，Laravel 預設會 302 導回上一頁。
        $response = $this->post('/api/scopes', []);

        $this->assertProblem($response, 422, '/api/scopes')
            ->assertJsonValidationErrors(['name']);
    }

    public function test_login_failure_keeps_the_field_error_the_login_form_reads()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertProblem($response, 422, '/api/auth/login')
            ->assertJsonPath('errors.email.0', '帳號或密碼錯誤。');
    }

    public function test_relation_locked_error_keeps_its_extension_fields()
    {
        $this->actingAsOwner();
        $this->seed();

        $relation = Relation::all()->first(
            fn (Relation $r) => ! is_null($r->reverse_id) && $r->reverse_id !== $r->id
        );
        Documentation::factory()
            ->create()
            ->techniques()
            ->attach(Technique::factory()->create()->id, ['relation_id' => $relation->id]);

        $response = $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => $relation->subject_id,
            'object_id' => $relation->object_id,
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
            'name' => $relation->name.'Renamed',
            'note' => $relation->note,
            'reverse_id' => $relation->reverse_id,
        ]);

        $this->assertProblem($response, 422, "/api/relations/{$relation->id}")
            ->assertJsonValidationErrors(['name']);
        $this->assertContains('name', $response->json('locked_fields'));
    }

    public function test_unauthenticated()
    {
        $response = $this->postJson('/api/scopes', []);

        $this->assertProblem($response, 401, '/api/scopes')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    /**
     * 不帶 Accept 時，Laravel 預設會試著導去 `login` 路由（這個專案沒有），變成 500。
     */
    public function test_unauthenticated_without_accept_header_is_still_401()
    {
        $this->assertProblem($this->delete('/api/scopes/1'), 401, '/api/scopes/1');
    }

    public function test_refresh_rejection_keeps_clearing_the_cookie()
    {
        $response = $this->post('/api/auth/refresh', [], [
            'Origin' => config('app.frontend_url'),
            'Accept' => 'application/json',
        ]);

        $this->assertProblem($response, 401, '/api/auth/refresh')
            ->assertJsonPath('message', '登入已過期，請重新登入。');

        $cleared = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === RefreshTokenCookie::NAME);
        $this->assertNotNull($cleared, '401 仍然要叫瀏覽器刪掉 refresh cookie。');
        $this->assertTrue($cleared->isCleared());
    }

    public function test_forbidden_origin()
    {
        $response = $this->postJson('/api/auth/refresh', [], ['Origin' => 'https://evil.example']);

        $this->assertProblem($response, 403, '/api/auth/refresh')
            ->assertJsonPath('message', '不允許的來源。');
    }

    public function test_forbidden_by_policy()
    {
        Route::middleware('api')->get('/api/__test/forbidden', function () {
            throw new AuthorizationException('This action is unauthorized.');
        });

        $this->assertProblem($this->getJson('/api/__test/forbidden'), 403, '/api/__test/forbidden')
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_model_not_found()
    {
        $response = $this->get('/api/scopes/999999');

        $this->assertProblem($response, 404, '/api/scopes/999999');
    }

    public function test_unknown_api_path_is_404_not_the_spa_shell()
    {
        $response = $this->get('/api/does-not-exist');

        $this->assertProblem($response, 404, '/api/does-not-exist');
    }

    public function test_method_not_allowed()
    {
        $response = $this->patchJson('/api/graph');

        $this->assertProblem($response, 405, '/api/graph');
    }

    public function test_too_many_requests_keeps_retry_after()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'x']);
        }

        $response = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'x']);

        $this->assertProblem($response, 429, '/api/auth/login')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5');
    }

    public function test_server_error_does_not_leak_exception_details_in_production()
    {
        config(['app.debug' => false]);

        Route::middleware('api')->get('/api/__test/boom', function () {
            throw new RuntimeException('secret internal detail');
        });

        $response = $this->getJson('/api/__test/boom');

        $this->assertProblem($response, 500, '/api/__test/boom')
            ->assertJsonPath('message', 'Server Error')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line')
            ->assertJsonMissingPath('trace');

        $this->assertStringNotContainsString('secret internal detail', $response->getContent());
    }

    public function test_instance_has_no_query_string()
    {
        $this->assertProblem($this->getJson('/api/graph/path?start=&token=abc'), 422, '/api/graph/path');
    }

    /**
     * 成功的回應不受影響。
     */
    public function test_successful_responses_are_unchanged()
    {
        Scope::factory()->create();

        $this->getJson('/api/scopes')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonMissingPath('status')
            ->assertJsonPath('type', 'scopes');
    }

    /**
     * `/api` 以外的瀏覽器請求（不要 JSON）照舊是 Laravel 的 HTML 錯誤頁或導向，不改成 problem details。
     */
    public function test_non_api_browser_requests_are_not_converted()
    {
        $response = $this->get('/auth/unknown-provider/redirect');

        $response->assertNotFound();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    /**
     * Triple 用的 session 登入（routes/web.php）帶 Accept: application/json，錯誤一樣是 problem details。
     */
    public function test_json_requests_outside_api_prefix_also_get_problem_details()
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $response = $this->postJson('/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertProblem($response, 422, '/auth/login')
            ->assertJsonPath('errors.email.0', '帳號或密碼錯誤。');
    }
}
