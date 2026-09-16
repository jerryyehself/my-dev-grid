<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `routes/web.php` 只負責 OAuth/session 登入端點跟渲染 SPA 殼頁面的 catch-all，
 * 不註冊任何資源路由。
 *
 * 這支測試存在的理由是釘住 2026-09-16 移除的那三組死碼
 * （`Route::resources(['scopes', 'relations', 'projects'])`）不會被重新加回來：
 * 它們跟 `routes/api.php` 回傳同一份 JSON，卻少了 `auth:sanctum`，寫入只靠
 * controller 內部的 `$this->authorize()` 擋著。理由寫在 `routes/web.php` 的註解裡。
 */
class WebRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 寫入必須在**路由層**就不存在（405），而不是進到 controller 之後才被
     * `authorize()` 擋成 403——後者代表資源路由又被註冊回來了。
     */
    #[DataProvider('writeRequestsProvider')]
    public function test_web_namespace_exposes_no_resource_write_routes(string $method, string $uri)
    {
        $response = $this->call($method, $uri);

        $this->assertSame(
            405,
            $response->getStatusCode(),
            "{$method} {$uri} 應該在路由層就不存在（405）。收到 403 代表資源路由被重新註冊了，"
            .'寫入變成只靠 controller 內部的 authorize() 擋，不是路由中間件。'
        );
    }

    public static function writeRequestsProvider(): array
    {
        return [
            'POST /scopes' => ['POST', '/scopes'],
            'POST /relations' => ['POST', '/relations'],
            'POST /projects' => ['POST', '/projects'],
        ];
    }

    /**
     * `GET /projects/create` 以前會 500——`projects` 是以完整 resource 註冊的，
     * 會生出 `projects.create`，但 `TechniqueController` 沒有 `create()` 方法。
     * 現在它落到 catch-all，跟任何其他未知路徑一樣渲染 SPA 殼頁面。
     */
    public function test_projects_create_no_longer_five_hundreds()
    {
        $this->get('/projects/create')->assertOk();
    }

    /**
     * 讀取路徑仍然只有 `/api/*` 這一份，沒有被一起移除。
     */
    public function test_api_read_routes_are_untouched()
    {
        $this->getJson('/api/scopes')->assertOk();
        $this->getJson('/api/relations')->assertOk();
    }
}
