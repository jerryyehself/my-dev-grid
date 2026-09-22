<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * my-dev-grid-front（跨 origin SPA）的 email+password 登入/登出，發 Sanctum
 * API token，不是 session（decision-register.md D-56）。跟
 * SessionAuthController 是同一組 v1 需求（D-34）的兩個獨立實作：
 * SessionAuthController 服務同源的 Triple 後台（session cookie），這支服務
 * 跨 origin 的 my-dev-grid-front（bearer token）。刻意不共用同一支
 * controller——Triple 還沒被移除（D-48 卡在本體論 CRUD 搬到前端之前），
 * 改動它現有的登入方式沒有任何好處，純粹增加讓它跟著壞掉的風險。
 */
class TokenLoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Auth::once() 只驗證這次 request、完全不碰 session——token 模式
        // 不該像 SessionAuthController 那樣呼叫 session()->regenerate()，
        // 這裡本來就不该有 session 產生。
        if (! Auth::once($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['帳號或密碼錯誤。'],
            ]);
        }

        $user = Auth::user();
        $token = $user->createToken('my-dev-grid-front')->plainTextToken;

        return response()->json([
            'data' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => '已登出。',
        ]);
    }
}
