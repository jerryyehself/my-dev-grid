<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * `routes/web.php` 只負責 OAuth/session 登入端點，不註冊任何資源路由，也不渲染任何網頁。
 *
 * 釘住兩件事：
 * - 2026-09-16 移除的那三組死碼（`Route::resources(['scopes', 'relations', 'projects'])`）
 *   不會被重新加回來：它們跟 `routes/api.php` 回傳同一份 JSON，卻少了 `auth:sanctum`，
 *   寫入只靠 controller 內部的 `$this->authorize()` 擋著。
 * - 舊的內嵌 Vue 後台 Triple 跟它的 `/{any}` catch-all 已經移除（管理介面在
 *   my-dev-grid-front，D-48），以前會回 SPA 殼頁面的路徑現在一律 404。
 */
class WebRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 寫入必須在**路由層**就不存在（404），而不是進到 controller 之後才被
     * `authorize()` 擋成 403——後者代表資源路由又被註冊回來了。
     */
    #[DataProvider('writeRequestsProvider')]
    #[TestDox('網頁路由沒有資源寫入端點，回 404')]
    public function test_web_namespace_exposes_no_resource_write_routes(string $method, string $uri)
    {
        $response = $this->call($method, $uri);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            "{$method} {$uri} 應該在路由層就不存在（404）。收到 403 代表資源路由被重新註冊了，"
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
     * 以前這些路徑都會落到 catch-all、回 200 的 SPA 殼頁面（`/triple-control`、`/works`
     * 是 Triple 自己的前端路由，`/projects/create` 以前還是會 500 的資源路由）。
     */
    #[DataProvider('formerSpaPathsProvider')]
    #[TestDox('舊後台的網址回 404，不再渲染 SPA 殼頁面')]
    public function test_former_admin_spa_paths_are_not_found(string $uri)
    {
        $response = $this->get($uri);

        $response->assertNotFound();
        $this->assertStringNotContainsString('id="app"', (string) $response->getContent());
    }

    public static function formerSpaPathsProvider(): array
    {
        return [
            '/' => ['/'],
            '/triple-control' => ['/triple-control'],
            '/works' => ['/works'],
            '/scopes' => ['/scopes'],
            '/projects/create' => ['/projects/create'],
        ];
    }

    #[TestDox('移除 resource 路由後，projects.create 與 projects.edit 都不存在')]
    public function test_projects_create_and_edit_routes_do_not_exist()
    {
        $this->assertFalse(Route::has('projects.create'), 'projects.create 不該存在——TechniqueController 沒有 create() 方法。');
        $this->assertFalse(Route::has('projects.edit'), 'projects.edit 不該存在——TechniqueController 沒有 edit() 方法。');
    }

    /**
     * 拿掉 catch-all 時要一起留下來的路由：健康檢查（Cloud Run 的 startup probe 打它）、
     * 登入端點、API 文件、API 讀取。
     */
    #[TestDox('/up、登入路由、API 文件與 API 讀取都還在')]
    public function test_kept_routes_still_work()
    {
        $this->get('/up')->assertOk();

        foreach ([
            'auth.social.redirect',
            'auth.social.callback',
            'auth.login',
            'auth.logout',
            'auth.token.social.redirect',
            'auth.token.social.callback',
        ] as $name) {
            $this->assertTrue(Route::has($name), "登入路由 {$name} 不該跟著舊後台一起被移除。");
        }

        // API 文件只在 local 環境提供（config/scramble.php），其他環境的行為見 ApiDocsTest
        $this->app['env'] = 'local';
        $this->get('/docs/api.json')->assertOk();

        $this->getJson('/api/scopes')->assertOk();
        $this->getJson('/api/relations')->assertOk();
    }

    /**
     * `docker/entrypoint.sh` 開機時會跑 `php artisan view:cache`，`resources/views`
     * 不存在的話它會丟 DirectoryNotFoundException，容器起不來。舊後台的 blade 移除後
     * 這個資料夾只剩 `.gitkeep`，不能整個刪掉。
     */
    #[TestDox('resources/views 還在，view:cache 跑得過')]
    public function test_view_cache_succeeds_without_any_app_views()
    {
        $this->assertDirectoryExists(resource_path('views'));

        try {
            $this->assertSame(0, Artisan::call('view:cache'));
        } finally {
            Artisan::call('view:clear');
        }
    }
}
