<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 「改作」與「建置產物」的兩對述詞，借自 schema.org 的 isBasedOn／isBasisFor：
 * 外部 repo（Documentation）isBasisFor 實作、實作 isBasedOnProject 實作。
 * 述詞的受詞只能指到一個頂層分類，所以來源是文件和來源是實作各一對。
 */
class DerivationRelationsSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, string, string, string, string, string, string}> */
    public static function predicates(): iterable
    {
        // [名稱, 反向名稱, 主詞端分類, 受詞端分類, 類號, 子類號, 詞條]
        yield 'isBasisFor（文件 → 實作）' => ['isBasisFor', 'isBasedOn', 'Documentation', 'Implementation', '02', '30', 'isBasisFor'];
        yield 'isBasedOn（實作 → 文件）' => ['isBasedOn', 'isBasisFor', 'Implementation', 'Documentation', '20', '30', 'isBasedOn'];
        yield 'isBasedOnProject（實作 → 實作）' => ['isBasedOnProject', 'isBasisForProject', 'Implementation', 'Implementation', '22', '50', 'isBasedOn'];
        yield 'isBasisForProject（實作 → 實作）' => ['isBasisForProject', 'isBasedOnProject', 'Implementation', 'Implementation', '22', '60', 'isBasisFor'];
    }

    #[DataProvider('predicates')]
    #[TestDox('改作與建置產物的述詞：主詞、受詞、類號、反向與外部詞彙出處都正確')]
    public function test_predicate_definition(string $name, string $reverse, string $subject, string $object, string $classNumber, string $callNumber, string $term): void
    {
        $this->seed();

        $relation = Relation::where('name', $name)->firstOrFail();
        $this->assertSame(Scope::where('name', $subject)->value('id'), $relation->subject_id);
        $this->assertSame(Scope::where('name', $object)->value('id'), $relation->object_id);
        $this->assertSame($classNumber, $relation->class_number);
        $this->assertSame($callNumber, $relation->call_number);
        $this->assertNull($relation->parent_class);
        $this->assertSame(Relation::where('name', $reverse)->value('id'), $relation->reverse_id);
        $this->assertSame('schema', $relation->source_vocabulary);
        $this->assertSame($term, $relation->source_term);
        $this->assertStringContainsString("https://schema.org/{$term}", $relation->note);
    }

    #[TestDox('全部述詞的類號加子類號沒有重複')]
    public function test_class_and_call_numbers_are_unique(): void
    {
        $this->seed();

        $keys = Relation::all()->map(fn (Relation $r) => "{$r->class_number}/{$r->call_number}");

        $this->assertSame($keys->count(), $keys->unique()->count(), '重複的類號：'.$keys->duplicates()->implode(', '));
    }

    #[TestDox('重跑不會多出述詞，反向配對仍然互指')]
    public function test_rerun_keeps_one_row_per_predicate_and_pairs(): void
    {
        $this->seed();
        $this->seed();

        foreach (['isBasisFor', 'isBasedOn', 'isBasedOnProject', 'isBasisForProject'] as $name) {
            $relation = Relation::withTrashed()->where('name', $name)->get();
            $this->assertCount(1, $relation, $name);
            $this->assertSame($relation[0]->id, Relation::find($relation[0]->reverse_id)->reverse_id, $name);
        }
    }

    #[TestDox('部署 seed 把四條述詞補進已有資料的資料庫，原有述詞的 id 不變')]
    public function test_deploy_seed_adds_the_predicates_to_an_existing_database(): void
    {
        $this->seed();
        $names = ['isBasisFor', 'isBasedOn', 'isBasedOnProject', 'isBasisForProject'];
        Relation::whereIn('name', $names)->update(['reverse_id' => null]);
        Relation::whereIn('name', $names)->forceDelete();
        $existing = Relation::orderBy('id')->pluck('id', 'name')->all();

        $this->artisan('db:seed', ['--class' => 'ReferenceDataSeeder', '--force' => true])->assertSuccessful();

        $this->assertSame($existing, Relation::whereNotIn('name', $names)->orderBy('id')->pluck('id', 'name')->all());
        $this->assertSame(4, Relation::whereIn('name', $names)->count());
        $this->assertSame(Relation::where('name', 'isBasedOn')->value('id'), Relation::where('name', 'isBasisFor')->value('reverse_id'));
        $this->assertSame(Relation::where('name', 'isBasisForProject')->value('id'), Relation::where('name', 'isBasedOnProject')->value('reverse_id'));
    }

    #[TestDox('編輯器：外部 repo（repository）用 isBasisFor 連到專案可以；反向的 isBasedOn 與實作用的 isBasedOnProject 都 422')]
    public function test_editor_accepts_is_basis_for_from_an_external_repository(): void
    {
        $this->seed();
        $this->actingAsOwner();
        $repository = Scope::where('name', 'repository')->value('id');
        $project = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id')]);
        $id = fn (string $name) => Relation::where('name', $name)->value('id');

        $this->postJson('/api/documentations', [
            'type' => $repository, 'title' => 'external repo',
            'implementations' => [['id' => $project->id, 'relation_id' => $id('isBasisFor')]],
        ])->assertCreated();

        foreach (['isBasedOn', 'isBasedOnProject', 'isBasisForProject'] as $name) {
            $this->postJson('/api/documentations', [
                'type' => $repository, 'title' => "wrong {$name}",
                'implementations' => [['id' => $project->id, 'relation_id' => $id($name)]],
            ])->assertUnprocessable()->assertJsonValidationErrors(['implementations.0.relation_id']);
        }
    }

    #[TestDox('編輯器：從專案那一側用 isBasisFor 連到外部 repo 也可以；實作對實作的述詞不能拿來連文件')]
    public function test_editor_accepts_it_from_the_implementation_side(): void
    {
        $this->seed();
        $this->actingAsOwner();
        $repository = Documentation::factory()->create(['type' => Scope::where('name', 'repository')->value('id')]);
        $project = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id')]);
        $id = fn (string $name) => Relation::where('name', $name)->value('id');

        $this->putJson("/api/implementations/{$project->id}", [
            'type' => $project->type, 'title' => $project->title,
            'documentations' => [['id' => $repository->id, 'relation_id' => $id('isBasisFor')]],
        ])->assertOk();

        foreach (['isBasedOn', 'isBasedOnProject', 'isBasisForProject'] as $name) {
            $this->putJson("/api/implementations/{$project->id}", [
                'type' => $project->type, 'title' => $project->title,
                'documentations' => [['id' => $repository->id, 'relation_id' => $id($name)]],
            ])->assertUnprocessable()->assertJsonValidationErrors(['documentations.0.relation_id']);
        }
    }
}
