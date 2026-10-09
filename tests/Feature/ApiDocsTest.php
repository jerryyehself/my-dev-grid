<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API 文件（Scramble，D-118）：`/docs/api` 是文件頁、`/docs/api.json` 是 OpenAPI 文件，
 * 不用登入就能看（測試環境不是 local，Scramble 預設的存取限制在這裡會擋成 403）。
 */
class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    private function document(): array
    {
        return $this->getJson('/docs/api.json')->assertOk()->json();
    }

    public function test_docs_page_is_public()
    {
        $this->get('/docs/api')
            ->assertOk()
            ->assertSee('<title>IN / ARCHIVE API</title>', false)
            // 文件頁把 OpenAPI 文件直接嵌在頁面裡交給 Stoplight Elements 渲染
            ->assertSee('<elements-api', false)
            ->assertSee('apiDescriptionDocument', false);
    }

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
    public function test_document_excludes_oauth_redirects_and_web_routes()
    {
        $paths = array_keys($this->document()['paths']);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('{provider}', $path);
        }
    }

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
