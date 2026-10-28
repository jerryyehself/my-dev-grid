<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/user：回傳目前登入的使用者。
 *
 * 用 controller 而不是 routes/api.php 裡的 closure，是因為 production 會跑
 * `route:cache`，closure 會被序列化，Scramble 讀不到它身上的 #[Endpoint]，
 * API 文件的標題就掉了。
 */
class CurrentUserController extends Controller
{
    // 前端（SPA）開機時拿這支確認目前的登入狀態——未登入回 401，
    // 已登入回目前的 User。`auth:sanctum` 對同源 session cookie 跟
    // my-dev-grid-front（bearer token）一視同仁，兩種認證方式都吃。
    #[Endpoint(
        title: '取得目前使用者',
        description: '回傳目前登入的使用者；未登入回 401。',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        // 用 response()->json() 包，不直接回傳 Resource：直接回傳會多包一層 `data`，
        // 這支端點回的是使用者本身，跟資源的 show 一樣不包 `data`。
        return response()->json(new UserResource($request->user()));
    }
}
