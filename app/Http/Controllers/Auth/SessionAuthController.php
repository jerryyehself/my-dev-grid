<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Logging\SecurityEvent;
use App\Logging\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionAuthController extends Controller
{
    /**
     * email+password 備援登入（Google/LINE 帳號沒有時的救援手段，
     * 見 decision-register.md D-34）。
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            SecurityLog::passwordLoginFailed($credentials['email'], 'session');

            // 刻意不區分「帳號不存在」跟「密碼錯誤」，避免透露帳號是否存在。
            throw ValidationException::withMessages([
                'email' => ['帳號或密碼錯誤。'],
            ]);
        }

        $request->session()->regenerate();

        SecurityLog::loginSucceeded(Auth::id(), 'password', 'session');

        return response()->json([
            'data' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $userId = Auth::guard('web')->id();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        SecurityLog::write(SecurityEvent::SessionLogout, $userId === null ? '沒有登入中的 session' : "使用者 {$userId} 登出（session）", [
            'user_id' => $userId,
            'auth_mode' => 'session',
        ]);

        return response()->json([
            'message' => '已登出。',
        ]);
    }
}
