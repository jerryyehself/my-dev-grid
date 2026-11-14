<?php

namespace Tests\Feature;

use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 分類的名稱與子類號，新增與更新的唯一性規則要一致。
 *
 * - 名稱：兩邊都不算已軟刪除的分類。更新如果會算，刪掉分類「X」後新增同名的「X」成功，
 *   之後編輯這一筆、名稱不改，就會撞到已刪除的那一筆而回 422（建得出來、存不回去）。
 * - 子類號：兩邊都擋「同一個類號底下重複」，不同類號底下可以相同，已軟刪除的分類不算。
 *
 * 類號與子類號都明確指定，不靠工廠隨機挑，測試的結果才不會跟著亂數變。
 */
class ScopeUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Scope $parent;

    private Scope $otherParent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsOwner();

        $this->parent = Scope::factory()->create(['class_number' => '88', 'call_number' => '00', 'parent_class' => null]);
        $this->otherParent = Scope::factory()->create(['class_number' => '77', 'call_number' => '00', 'parent_class' => null]);
    }

    /** @param array<string, mixed> $overrides */
    private function child(string $callNumber, array $overrides = []): Scope
    {
        return Scope::factory()->create([
            'class_number' => $this->parent->class_number,
            'call_number' => $callNumber,
            'parent_class' => $this->parent->id,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Payload',
            'comment' => 'comment',
            'parent_class' => $this->parent->id,
            'call_number' => '10',
            ...$overrides,
        ];
    }

    // ---- 名稱 ----

    #[TestDox('新增分類時，名稱跟已刪除的分類相同可以建立')]
    public function test_store_allows_the_name_of_a_trashed_scope(): void
    {
        $this->child('11', ['name' => 'Taken'])->delete();

        $this->postJson('/api/scopes', $this->payload(['name' => 'Taken']))->assertCreated();

        $this->assertDatabaseCount('scopes', 4);
    }

    #[TestDox('新增分類時，名稱跟還在的分類相同回 422')]
    public function test_store_rejects_the_name_of_a_live_scope(): void
    {
        $this->child('11', ['name' => 'Taken']);

        $this->postJson('/api/scopes', $this->payload(['name' => 'Taken']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    #[TestDox('更新分類時，改成已刪除分類用過的名稱可以存')]
    public function test_update_allows_the_name_of_a_trashed_scope(): void
    {
        $this->child('11', ['name' => 'Taken'])->delete();
        $scope = $this->child('12', ['name' => 'Mine']);

        $this->putJson("/api/scopes/{$scope->id}", $this->payload(['name' => 'Taken', 'call_number' => '12']))
            ->assertOk();

        $this->assertSame('Taken', $scope->fresh()->name);
    }

    #[TestDox('刪除分類後同名新增，再更新新的那一筆（名稱不改）可以存')]
    public function test_recreated_scope_can_be_saved_without_renaming(): void
    {
        $this->child('11', ['name' => 'Recreated'])->delete();
        $recreatedId = $this->postJson('/api/scopes', $this->payload(['name' => 'Recreated', 'call_number' => '12']))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/scopes/{$recreatedId}", $this->payload(['name' => 'Recreated', 'call_number' => '12', 'comment' => 'edited']))
            ->assertOk();

        $this->assertSame('edited', Scope::find($recreatedId)->comment);
    }

    #[TestDox('更新分類時，改成還在的分類的名稱回 422')]
    public function test_update_rejects_the_name_of_a_live_scope(): void
    {
        $this->child('11', ['name' => 'Taken']);
        $scope = $this->child('12', ['name' => 'Mine']);

        $this->putJson("/api/scopes/{$scope->id}", $this->payload(['name' => 'Taken', 'call_number' => '12']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    // ---- 子類號 ----

    #[TestDox('新增分類時，同一個類號底下子類號重複回 422')]
    public function test_store_rejects_a_duplicate_call_number_in_the_same_class(): void
    {
        $this->child('10');

        $response = $this->postJson('/api/scopes', $this->payload(['call_number' => '10']));

        $response->assertUnprocessable()->assertJsonValidationErrors('call_number');
        $this->assertSame(['call_number'], array_keys($response->json('errors')));
    }

    #[TestDox('新增分類時，不同類號底下的相同子類號可以建立')]
    public function test_store_allows_the_same_call_number_in_a_different_class(): void
    {
        $this->child('10');

        $this->postJson('/api/scopes', $this->payload(['call_number' => '10', 'parent_class' => $this->otherParent->id]))
            ->assertCreated();

        $this->assertDatabaseHas('scopes', ['class_number' => '77', 'call_number' => '10', 'name' => 'Payload']);
    }

    #[TestDox('新增分類時，子類號跟已刪除的分類相同可以建立')]
    public function test_store_allows_the_call_number_of_a_trashed_scope(): void
    {
        $this->child('10')->delete();

        $this->postJson('/api/scopes', $this->payload(['call_number' => '10']))->assertCreated();
    }

    #[TestDox('新增分類時，子類號留空不檢查重複')]
    public function test_store_does_not_check_duplicates_for_an_empty_call_number(): void
    {
        $this->child('10', ['call_number' => null]);

        $this->postJson('/api/scopes', $this->payload(['call_number' => null]))->assertCreated();
    }

    #[TestDox('新增分類時，父層不是整數只報父層的錯，不會 500')]
    public function test_store_with_a_non_integer_parent_only_reports_the_parent(): void
    {
        $response = $this->postJson('/api/scopes', $this->payload(['parent_class' => 'abc']));

        $response->assertUnprocessable()->assertJsonValidationErrors('parent_class');
        $this->assertSame(['parent_class'], array_keys($response->json('errors')));
    }

    #[TestDox('更新分類時，改成同一個類號底下已有的子類號回 422')]
    public function test_update_rejects_a_duplicate_call_number_in_the_same_class(): void
    {
        $this->child('10');
        $scope = $this->child('12');

        $this->putJson("/api/scopes/{$scope->id}", $this->payload(['name' => $scope->name, 'call_number' => '10']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('call_number');
    }

    #[TestDox('更新分類時，改成不同類號底下的相同子類號可以存')]
    public function test_update_allows_the_same_call_number_in_a_different_class(): void
    {
        Scope::factory()->create(['class_number' => '77', 'call_number' => '10', 'parent_class' => $this->otherParent->id]);
        $scope = $this->child('12');

        $this->putJson("/api/scopes/{$scope->id}", $this->payload(['name' => $scope->name, 'call_number' => '10']))
            ->assertOk();
    }

    #[TestDox('更新分類時，子類號不改可以存')]
    public function test_update_allows_keeping_its_own_call_number(): void
    {
        $this->child('10');
        $scope = $this->child('12');

        $this->putJson("/api/scopes/{$scope->id}", $this->payload(['name' => $scope->name, 'call_number' => '12', 'comment' => 'edited']))
            ->assertOk();
    }

    #[TestDox('更新分類時，子類號跟已刪除的分類相同可以存')]
    public function test_update_allows_the_call_number_of_a_trashed_scope(): void
    {
        $this->child('10')->delete();
        $scope = $this->child('12');

        $this->putJson("/api/scopes/{$scope->id}", $this->payload(['name' => $scope->name, 'call_number' => '10']))
            ->assertOk();
    }

    #[TestDox('更新分類時，換到別的父層後以新類號檢查子類號重複')]
    public function test_update_checks_the_call_number_against_the_new_parent_class(): void
    {
        Scope::factory()->create(['class_number' => '77', 'call_number' => '10', 'parent_class' => $this->otherParent->id]);
        $scope = $this->child('10');

        $this->putJson("/api/scopes/{$scope->id}", $this->payload(['name' => $scope->name, 'call_number' => '10', 'parent_class' => $this->otherParent->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('call_number');
    }
}
