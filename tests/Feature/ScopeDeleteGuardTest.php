<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 刪除分類（Scope）前要擋掉會讓述詞變成孤兒的情況。
 *
 * 刪分類會連帶軟刪除主體或客體是它的述詞，而軟刪除不會處理引用那些述詞的資料：
 * 邊會指著一條查不到的述詞，分類以外的述詞的 reverse_id 會指著已刪除的那條。
 * 所以那批述詞只要還有邊在用、或還有分類以外的述詞把它們當反向關係，就回 422
 * 並說明各剩幾個，而且什麼都不刪；沒被引用的照舊可以刪。判斷規則跟刪單一述詞
 * （RelationDeleteGuardTest）同一套。
 */
class ScopeDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    /** 四張連結表各掛一筆邊（每個連結表一種），用來確認每一張都有算進去。 */
    public static function linkKinds(): array
    {
        return [
            'documentation_technique' => ['documentation_technique'],
            'documentation_implementation' => ['documentation_implementation'],
            'technique_implementation' => ['technique_implementation'],
            'entity_relations' => ['entity_relations'],
        ];
    }

    private function link(Relation $relation, string $table): void
    {
        match ($table) {
            'documentation_technique' => Documentation::factory()->create()
                ->techniques()->attach(Technique::factory()->create()->id, ['relation_id' => $relation->id]),
            'documentation_implementation' => Documentation::factory()->create()
                ->implementations()->attach(Implementation::factory()->create()->id, ['relation_id' => $relation->id]),
            'technique_implementation' => Technique::factory()->create()
                ->implementations()->attach(Implementation::factory()->create()->id, ['relation_id' => $relation->id]),
            'entity_relations' => EntityRelation::factory()->create(['relation_id' => $relation->id]),
        };
    }

    /** 造一條以 $scope 為主體的述詞（客體是另一個新分類）。 */
    private function predicateOf(Scope $scope, array $attributes = []): Relation
    {
        return Relation::factory()->create(['subject_id' => $scope->id, ...$attributes]);
    }

    /** 造一對配成雙向的述詞（寫入 B 的 reverse_id，syncReverse() 會把 A 補成指回 B）。 */
    private function pair(Relation $a, Relation $b): void
    {
        $b->update(['reverse_id' => $a->id]);

        $this->assertSame($b->id, $a->fresh()->reverse_id, '前置條件：A 要被補成指回 B。');
    }

    #[TestDox('底下的述詞都沒被引用時，分類照舊可以刪，述詞連帶軟刪除')]
    public function test_scope_with_unused_predicates_is_deleted_with_them()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $asSubject = $this->predicateOf($scope);
        $asObject = Relation::factory()->create(['object_id' => $scope->id]);

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertOk()
            ->assertJsonFragment(['message' => "{$scope->name} was deleted."]);

        $this->assertSoftDeleted('scopes', ['id' => $scope->id]);
        $this->assertSoftDeleted('relations', ['id' => $asSubject->id]);
        $this->assertSoftDeleted('relations', ['id' => $asObject->id]);
    }

    #[TestDox('沒有任何述詞的分類照舊可以刪')]
    public function test_scope_without_predicates_is_deleted()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();

        $this->deleteJson("/api/scopes/{$scope->id}")->assertOk();

        $this->assertSoftDeleted('scopes', ['id' => $scope->id]);
    }

    #[DataProvider('linkKinds')]
    #[TestDox('底下的述詞被連結表的邊引用時回 422 並說明邊數，什麼都不刪')]
    public function test_scope_with_predicate_used_by_links_is_refused(string $table)
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create(['name' => 'usedScope']);
        $relation = $this->predicateOf($scope);
        $this->link($relation, $table);
        $this->link($relation, $table);

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '分類「usedScope」底下的述詞仍被引用，不能刪除：被 2 筆邊使用。請先移除這些引用。')
            ->assertJsonPath('errors.scope.0', '分類「usedScope」底下的述詞仍被引用，不能刪除：被 2 筆邊使用。請先移除這些引用。')
            ->assertJsonPath('reference_counts', ['links' => 2, 'inverse_of' => 0]);

        $this->assertNotSoftDeleted('scopes', ['id' => $scope->id]);
        $this->assertNotSoftDeleted('relations', ['id' => $relation->id]);
    }

    #[TestDox('述詞以分類為客體時也要檢查')]
    public function test_predicate_with_scope_as_object_is_checked()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $relation = Relation::factory()->create(['object_id' => $scope->id]);
        $this->link($relation, 'entity_relations');

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertJsonPath('reference_counts', ['links' => 1, 'inverse_of' => 0]);

        $this->assertNotSoftDeleted('scopes', ['id' => $scope->id]);
        $this->assertNotSoftDeleted('relations', ['id' => $relation->id]);
    }

    #[TestDox('多條述詞的邊數、四張連結表的邊數加總')]
    public function test_links_are_summed_across_predicates_and_tables()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $first = $this->predicateOf($scope);
        $second = Relation::factory()->create(['object_id' => $scope->id]);
        foreach (array_keys(self::linkKinds()) as $table) {
            $this->link($first, $table);
        }
        $this->link($second, 'entity_relations');

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertJsonPath('reference_counts.links', 5);
    }

    #[TestDox('主體和客體都是這個分類的述詞，邊只算一次')]
    public function test_predicate_with_scope_as_both_ends_is_counted_once()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $relation = Relation::factory()->create(['subject_id' => $scope->id, 'object_id' => $scope->id]);
        $this->link($relation, 'entity_relations');

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertJsonPath('reference_counts', ['links' => 1, 'inverse_of' => 0]);
    }

    #[TestDox('有被引用的述詞時，同分類沒被引用的述詞也不會被刪')]
    public function test_nothing_is_deleted_when_only_some_predicates_are_used()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $used = $this->predicateOf($scope);
        $unused = $this->predicateOf($scope);
        $this->link($used, 'documentation_technique');

        $this->deleteJson("/api/scopes/{$scope->id}")->assertStatus(422);

        $this->assertNotSoftDeleted('scopes', ['id' => $scope->id]);
        $this->assertNotSoftDeleted('relations', ['id' => $used->id]);
        $this->assertNotSoftDeleted('relations', ['id' => $unused->id]);
    }

    #[TestDox('被分類以外的述詞設為反向關係時回 422 並說明條數，對方不被動到')]
    public function test_predicate_paired_with_one_outside_the_scope_is_refused()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create(['name' => 'pairedScope']);
        $inside = $this->predicateOf($scope);
        $outside = Relation::factory()->create();
        $this->pair($inside, $outside);

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '分類「pairedScope」底下的述詞仍被引用，不能刪除：被 1 條分類以外的述詞設為反向關係。請先移除這些引用。')
            ->assertJsonPath('reference_counts', ['links' => 0, 'inverse_of' => 1]);

        $this->assertNotSoftDeleted('scopes', ['id' => $scope->id]);
        $this->assertNotSoftDeleted('relations', ['id' => $inside->id]);
        $this->assertSame($inside->id, $outside->fresh()->reverse_id, '反向那條不能被動到。');
    }

    #[TestDox('同時被邊和分類以外的反向關係引用時兩種數量都列出')]
    public function test_both_kinds_are_reported()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create(['name' => 'bothScope']);
        $inside = $this->predicateOf($scope);
        $this->pair($inside, Relation::factory()->create());
        $this->link($inside, 'documentation_technique');

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '分類「bothScope」底下的述詞仍被引用，不能刪除：被 1 筆邊使用、被 1 條分類以外的述詞設為反向關係。請先移除這些引用。')
            ->assertJsonPath('reference_counts', ['links' => 1, 'inverse_of' => 1]);
    }

    #[TestDox('同一分類內配成一對的述詞不算外部引用，一起軟刪除')]
    public function test_pair_inside_the_same_scope_is_not_an_external_reference()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        // 一條以分類為主體、另一條以分類為客體，兩條都會被連帶刪除。
        $a = $this->predicateOf($scope);
        $b = Relation::factory()->create(['object_id' => $scope->id]);
        $this->pair($a, $b);

        $this->deleteJson("/api/scopes/{$scope->id}")->assertOk();

        $this->assertSoftDeleted('scopes', ['id' => $scope->id]);
        $this->assertSoftDeleted('relations', ['id' => $a->id]);
        $this->assertSoftDeleted('relations', ['id' => $b->id]);
    }

    #[TestDox('同一分類內成對的述詞，若其中一條有邊仍回 422 且邊數照算')]
    public function test_pair_inside_the_same_scope_still_counts_its_links()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $a = $this->predicateOf($scope);
        $b = $this->predicateOf($scope);
        $this->pair($a, $b);
        $this->link($b, 'entity_relations');

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertJsonPath('reference_counts', ['links' => 1, 'inverse_of' => 0]);

        $this->assertNotSoftDeleted('relations', ['id' => $a->id]);
        $this->assertNotSoftDeleted('relations', ['id' => $b->id]);
    }

    #[TestDox('對稱述詞（反向是自己）沒有其他引用時，分類可以刪')]
    public function test_symmetric_predicate_does_not_block()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $relation = $this->predicateOf($scope);
        $relation->update(['reverse_id' => $relation->id]);

        $this->deleteJson("/api/scopes/{$scope->id}")->assertOk();

        $this->assertSoftDeleted('relations', ['id' => $relation->id]);
    }

    #[TestDox('分類以外的述詞被引用，不影響這個分類的刪除')]
    public function test_references_to_predicates_of_other_scopes_are_ignored()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $this->predicateOf($scope);
        $unrelated = Relation::factory()->create();
        $this->link($unrelated, 'entity_relations');

        $this->deleteJson("/api/scopes/{$scope->id}")->assertOk();

        $this->assertNotSoftDeleted('relations', ['id' => $unrelated->id]);
    }

    #[TestDox('已軟刪除的述詞不在連帶刪除的範圍內，不算引用')]
    public function test_soft_deleted_predicate_is_not_counted()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $deleted = $this->predicateOf($scope);
        $this->link($deleted, 'entity_relations');
        $deleted->delete();

        $this->deleteJson("/api/scopes/{$scope->id}")->assertOk();

        $this->assertSoftDeleted('scopes', ['id' => $scope->id]);
    }

    #[TestDox('先移除引用之後，分類就可以刪')]
    public function test_scope_is_deletable_once_references_are_gone()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $relation = $this->predicateOf($scope);
        $edge = EntityRelation::factory()->create(['relation_id' => $relation->id]);

        $this->deleteJson("/api/scopes/{$scope->id}")->assertStatus(422);

        $edge->delete();

        $this->deleteJson("/api/scopes/{$scope->id}")->assertOk();
        $this->assertSoftDeleted('relations', ['id' => $relation->id]);
    }

    #[TestDox('回應是 problem details，且不是 500')]
    public function test_refusal_is_problem_details()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $this->link($this->predicateOf($scope), 'entity_relations');

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('instance', "/api/scopes/{$scope->id}");
    }

    #[TestDox('未登入時先回 401，不洩漏引用數量')]
    public function test_guest_gets_401_before_the_reference_check()
    {
        $scope = Scope::factory()->create();
        $this->link($this->predicateOf($scope), 'entity_relations');

        $this->deleteJson("/api/scopes/{$scope->id}")
            ->assertUnauthorized()
            ->assertJsonMissingPath('reference_counts');
    }

    #[TestDox('已軟刪除的分類再刪回 404')]
    public function test_deleting_a_soft_deleted_scope_is_404()
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();
        $scope->delete();

        $this->deleteJson("/api/scopes/{$scope->id}")->assertNotFound();
    }
}
