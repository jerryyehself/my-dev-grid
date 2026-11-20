<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 同步依證據決定技術邊的述詞：程式裡有證據（語言統計、套件清單）寫 usedBy，只有 topic 寫 subjectOfProject；
 * 只升不降；改作自外部 repo（isBasisFor）的專案不寫技術邊。
 */
class SaveReposDataEvidencePredicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-19 03:17:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<string>  $languages
     * @param  list<string>  $topics
     * @param  array<string, list<string>>  $packages
     * @param  array<string, array<string, string>>  $versions
     * @return array<string, mixed>
     */
    private function repo(array $languages, array $topics, array $packages = [], array $versions = [], int $id = 111): array
    {
        return [
            'id' => $id,
            'git_repo_id' => $id,
            'title' => "demo-{$id}",
            'html_url' => "https://github.com/example/demo-{$id}",
            'description' => null,
            'topics' => $topics,
            'languages' => $languages,
            'language_bytes' => array_fill_keys($languages, 1000),
            'packages' => $packages,
            'package_versions' => $versions,
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => 'example',
        ];
    }

    /** @param  list<array<string, mixed>>  $repos */
    private function sync(array $repos): void
    {
        (new SaveReposDataService(collect($repos)))->save_repos_data();
    }

    /** @return array<string, list<string>> 技術的標籤（小寫）=> 這一對所有列的述詞名稱 */
    private function edges(int $gitRepoId = 111): array
    {
        return DB::table('technique_implementation')
            ->join('techniques', 'techniques.id', '=', 'technique_implementation.technique_id')
            ->join('relations', 'relations.id', '=', 'technique_implementation.relation_id')
            ->where('technique_implementation.implementation_id', Implementation::where('git_repo_id', $gitRepoId)->value('id'))
            ->orderBy('relations.name')
            ->get(['techniques.title', 'techniques.version', 'relations.name'])
            ->groupBy(fn (object $row) => mb_strtolower(trim("{$row->title} {$row->version}")))
            ->map(fn ($rows) => $rows->pluck('name')->all())
            ->sortKeys()
            ->all();
    }

    #[TestDox('只有 topic 證據（GitHub Pages 的形狀）：PHP、laravel 寫 subjectOfProject，語言統計裡的 HTML、JavaScript、CSS 寫 usedBy')]
    public function test_topic_only_techniques_become_subject_of_project(): void
    {
        $this->sync([$this->repo(['HTML', 'JavaScript', 'CSS'], ['php', 'laravel'])]);

        $this->assertSame([
            'css' => ['usedBy'],
            'html' => ['usedBy'],
            'javascript' => ['usedBy'],
            'laravel' => ['subjectOfProject'],
            'php' => ['subjectOfProject'],
        ], $this->edges());
    }

    #[TestDox('topic 和套件都有同一個技術：只有一列 usedBy')]
    public function test_topic_backed_by_a_package_is_used_by(): void
    {
        $this->sync([$this->repo(['PHP'], ['laravel'], ['composer' => ['laravel/framework']])]);

        $this->assertSame(['laravel' => ['usedBy'], 'php' => ['usedBy']], $this->edges());
    }

    #[TestDox('topic vue、套件 vue@^3：比的是不分版本的名稱，算有證據，不出現「關於」')]
    public function test_versioned_package_counts_as_evidence_for_the_unversioned_topic(): void
    {
        $this->sync([$this->repo([], ['vue'], ['npm' => ['vue']], ['npm' => ['vue' => '^3.4.0']])]);

        $this->assertSame(['vue 3' => ['usedBy']], $this->edges());
        $this->assertSame(0, DB::table('technique_implementation')->where('relation_id', Relation::where('name', 'subjectOfProject')->value('id'))->count());
    }

    #[TestDox('原本是 subjectOfProject，這次多了套件證據：同一列升成 usedBy，仍然只有一列')]
    public function test_subject_of_project_is_upgraded_when_code_evidence_appears(): void
    {
        $this->sync([$this->repo(['PHP'], ['laravel'])]);
        $this->assertSame(['laravel' => ['subjectOfProject'], 'php' => ['usedBy']], $this->edges());
        $rowId = DB::table('technique_implementation')->where('relation_id', Relation::where('name', 'subjectOfProject')->value('id'))->value('id');

        Carbon::setTestNow('2026-11-20 03:17:00');
        $this->sync([$this->repo(['PHP'], ['laravel'], ['composer' => ['laravel/framework']])]);

        $this->assertSame(['laravel' => ['usedBy'], 'php' => ['usedBy']], $this->edges());
        $row = DB::table('technique_implementation')->where('id', $rowId)->first();
        $this->assertSame((int) Relation::where('name', 'usedBy')->value('id'), (int) $row->relation_id);
        $this->assertSame('2026-11-20 03:17:00', Carbon::parse($row->last_seen_at)->format('Y-m-d H:i:s'));
    }

    #[TestDox('原本是 usedBy，這次只剩 topic 證據（模擬語言 API 讀取失敗）：不降級，只更新 last_seen_at')]
    public function test_used_by_is_not_downgraded_when_code_evidence_disappears(): void
    {
        $this->sync([$this->repo(['PHP'], ['php'])]);
        $this->assertSame(['php' => ['usedBy']], $this->edges());

        // GitService 讀不到語言時回空陣列
        Carbon::setTestNow('2026-11-20 03:17:00');
        $this->sync([$this->repo([], ['php'])]);

        $this->assertSame(['php' => ['usedBy']], $this->edges());
        $this->assertSame('2026-11-20 03:17:00', Carbon::parse(DB::table('technique_implementation')->value('last_seen_at'))->format('Y-m-d H:i:s'));
    }

    #[TestDox('專案有 isBasisFor 連結（改作自外部 repo）：不寫技術邊、不建技術，專案本身照常同步')]
    public function test_project_adapted_from_an_external_repository_gets_no_technique_edges(): void
    {
        $project = $this->existingProject(111);
        $this->linkDocumentation($project, 'isBasisFor');
        $techniquesBefore = DB::table('techniques')->count();

        $this->sync([$this->repo(['TypeScript'], ['vue'], ['npm' => ['vue']])]);

        $this->assertSame([], $this->edges());
        $this->assertSame($techniquesBefore, DB::table('techniques')->count());
        $this->assertSame('demo-111', $project->fresh()->title);
    }

    #[TestDox('isBasisFor 連到的文件已刪除：不算改作，照常同步技術邊')]
    public function test_soft_deleted_basis_documentation_does_not_skip(): void
    {
        $project = $this->existingProject(111);
        $this->linkDocumentation($project, 'isBasisFor')->delete();

        $this->sync([$this->repo(['TypeScript'], [])]);

        $this->assertSame(['typescript' => ['usedBy']], $this->edges());
    }

    #[TestDox('建置產物（isBasedOnProject）不算改作：照常同步技術邊；只影響有連結的那個專案')]
    public function test_build_output_project_is_synced_normally(): void
    {
        $output = $this->existingProject(111);
        $source = $this->existingProject(222);
        DB::table('entity_relations')->insert([
            'entity_type' => 'implementation',
            'subject_id' => $output->id,
            'object_id' => $source->id,
            'relation_id' => Relation::where('name', 'isBasedOnProject')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->linkDocumentation($this->existingProject(333), 'isBasisFor');

        $this->sync([
            $this->repo(['HTML'], ['laravel'], id: 111),
            $this->repo(['PHP'], [], id: 222),
            $this->repo(['PHP'], [], id: 333),
        ]);

        $this->assertSame(['html' => ['usedBy'], 'laravel' => ['subjectOfProject']], $this->edges(111));
        $this->assertSame(['php' => ['usedBy']], $this->edges(222));
        $this->assertSame([], $this->edges(333));
    }

    private function existingProject(int $gitRepoId): Implementation
    {
        return Implementation::factory()->create([
            'git_repo_id' => $gitRepoId,
            'type' => Scope::where('name', 'project')->value('id'),
        ]);
    }

    private function linkDocumentation(Implementation $project, string $relation): Documentation
    {
        $documentation = Documentation::factory()->create(['type' => Scope::where('name', 'repository')->value('id')]);
        $documentation->implementations()->attach($project->id, ['relation_id' => Relation::where('name', $relation)->value('id')]);

        return $documentation;
    }
}
