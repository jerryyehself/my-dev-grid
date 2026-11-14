<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 「文章講到某個技術／專案」的兩對述詞（about／subjectOf、aboutProject／projectSubjectOf），
 * 借自 schema.org。述詞的受詞只能指到一個頂層分類，所以技術和專案各一對。
 */
class AboutRelationsSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function predicates(): iterable
    {
        // [名稱, 反向名稱, 主詞端分類, 受詞端分類, 詞條, 說明裡要有的網址]
        yield 'about（文章 → 技術）' => ['about', 'subjectOf', 'Documentation', 'Technique', 'about', 'https://schema.org/about'];
        yield 'subjectOf（技術 → 文章）' => ['subjectOf', 'about', 'Technique', 'Documentation', 'subjectOf', 'https://schema.org/subjectOf'];
        yield 'aboutProject（文章 → 專案）' => ['aboutProject', 'projectSubjectOf', 'Documentation', 'Implementation', 'about', 'https://schema.org/about'];
        yield 'projectSubjectOf（專案 → 文章）' => ['projectSubjectOf', 'aboutProject', 'Implementation', 'Documentation', 'subjectOf', 'https://schema.org/subjectOf'];
    }

    #[DataProvider('predicates')]
    #[TestDox('about 這幾條述詞的主詞、受詞、反向與外部詞彙出處都正確')]
    public function test_predicate_definition(string $name, string $reverse, string $subject, string $object, string $term, string $url): void
    {
        $this->seed();

        $relation = Relation::where('name', $name)->firstOrFail();
        $this->assertSame(Scope::where('name', $subject)->value('id'), $relation->subject_id);
        $this->assertSame(Scope::where('name', $object)->value('id'), $relation->object_id);
        $this->assertSame(Relation::where('name', $reverse)->value('id'), $relation->reverse_id);
        $this->assertSame('schema', $relation->source_vocabulary);
        $this->assertSame($term, $relation->source_term);
        $this->assertStringContainsString($url, $relation->note);
    }

    #[TestDox('類號跟 specs／documents 同一組、子類號不同，不接在它們底下當子關係')]
    public function test_predicates_are_siblings_of_specs_and_documents(): void
    {
        $this->seed();

        $expected = ['about' => '01', 'subjectOf' => '10', 'aboutProject' => '02', 'projectSubjectOf' => '20'];
        foreach ($expected as $name => $classNumber) {
            $relation = Relation::where('name', $name)->firstOrFail();
            $this->assertSame($classNumber, $relation->class_number, $name);
            $this->assertSame('10', $relation->call_number, $name);
            $this->assertNull($relation->parent_class, $name);
        }
        $this->assertSame('01', Relation::where('name', 'specs')->value('class_number'));
        $this->assertSame('02', Relation::where('name', 'documents')->value('class_number'));
    }

    #[TestDox('編輯器：文章用 about 連技術、用 aboutProject 連專案可以，換成另一個或反向的都 422')]
    public function test_editor_accepts_each_predicate_only_on_its_own_object_class(): void
    {
        $this->seed();
        $this->actingAsOwner();
        $post = Scope::where('name', 'post')->value('id');
        $technique = Technique::factory()->create(['type' => Scope::where('name', 'framework')->value('id')]);
        $project = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id')]);
        $id = fn (string $name) => Relation::where('name', $name)->value('id');

        $this->postJson('/api/documentations', [
            'type' => $post, 'title' => 'ok',
            'techniques' => [['id' => $technique->id, 'relation_id' => $id('about')]],
            'implementations' => [['id' => $project->id, 'relation_id' => $id('aboutProject')]],
        ])->assertCreated();

        foreach ([
            ['techniques.0.relation_id', ['techniques' => [['id' => $technique->id, 'relation_id' => $id('aboutProject')]]]],
            ['techniques.0.relation_id', ['techniques' => [['id' => $technique->id, 'relation_id' => $id('subjectOf')]]]],
            ['implementations.0.relation_id', ['implementations' => [['id' => $project->id, 'relation_id' => $id('about')]]]],
            ['implementations.0.relation_id', ['implementations' => [['id' => $project->id, 'relation_id' => $id('projectSubjectOf')]]]],
        ] as [$field, $payload]) {
            $this->postJson('/api/documentations', ['type' => $post, 'title' => 'wrong'] + $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    #[TestDox('部署 seed 把四條述詞補進已有資料的資料庫，原有述詞的 id 不變')]
    public function test_deploy_seed_adds_the_predicates_to_an_existing_database(): void
    {
        $this->seed();
        $names = ['about', 'subjectOf', 'aboutProject', 'projectSubjectOf'];
        // 模擬加進 seeder 之前就存在的資料庫：拿掉這四條（先解開配對，否則外鍵擋著）
        Relation::whereIn('name', $names)->update(['reverse_id' => null]);
        Relation::whereIn('name', $names)->forceDelete();
        $existing = Relation::orderBy('id')->pluck('id', 'name')->all();

        $this->artisan('db:seed', ['--class' => 'ReferenceDataSeeder', '--force' => true])->assertSuccessful();

        $this->assertSame($existing, Relation::whereNotIn('name', $names)->orderBy('id')->pluck('id', 'name')->all());
        $this->assertSame(4, Relation::whereIn('name', $names)->count());
        $this->assertSame(Relation::where('name', 'subjectOf')->value('id'), Relation::where('name', 'about')->value('reverse_id'));
        $this->assertSame(Relation::where('name', 'projectSubjectOf')->value('id'), Relation::where('name', 'aboutProject')->value('reverse_id'));
    }
}
