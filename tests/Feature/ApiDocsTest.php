<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * API 文件（Scramble，D-118）：`/docs/api` 是文件頁、`/docs/api.json` 是 OpenAPI 文件，
 * 只在本機開發環境（APP_ENV=local）提供，其他環境由 RestrictedDocsAccess 擋成 403
 * （config/scramble.php）。驗證文件內容的測試先把環境切成 local。
 */
class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml 的 APP_ENV 是 testing；RestrictedDocsAccess 看的是 app()->environment()
        $this->app['env'] = 'local';
    }

    private function document(): array
    {
        return $this->getJson('/docs/api.json')->assertOk()->json();
    }

    /**
     * 正式環境不提供文件：這兩個路由每次請求都會重新分析程式碼，也會列出完整的路由清單。
     * 登入也一樣擋——RestrictedDocsAccess 在非 local 環境會查 `viewApiDocs` gate，
     * 這個專案沒有定義它，所以沒有人能通過。
     */
    #[DataProvider('nonLocalEnvironmentsProvider')]
    #[TestDox('非 local 環境請求 API 文件回 403')]
    public function test_docs_are_forbidden_outside_local(string $environment)
    {
        $this->app['env'] = $environment;

        $this->get('/docs/api')->assertForbidden();
        $this->getJson('/docs/api.json')->assertForbidden();

        $this->actingAs(User::factory()->create());

        $this->get('/docs/api')->assertForbidden();
        $this->getJson('/docs/api.json')->assertForbidden();
    }

    public static function nonLocalEnvironmentsProvider(): array
    {
        return [
            'production' => ['production'],
            'testing' => ['testing'],
        ];
    }

    #[TestDox('local 環境的 API 文件頁不需登入即可瀏覽')]
    public function test_docs_page_is_public_in_local()
    {
        $this->get('/docs/api')
            ->assertOk()
            ->assertSee('<title>IN / ARCHIVE API</title>', false)
            // 文件頁把 OpenAPI 文件直接嵌在頁面裡交給 Stoplight Elements 渲染
            ->assertSee('<elements-api', false)
            ->assertSee('apiDescriptionDocument', false);
    }

    #[TestDox('OpenAPI 文件列出所有主要端點')]
    public function test_document_lists_the_main_endpoints()
    {
        $paths = $this->document()['paths'];

        foreach ([
            '/scopes', '/scopes/{scope}',
            '/relations', '/relations/{relation}', '/relations/{relation}/edges',
            '/documentations', '/documentations/{documentation}',
            '/techniques', '/techniques/{technique}',
            '/implementations', '/implementations/{implementation}',
            '/graph', '/graph/path',
            '/user', '/auth/login', '/auth/logout', '/auth/refresh', '/auth/session',
        ] as $path) {
            $this->assertArrayHasKey($path, $paths, "{$path} 應該在 OpenAPI 文件裡。");
        }
    }

    /**
     * OAuth 跳轉端點（routes/web.php 的 /auth/{provider}/*、/auth/token/{provider}/*）
     * 是瀏覽器導向，不是 API，不列進文件。
     */
    #[TestDox('OpenAPI 文件不含 OAuth 導向與網頁路由')]
    public function test_document_excludes_oauth_redirects_and_web_routes()
    {
        $paths = array_keys($this->document()['paths']);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('{provider}', $path);
        }
    }

    #[TestDox('手寫的圖回應在文件中有欄位說明')]
    public function test_hand_built_graph_response_is_described()
    {
        $schema = $this->document()['paths']['/graph']['get']['responses']['200']['content']['application/json']['schema'];

        $node = $schema['properties']['nodes']['items'];
        $this->assertSame(['id', 'type', 'label', 'created_at', 'subtype', 'url'], array_keys($node['properties']));
        $this->assertSame(
            ['source', 'target', 'predicate', 'label', 'relation_id'],
            array_keys($schema['properties']['edges']['items']['properties'])
        );
    }

    #[TestDox('文件中的錯誤回應採用 problem details')]
    public function test_error_responses_use_problem_details()
    {
        $document = $this->document();

        $this->assertArrayHasKey('ProblemDetails', $document['components']['schemas']);
        $this->assertContains('errors', $document['components']['schemas']['ValidationProblemDetails']['required']);

        $store = $document['paths']['/scopes']['post']['responses'];
        $this->assertSame('#/components/responses/ValidationException', $store['422']['$ref']);
        $this->assertSame(
            '#/components/schemas/ValidationProblemDetails',
            $document['components']['responses']['ValidationException']['content']['application/problem+json']['schema']['$ref']
        );

        // 每支端點都有 429，帶 Retry-After header
        foreach ($document['paths'] as $operations) {
            foreach ($operations as $operation) {
                $this->assertSame('#/components/responses/TooManyRequests', $operation['responses']['429']['$ref']);
            }
        }
        $this->assertArrayHasKey('Retry-After', $document['components']['responses']['TooManyRequests']['headers']);
    }

    #[TestDox('文件的認證方式與路由實際設定一致')]
    public function test_security_schemes_match_the_routes()
    {
        $document = $this->document();

        $this->assertSame('bearer', $document['components']['securitySchemes']['sanctum']['scheme']);
        $this->assertSame('cookie', $document['components']['securitySchemes']['refreshCookie']['in']);

        $paths = $document['paths'];
        // 讀取公開
        $this->assertSame([], $paths['/scopes']['get']['security']);
        $this->assertSame([], $paths['/graph']['get']['security']);
        // 寫入沿用文件層級的 bearer token
        $this->assertArrayNotHasKey('security', $paths['/scopes']['post']);
        $this->assertSame([['sanctum' => []]], $document['security']);
        // refresh 只看 cookie
        $this->assertSame([['refreshCookie' => []]], $paths['/auth/refresh']['post']['security']);
        $this->assertArrayHasKey('403', $paths['/auth/refresh']['post']['responses']);
    }
}
