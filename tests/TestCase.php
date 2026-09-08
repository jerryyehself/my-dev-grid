<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 以（唯一）owner 身分登入目前的測試 request。
     *
     * 這個專案的 Sanctum 是 SPA 模式（config/sanctum.php 的
     * `guard` 是 `['web']`，config/auth.php 的 `web` guard 是
     * session driver，不是 bearer token）——`auth:sanctum`
     * middleware 實際上就是照 `web` guard 認 session。所以這裡
     * 故意用 Laravel 內建、session 導向的 `actingAs()`，
     * 不是 `Laravel\Sanctum\Sanctum::actingAs()`（那是給
     * token-based API 測試用的，跟這個專案的實際認證機制不符）。
     */
    protected function actingAsOwner(?User $user = null): User
    {
        $user ??= User::factory()->create();

        $this->actingAs($user);

        return $user;
    }
}
