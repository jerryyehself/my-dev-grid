<?php

namespace Tests\Feature;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 「專案關於某個技術」的一對述詞（subjectOfProject／projectAbout），借自 schema.org 的 subjectOf／about。
 * GitHub 同步對只在 topic 看到、程式裡沒有證據的技術寫 subjectOfProject（technique_implementation 以技術當主詞）。
 */
class ProjectAboutRelationsSeederTest extends TestCase
{
    use RefreshDatabase;

    private const NAMES = ['subjectOfProject', 'projectAbout'];

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function predicates(): iterable
    {
        // [名稱, 反向名稱, 主詞端分類, 受詞端分類, 類號, 詞條]
        yield 'subjectOfProject（技術 → 專案）' => ['subjectOfProject', 'projectAbout', 'Technique', 'Implementation', '12', 'subjectOf'];
        yield 'projectAbout（專案 → 技術）' => ['projectAbout', 'subjectOfProject', 'Implementation', 'Technique', '21', 'about'];
    }

    #[DataProvider('predicates')]
    #[TestDox('主詞、受詞、類號、反向與外部詞彙出處都正確；跟 usedBy／assists 平輩，子類號 20')]
    public function test_predicate_definition(string $name, string $reverse, string $subject, string $object, string $classNumber, string $term): void
    {
        $this->seed();

        $relation = Relation::where('name', $name)->firstOrFail();
        $this->assertSame(Scope::where('name', $subject)->value('id'), $relation->subject_id);
        $this->assertSame(Scope::where('name', $object)->value('id'), $relation->object_id);
        $this->assertSame($classNumber, $relation->class_number);
        $this->assertSame('20', $relation->call_number);
        $this->assertNull($relation->parent_class);
        $this->assertSame(Relation::where('name', $reverse)->value('id'), $relation->reverse_id);
        $this->assertSame('schema', $relation->source_vocabulary);
        $this->assertSame($term, $relation->source_term);
        $this->assertStringContainsString("https://schema.org/{$term}", $relation->note);
        // 同步寫的 usedBy 跟它同一個類號
        $this->assertSame('12', Relation::where('name', 'usedBy')->value('class_number'));
    }

    #[TestDox('重跑不會多出述詞，反向配對仍然互指')]
    public function test_rerun_keeps_one_row_per_predicate(): void
    {
        $this->seed();
        $this->seed();

        foreach (self::NAMES as $name) {
            $relation = Relation::withTrashed()->where('name', $name)->get();
            $this->assertCount(1, $relation, $name);
            $this->assertSame($relation[0]->id, Relation::find($relation[0]->reverse_id)->reverse_id, $name);
        }
    }

    #[TestDox('部署 seed 把這一對補進已有資料的資料庫，原有述詞的 id 不變')]
    public function test_deploy_seed_adds_the_pair_to_an_existing_database(): void
    {
        $this->seed();
        Relation::whereIn('name', self::NAMES)->update(['reverse_id' => null]);
        Relation::whereIn('name', self::NAMES)->forceDelete();
        $existing = Relation::orderBy('id')->pluck('id', 'name')->all();

        $this->artisan('db:seed', ['--class' => 'ReferenceDataSeeder', '--force' => true])->assertSuccessful();

        $this->assertSame($existing, Relation::whereNotIn('name', self::NAMES)->orderBy('id')->pluck('id', 'name')->all());
        $this->assertSame(Relation::where('name', 'projectAbout')->value('id'), Relation::where('name', 'subjectOfProject')->value('reverse_id'));
    }
}
