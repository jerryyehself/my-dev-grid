<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
