<?php

namespace App\Models;

use D076\SanctumRefreshTokens\Models\AuthenticatableUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * 繼承 d076/sanctum-refresh-tokens 的 AuthenticatableUser，不是直接繼承
 * Illuminate\Foundation\Auth\User：套件的 TokenService 建構子型別限定
 * AuthenticatableUser（不是介面），只 use trait 的話換發 refresh token 會 TypeError。
 * AuthenticatableUser 本身就繼承 Illuminate\Foundation\Auth\User，並已經帶上
 * Sanctum 的 HasApiTokens（加上 refresh token 的部分）跟 Notifiable，
 * 所以這裡不用再 use 那兩個 trait。
 */
class User extends AuthenticatableUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** viewerIsSignedIn() 記住結果用的 request attribute 名稱。 */
    private const VIEWER_SIGNED_IN_ATTRIBUTE = 'viewer_is_signed_in';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 這次請求有沒有帶有效的登入態（Bearer token 或同源 session），每個請求只判斷一次。
     *
     * 公開讀取端點靠它決定看不看得到草稿文章與不公開的專案（`Documentation::viewerCanSeeDrafts()`、
     * `Implementation::viewerCanSeeHidden()`），而 Resource 每序列化一列就會問一次。
     * Sanctum 的 guard 只記住「有登入」的結果，token 無效時每問一次就查一次 token 表，
     * 所以結果記在這次請求的 attributes 上。
     */
    public static function viewerIsSignedIn(): bool
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::VIEWER_SIGNED_IN_ATTRIBUTE)) {
            $attributes->set(self::VIEWER_SIGNED_IN_ATTRIBUTE, auth('sanctum')->check());
        }

        return $attributes->get(self::VIEWER_SIGNED_IN_ATTRIBUTE);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
