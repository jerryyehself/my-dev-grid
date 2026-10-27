<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * GET /api/user：前端開機時確認登入狀態。
 */
class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('未登入取得目前使用者回 401')]
    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    #[TestDox('已登入時回傳目前使用者')]
    public function test_authenticated_request_gets_current_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }
}
