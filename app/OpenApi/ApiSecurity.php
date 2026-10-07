<?php

namespace App\OpenApi;

use App\Http\RefreshTokenCookie;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * 每支端點在 OpenAPI 文件裡標出它實際吃的驗證方式。
 *
 * 文件層級預設是 Sanctum bearer token（`sanctum`，見 AppServiceProvider::configureApiDocs）：
 *
 * - 掛了 `auth:sanctum` 的端點：沿用預設。
 * - `POST /auth/refresh`：不看 Authorization，只看 refresh cookie。
 * - `POST /auth/logout`：bearer 或 refresh cookie 任一個都可以，兩個都沒有也回 200。
 * - 其他：公開，不需要驗證（`security: []`）。
 *
 * 會讀寫 refresh cookie 的三支（refresh、session、logout）另外都要求 `Origin` header
 * 等於前端網址（EnsureFrontendOrigin），不符合回 403，寫進端點說明。
 */
class ApiSecurity implements OperationTransformer
{
    public const BEARER = 'sanctum';

    public const REFRESH_COOKIE = 'refreshCookie';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $middleware = $route->gatherMiddleware();

        $operation->security = match (true) {
            $route->getName() === 'api.auth.refresh' => [
                new SecurityRequirement([self::REFRESH_COOKIE => []]),
            ],
            $route->getName() === 'api.auth.logout' => [
                new SecurityRequirement([self::BEARER => []]),
                new SecurityRequirement([self::REFRESH_COOKIE => []]),
                new SecurityRequirement([]),
            ],
            in_array('auth:sanctum', $middleware, true) => null,
            default => [],
        };

        if (in_array('frontend.origin', $middleware, true)) {
            $operation->description = trim($operation->description."\n\n"
                .'只接受前端網站發出的請求：`Origin` header 必須等於前端網址，否則回 403。'
                .'refresh token 放在 `'.RefreshTokenCookie::NAME.'` cookie（HttpOnly、Secure、SameSite=None、Partitioned），'
                ."瀏覽器端要用 `credentials: 'include'` 才會送出與更新。");

            // 403 是 middleware 回的，Scramble 只分析 controller，看不到。內容格式由
            // ProblemDetailsResponses 統一補上。
            $hasForbidden = collect($operation->responses ?? [])
                ->contains(fn ($response) => $response instanceof Response && (int) $response->code === 403);

            if (! $hasForbidden) {
                $operation->addResponse(Response::make(403)->setDescription('`Origin` 不是前端網址。'));
            }
        }
    }
}
