<?php

namespace Tests\Feature;

use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TechniqueCRUDTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('登入後可新增技術')]
    public function test_create_technique()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/techniques', [
            'type' => $scope->id,
            'title' => 'Laravel',
            'version' => '13.0',
            'note' => 'unit test note',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['title' => 'Laravel']);

        $this->assertDatabaseHas('techniques', ['title' => 'Laravel', 'type' => $scope->id]);
    }

    #[TestDox('新增技術時 scope 不存在回 422')]
    public function test_create_technique_rejects_unknown_scope()
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/techniques', [
            'type' => 99999,
            'title' => 'Laravel',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    #[TestDox('新增技術未登入回 401')]
    public function test_create_technique_rejects_unauthenticated_request()
    {
        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/techniques', [
            'type' => $scope->id,
            'title' => 'Laravel',
        ]);

        $response->assertUnauthorized();
    }

    #[TestDox('可檢視單一技術')]
    public function test_view_technique()
    {
        $technique = Technique::factory()->create();

        $response = $this->getJson("/api/techniques/{$technique->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $technique->id]);
    }

    #[TestDox('登入後可更新技術')]
    public function test_update_technique()
    {
        $this->actingAsOwner();

        $technique = Technique::factory()->create();

        $response = $this->putJson("/api/techniques/{$technique->id}", [
            'type' => $technique->type,
            'title' => 'Updated title',
            'version' => $technique->version,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Updated title']);

        $this->assertDatabaseHas('techniques', ['id' => $technique->id, 'title' => 'Updated title']);
    }

    #[TestDox('更新技術未登入回 401')]
    public function test_update_technique_rejects_unauthenticated_request()
    {
        $technique = Technique::factory()->create();

        $response = $this->putJson("/api/techniques/{$technique->id}", [
            'type' => $technique->type,
            'title' => 'Updated title',
            'version' => $technique->version,
        ]);

        $response->assertUnauthorized();
    }

    #[TestDox('登入後可刪除技術（軟刪除）')]
    public function test_delete_technique()
    {
        $this->actingAsOwner();

        $technique = Technique::factory()->create();

        $response = $this->deleteJson("/api/techniques/{$technique->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => "{$technique->title} was deleted."]);

        $this->assertSoftDeleted('techniques', ['id' => $technique->id]);
    }

    #[TestDox('刪除訊息會帶上技術版本')]
    public function test_delete_message_names_the_version()
    {
        // 版本是獨立的一筆、title 相同（2026-09-30）：訊息只寫 title 的話，刪的是 Vue 還是 Vue 3 分不出來
        $this->actingAsOwner();

        $technique = Technique::factory()->create(['title' => 'Vue', 'version' => '3']);

        $this->deleteJson("/api/techniques/{$technique->id}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Vue 3 was deleted.']);
    }

    #[TestDox('刪除技術未登入回 401')]
    public function test_delete_technique_rejects_unauthenticated_request()
    {
        $technique = Technique::factory()->create();

        $response = $this->deleteJson("/api/techniques/{$technique->id}");

        $response->assertUnauthorized();
    }

    #[TestDox('可列出所有技術')]
    public function test_list_all_techniques()
    {
        Technique::factory()->create(['title' => 'Vue']);

        $response = $this->getJson('/api/techniques');

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Vue']);
    }
}
