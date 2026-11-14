<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 刪除述詞（Relation）前要擋掉仍被引用的。
 *
 * 軟刪除不會連帶處理引用它的資料：邊會指著一條查不到的述詞，配成一對的另一條
 * 述詞的 reverse_id 會指著已刪除的那條。所以只要還有邊在用，或還有別條述詞把它當
 * 反向關係，就回 422 並說明各剩幾個；沒被引用的照舊可以刪。
 */
class RelationDeleteGuardTest extends TestCase
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

    /** 造一對配成雙向的述詞（寫入 B 的 reverse_id，syncReverse() 會把 A 補成指回 B）。 */
    private function pair(): array
    {
        $a = Relation::factory()->create();
        $b = Relation::factory()->create(['reverse_id' => $a->id]);

        $this->assertSame($b->id, $a->fresh()->reverse_id, '前置條件：A 要被補成指回 B。');

        return [$a->fresh(), $b->fresh()];
    }

    #[TestDox('沒被引用的述詞照舊可以刪')]
    public function test_unreferenced_relation_is_deleted()
    {
        $this->actingAsOwner();
        $relation = Relation::factory()->create();

        $this->deleteJson("/api/relations/{$relation->id}")
            ->assertOk()
            ->assertJsonFragment(['message' => "{$relation->name} was deleted."]);

        $this->assertSoftDeleted('relations', ['id' => $relation->id]);
    }

    #[DataProvider('linkKinds')]
    #[TestDox('被連結表的邊引用時回 422 並說明邊數')]
    public function test_relation_used_by_links_is_refused(string $table)
    {
        $this->actingAsOwner();
        $relation = Relation::factory()->create(['name' => 'usedByLinks']);
        $this->link($relation, $table);
        $this->link($relation, $table);

        $this->deleteJson("/api/relations/{$relation->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '述詞「usedByLinks」仍被引用，不能刪除：被 2 筆邊使用。請先移除這些引用。')
            ->assertJsonPath('errors.relation.0', '述詞「usedByLinks」仍被引用，不能刪除：被 2 筆邊使用。請先移除這些引用。')
            ->assertJsonPath('reference_counts', ['links' => 2, 'inverse_of' => 0]);

        $this->assertNotSoftDeleted('relations', ['id' => $relation->id]);
    }

    #[TestDox('四張連結表的邊加總計入')]
    public function test_links_from_all_tables_are_summed()
    {
        $this->actingAsOwner();
        $relation = Relation::factory()->create();
        foreach (array_keys(self::linkKinds()) as $table) {
            $this->link($relation, $table);
        }

        $this->deleteJson("/api/relations/{$relation->id}")
            ->assertStatus(422)
            ->assertJsonPath('reference_counts.links', 4);
    }

    #[TestDox('被別條述詞設為反向關係時回 422 並說明條數')]
    public function test_relation_used_as_inverse_is_refused()
    {
        $this->actingAsOwner();
        [$a, $b] = $this->pair();

        $this->deleteJson("/api/relations/{$a->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', "述詞「{$a->name}」仍被引用，不能刪除：被 1 條述詞設為反向關係。請先移除這些引用。")
            ->assertJsonPath('reference_counts', ['links' => 0, 'inverse_of' => 1]);

        $this->assertNotSoftDeleted('relations', ['id' => $a->id]);
        $this->assertSame($a->id, $b->fresh()->reverse_id, '反向那條不能被動到。');
    }

    #[TestDox('同時被邊和反向關係引用時兩種數量都列出')]
    public function test_relation_with_both_kinds_reports_both()
    {
        $this->actingAsOwner();
        [$a] = $this->pair();
        $this->link($a, 'documentation_technique');
        $this->link($a, 'entity_relations');
        $this->link($a, 'entity_relations');

        $this->deleteJson("/api/relations/{$a->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', "述詞「{$a->name}」仍被引用，不能刪除：被 3 筆邊使用、被 1 條述詞設為反向關係。請先移除這些引用。")
            ->assertJsonPath('reference_counts', ['links' => 3, 'inverse_of' => 1]);
    }

    #[TestDox('回應是 problem details，且不是 500')]
    public function test_refusal_is_problem_details()
    {
        $this->actingAsOwner();
        [$a] = $this->pair();

        $this->deleteJson("/api/relations/{$a->id}")
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('instance', "/api/relations/{$a->id}");
    }

    #[TestDox('反向關係已軟刪除就不算引用')]
    public function test_soft_deleted_inverse_is_not_counted()
    {
        $this->actingAsOwner();
        [$a, $b] = $this->pair();
        $b->delete();

        $this->deleteJson("/api/relations/{$a->id}")->assertOk();

        $this->assertSoftDeleted('relations', ['id' => $a->id]);
    }

    #[TestDox('對稱關係（反向是自己）沒有其他引用時可以刪')]
    public function test_symmetric_relation_is_deletable()
    {
        $this->actingAsOwner();
        $relation = Relation::factory()->create();
        $relation->update(['reverse_id' => $relation->id]);

        $this->deleteJson("/api/relations/{$relation->id}")->assertOk();

        $this->assertSoftDeleted('relations', ['id' => $relation->id]);
    }

    #[TestDox('對稱關係被邊引用時仍回 422')]
    public function test_symmetric_relation_with_links_is_refused()
    {
        $this->actingAsOwner();
        $relation = Relation::factory()->create();
        $relation->update(['reverse_id' => $relation->id]);
        $this->link($relation, 'entity_relations');

        $this->deleteJson("/api/relations/{$relation->id}")
            ->assertStatus(422)
            ->assertJsonPath('reference_counts', ['links' => 1, 'inverse_of' => 0]);
    }

    #[TestDox('先把反向關係清空解除配對，就可以刪')]
    public function test_unpairing_first_makes_it_deletable()
    {
        $this->actingAsOwner();
        [$a, $b] = $this->pair();

        $this->putJson("/api/relations/{$b->id}", [
            'subject_id' => $b->subject_id,
            'object_id' => $b->object_id,
            'class_number' => $b->class_number,
            'call_number' => $b->call_number,
            'name' => $b->name,
            'note' => $b->note,
            'reverse_id' => null,
        ])->assertOk();

        $this->assertNull($a->fresh()->reverse_id, '配對是雙向解除的。');

        $this->deleteJson("/api/relations/{$a->id}")->assertOk();
    }

    #[TestDox('未登入時先回 401，不洩漏引用數量')]
    public function test_guest_gets_401_before_the_reference_check()
    {
        $relation = Relation::factory()->create();
        $this->link($relation, 'entity_relations');

        $this->deleteJson("/api/relations/{$relation->id}")
            ->assertUnauthorized()
            ->assertJsonMissingPath('reference_counts');
    }

    #[TestDox('已軟刪除的述詞再刪回 404')]
    public function test_deleting_a_soft_deleted_relation_is_404()
    {
        $this->actingAsOwner();
        $relation = Relation::factory()->create();
        $relation->delete();

        $this->deleteJson("/api/relations/{$relation->id}")->assertNotFound();
    }
}
