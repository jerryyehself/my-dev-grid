<?php

namespace Tests\Feature;

use App\Models\Scope;
use Database\Seeders\ScopeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ScopeCRUDTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('登入後可新增 scope，分類號由父層推導')]
    public function test_create_scope()
    {
        $this->actingAsOwner();

        $parent = Scope::factory()->create([
            'class_number' => '99',
            'call_number' => '00',
            'parent_class' => null,
        ]);

        $response = $this->postJson('/api/scopes', [
            'name' => 'Test',
            'parent_class' => $parent->id,
            'call_number' => '10',
            'comment' => 'unit test comment',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Test']);

        $this->assertDatabaseHas('scopes', [
            'name' => 'Test',
            'parent_class' => $parent->id,
            'class_number' => $parent->class_number,
        ]);
    }

    #[TestDox('新增 scope 時父層不存在回 422')]
    public function test_create_scope_rejects_unknown_parent()
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/scopes', [
            'name' => 'Test',
            'parent_class' => '99999',
            'call_number' => '10',
            'comment' => 'unit test comment',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('parent_class');
    }

    #[TestDox('新增 scope 未登入回 401')]
    public function test_create_scope_rejects_unauthenticated_request()
    {
        $response = $this->postJson('/api/scopes', [
            'name' => 'Test',
            'class_number' => '99999',
            'call_number' => '10',
            'comment' => 'unit test comment',
        ]);

        $response->assertUnauthorized();
    }

    #[TestDox('可檢視單一 scope')]
    public function test_view_scope()
    {
        $scope = Scope::factory()->create();

        $response = $this->getJson("/api/scopes/{$scope->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $scope->id]);
    }

    #[TestDox('登入後可更新 scope')]
    public function test_update_scope()
    {
        $this->actingAsOwner();

        $parent = Scope::factory()->create(['class_number' => '99', 'call_number' => '00', 'parent_class' => null]);
        $scope = Scope::factory()->create(['class_number' => '99', 'call_number' => '10', 'parent_class' => $parent->id]);

        $response = $this->putJson("/api/scopes/{$scope->id}", [
            'name' => 'Updated Name',
            'parent_class' => $scope->parent_class,
            'call_number' => $scope->call_number,
            'comment' => 'Updated comment',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name']);

        $this->assertDatabaseHas('scopes', ['id' => $scope->id, 'name' => 'Updated Name']);
    }

    /**
     * class_number 一律由 parent_class 推導:換了父層,class_number 要跟著變。
     *
     * 2026-09-17 之前這是兩個獨立的問題:新增端點的 class_number 收的是**父 Scope 的 id**
     * (controller 再把它搬進 parent_class、用父層的分類號蓋掉),而修改端點的同名欄位收的是
     * **字面分類號**且完全沒有驗證它跟 parent_class 對不對得上。於是可以把一個父層是
     * Documentation(class 00) 的 scope 改成 class_number = 99,製造出實際資料裡一筆都
     * 不存在的不一致(實測 16 筆 scope 的 class_number 全部等於其父層的,0 筆例外)。
     */
    #[TestDox('更換父層時分類號會重新推導')]
    public function test_changing_the_parent_re_derives_the_class_number()
    {
        $this->actingAsOwner();

        $oldParent = Scope::factory()->create(['class_number' => '88', 'call_number' => '00', 'parent_class' => null]);
        $newParent = Scope::factory()->create(['class_number' => '77', 'call_number' => '00', 'parent_class' => null]);
        $scope = Scope::factory()->create(['class_number' => '88', 'call_number' => '10', 'parent_class' => $oldParent->id]);

        $this->putJson("/api/scopes/{$scope->id}", [
            'name' => 'Moved',
            'parent_class' => $newParent->id,
            'call_number' => '10',
            'comment' => 'moved to a new parent',
        ])->assertOk();

        $moved = $scope->fresh();

        $this->assertSame($newParent->id, $moved->parent_class);
        $this->assertSame(
            '77',
            $moved->class_number,
            'class_number 要跟著新的父層走,不能停在舊父層的 88。'
        );
    }

    /**
     * 呼叫端硬送 class_number 一律不算數——它不是可輸入的欄位。
     */
    #[TestDox('呼叫端送來的分類號不算數')]
    public function test_class_number_sent_by_the_client_is_ignored()
    {
        $this->actingAsOwner();

        $parent = Scope::factory()->create(['class_number' => '88', 'call_number' => '00', 'parent_class' => null]);
        $scope = Scope::factory()->create(['class_number' => '88', 'call_number' => '10', 'parent_class' => $parent->id]);

        $this->putJson("/api/scopes/{$scope->id}", [
            'name' => 'Updated Name',
            'parent_class' => $parent->id,
            'call_number' => '10',
            'comment' => 'Updated comment',
            'class_number' => '99',
        ])->assertOk();

        $this->assertSame('88', $scope->fresh()->class_number);
    }

    #[TestDox('更新 scope 未登入回 401')]
    public function test_update_scope_rejects_unauthenticated_request()
    {
        $parent = Scope::factory()->create(['class_number' => '99', 'call_number' => '00', 'parent_class' => null]);
        $scope = Scope::factory()->create(['class_number' => '99', 'call_number' => '10', 'parent_class' => $parent->id]);

        $response = $this->putJson("/api/scopes/{$scope->id}", [
            'name' => 'Updated Name',
            'parent_class' => $scope->parent_class,
            'call_number' => $scope->call_number,
            'comment' => 'Updated comment',
        ]);

        $response->assertUnauthorized();
    }

    #[TestDox('登入後可刪除 scope（軟刪除）')]
    public function test_delete_scope()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();

        $response = $this->deleteJson("/api/scopes/{$scope->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => "{$scope->name} was deleted."]);

        $this->assertSoftDeleted('scopes', ['id' => $scope->id]);
    }

    #[TestDox('刪除 scope 未登入回 401')]
    public function test_delete_scope_rejects_unauthenticated_request()
    {
        $scope = Scope::factory()->create();

        $response = $this->deleteJson("/api/scopes/{$scope->id}");

        $response->assertUnauthorized();
    }

    #[TestDox('可列出所有 scope')]
    public function test_list_all_scopes()
    {
        $this->seed(ScopeSeeder::class);
        $response = $this->getJson('api/scopes');

        $response->assertOk();
        $this->assertCount(16, $response->json('data'), '錯誤');

        $response->assertJsonFragment([
            'name' => 'Documentation',
        ]);
    }

    #[TestDox('檢視 scope 時會附上同層的兄弟 scope')]
    public function test_view_scope_returns_siblings()
    {
        $parent = Scope::factory()->create([
            'class_number' => '99',
            'call_number' => '00',
            'parent_class' => null,
        ]);
        $scope = Scope::factory()->create([
            'class_number' => '99',
            'call_number' => '01',
            'parent_class' => $parent->id,
        ]);
        $sibling = Scope::factory()->create([
            'class_number' => '99',
            'call_number' => '02',
            'parent_class' => $parent->id,
        ]);

        $response = $this->getJson("/api/scopes/{$scope->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('siblings'));
        $this->assertSame($sibling->id, $response->json('siblings.0.id'));
        $response->assertJsonFragment(['name' => $sibling->name]);
    }

    #[TestDox('沒有兄弟的 scope 回傳空陣列')]
    public function test_view_scope_without_siblings_returns_empty_array()
    {
        $parent = Scope::factory()->create([
            'class_number' => '99',
            'call_number' => '00',
            'parent_class' => null,
        ]);
        $onlyChild = Scope::factory()->create([
            'class_number' => '99',
            'call_number' => '01',
            'parent_class' => $parent->id,
        ]);

        $response = $this->getJson("/api/scopes/{$onlyChild->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('siblings'));
    }

    #[TestDox('頂層 scope 沒有兄弟也沒有父層')]
    public function test_view_top_level_scope_has_no_siblings_or_parent()
    {
        Scope::factory()->create([
            'class_number' => '98',
            'call_number' => '00',
            'parent_class' => null,
        ]);
        $topLevelScope = Scope::factory()->create([
            'class_number' => '99',
            'call_number' => '00',
            'parent_class' => null,
        ]);

        $response = $this->getJson("/api/scopes/{$topLevelScope->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('siblings'), '沒有 parent 的頂層 scope 不應該把其他頂層 scope 當成 siblings');
        $this->assertNull($response->json('parent'));
    }
}
