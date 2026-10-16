<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/user：前端開機時確認登入狀態。
 */
class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_authenticated_request_gets_current_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }
}
