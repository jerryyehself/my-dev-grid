<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
     * 現在它落到 catch-all，跟任何其他未知路徑一樣。
     *
     * 這裡刻意**不**用 `$this->get('/projects/create')` 實際發一個請求：catch-all
     * 渲染的 `app.blade.php` 需要 `public/build/manifest.json`，而 `public/build`
     * 沒有進版控、`tests.yml` 也不建前端資產，所以那種寫法只有在本機剛好跑過
     * `npm run build` 時才會綠，推上 CI 就紅（2026-09-16 實際踩過一次）。
     * 這支測試要斷言的是「路由指到哪」，跟前端資產無關，就在路由層斷言。
     */
    public function test_projects_create_is_not_routed_to_a_missing_controller_method()
    {
        $this->assertFalse(Route::has('projects.create'), 'projects.create 不該存在——TechniqueController 沒有 create() 方法。');
        $this->assertFalse(Route::has('projects.edit'), 'projects.edit 不該存在——TechniqueController 沒有 edit() 方法。');

        $matched = Route::getRoutes()->match(Request::create('/projects/create', 'GET'));

        $this->assertSame(
            '{any}',
            $matched->uri(),
            '/projects/create 應該落到 catch-all，而不是被某個資源路由接走。'
        );
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
