<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 會存網址的 API 欄位只收 http／https。
 *
 * 文件與實作的 `url` 會被當成可點的連結輸出，javascript:、data: 這類 scheme
 * 存進來就可能在點擊時執行；ftp 之類的其他 scheme 也一併擋掉。
 * 每個 FormRequest（新增／修改 × 文件／實作）各驗一次。
 */
class UrlSchemeValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 要被擋下的網址。舊規則（不限 scheme 的 url）已經會擋 javascript:／data:，
     * 但 ftp:// 等其他 scheme 會放行；這裡全部列出，免得之後放寬規則時沒人發現。
     *
     * @return array<string, array{string}>
     */
    public static function rejectedUrlProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript 雙斜線' => ['javascript://example.com/%0Aalert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'ftp' => ['ftp://example.com/file.txt'],
        ];
    }

    /**
     * 每個端點回傳 [HTTP 方法, 路徑, 其餘必填欄位]。
     *
     * @return array{string, string, array<string, mixed>}
     */
    private function endpoint(string $name): array
    {
        return match ($name) {
            'store documentation' => ['POST', '/api/documentations', [
                'type' => Scope::factory()->create()->id,
                'title' => 'Doc',
            ]],
            'update documentation' => (function () {
                $documentation = Documentation::factory()->create();

                return ['PUT', "/api/documentations/{$documentation->id}", [
                    'type' => $documentation->type,
                    'title' => 'Doc',
                ]];
            })(),
            'store implementation' => ['POST', '/api/implementations', [
                'type' => Scope::factory()->create()->id,
                'title' => 'Impl',
            ]],
            'update implementation' => (function () {
                $implementation = Implementation::factory()->create();

                return ['PUT', "/api/implementations/{$implementation->id}", [
                    'type' => $implementation->type,
                    'title' => 'Impl',
                ]];
            })(),
        };
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'store documentation' => ['store documentation'],
            'update documentation' => ['update documentation'],
            'store implementation' => ['store implementation'],
            'update implementation' => ['update implementation'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpointAndRejectedUrlProvider(): array
    {
        $cases = [];
        foreach (self::endpointProvider() as $endpointLabel => [$endpoint]) {
            foreach (self::rejectedUrlProvider() as $urlLabel => [$url]) {
                $cases["{$endpointLabel} / {$urlLabel}"] = [$endpoint, $url];
            }
        }

        return $cases;
    }

    #[DataProvider('endpointAndRejectedUrlProvider')]
    public function test_non_http_scheme_is_rejected(string $endpoint, string $url): void
    {
        $this->actingAsOwner();
        [$method, $uri, $payload] = $this->endpoint($endpoint);

        $this->json($method, $uri, [...$payload, 'url' => $url])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    #[DataProvider('endpointProvider')]
    public function test_https_url_is_accepted(string $endpoint): void
    {
        $this->actingAsOwner();
        [$method, $uri, $payload] = $this->endpoint($endpoint);

        $this->json($method, $uri, [...$payload, 'url' => 'https://example.com/docs?page=1'])
            ->assertSuccessful()
            ->assertJsonFragment(['url' => 'https://example.com/docs?page=1']);
    }

    #[DataProvider('endpointProvider')]
    public function test_http_url_is_accepted(string $endpoint): void
    {
        $this->actingAsOwner();
        [$method, $uri, $payload] = $this->endpoint($endpoint);

        $this->json($method, $uri, [...$payload, 'url' => 'http://example.com/'])
            ->assertSuccessful();
    }

    /**
     * 文件的 `uri` 欄位規則跟 `url` 相同。
     *
     * @return array<string, array{string, string}>
     */
    public static function documentationEndpointAndRejectedUrlProvider(): array
    {
        $cases = [];
        foreach (['store documentation', 'update documentation'] as $endpoint) {
            foreach (self::rejectedUrlProvider() as $urlLabel => [$url]) {
                $cases["{$endpoint} / {$urlLabel}"] = [$endpoint, $url];
            }
        }

        return $cases;
    }

    #[DataProvider('documentationEndpointAndRejectedUrlProvider')]
    public function test_documentation_uri_rejects_non_http_scheme(string $endpoint, string $url): void
    {
        $this->actingAsOwner();
        [$method, $uri, $payload] = $this->endpoint($endpoint);

        $this->json($method, $uri, [...$payload, 'uri' => $url])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uri');
    }

    public function test_documentation_uri_accepts_https(): void
    {
        $this->actingAsOwner();
        [$method, $uri, $payload] = $this->endpoint('store documentation');

        $this->json($method, $uri, [...$payload, 'uri' => 'https://stackoverflow.com/questions/1'])
            ->assertSuccessful()
            ->assertJsonFragment(['uri' => 'https://stackoverflow.com/questions/1']);
    }
}
