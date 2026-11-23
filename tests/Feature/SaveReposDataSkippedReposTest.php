<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Nomen;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 不寫技術邊的 repo 只看資料庫：專案有 isBasisFor 文件連結（改作自外部 repo）才略過，repo 名稱不再決定。
 * 以前的 `config/repo_sync.php` 清單已經拿掉，my-profile 改用 isBasisFor 連結
 * （2026_11_23_000000_link_my_profile_to_origin_and_downgrade_topic_only_edges.php）。
 */
class SaveReposDataSkippedReposTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-17 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> 一個 repo，語言、topic、套件各有一項會被對照到的技術 */
    private function repo(string $title, int $id, string $owner = 'jerryyehself'): array
    {
        return [
            'id' => $id,
            'git_repo_id' => $id,
            'title' => $title,
            'html_url' => "https://github.com/{$owner}/{$title}",
            'description' => null,
            'topics' => ['vue'],
            'languages' => ['PHP'],
            'language_bytes' => ['PHP' => 1000],
            'packages' => ['npm' => ['alpinejs'], 'composer' => ['laravel/framework']],
            'package_versions' => [],
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => $owner,
        ];
    }

    /** @param  list<array<string, mixed>>  $repos */
    private function sync(array $repos): void
    {
        (new SaveReposDataService(collect($repos)))->save_repos_data();
    }

    private function edgeCount(int $gitRepoId): int
    {
        return DB::table('technique_implementation')
            ->where('implementation_id', Implementation::where('git_repo_id', $gitRepoId)->value('id'))
            ->count();
    }

    private function derivedProject(int $gitRepoId, string $title = 'my-profile'): Implementation
    {
        $project = Implementation::factory()->create([
            'git_repo_id' => $gitRepoId,
            'title' => $title,
            'type' => Scope::where('name', 'project')->value('id'),
        ]);
        Documentation::factory()->create(['type' => Scope::where('name', 'repository')->value('id')])
            ->implementations()->attach($project->id, ['relation_id' => Relation::where('name', 'isBasisFor')->value('id')]);

        return $project;
    }

    #[TestDox('設定檔清單已經拿掉')]
    public function test_config_list_is_gone(): void
    {
        $this->assertFileDoesNotExist(config_path('repo_sync.php'));
        $this->assertNull(config('repo_sync.skip_technique_edges'));
    }

    #[DataProvider('formerlyListedRepos')]
    #[TestDox('以前在清單裡的 repo，沒有 isBasisFor 連結就照常寫技術邊（名稱不再決定略過）')]
    public function test_formerly_listed_repo_without_link_is_synced(string $title): void
    {
        $this->sync([$this->repo($title, 111)]);

        $this->assertSame(
            ['Alpine.js', 'Laravel', 'PHP', 'Vue'],
            Implementation::where('git_repo_id', 111)->firstOrFail()->techniques()->pluck('title')->sort()->values()->all(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function formerlyListedRepos(): iterable
    {
        yield 'my-profile' => ['my-profile'];
        yield 'laravel-migration-visualizer-pages' => ['laravel-migration-visualizer-pages'];
    }

    #[TestDox('改作的 repo 不建技術、不寫邊，語言、topic、套件字串照記進 nomens')]
    public function test_derived_repo_still_records_nomens(): void
    {
        $project = $this->derivedProject(111);
        $techniquesBefore = Technique::count();

        $this->sync([$this->repo('my-profile', 111)]);

        $this->assertSame(0, $this->edgeCount(111));
        $this->assertSame($techniquesBefore, Technique::count());
        $this->assertSame(
            [Nomen::SOURCE_GITHUB_LANGUAGE, Nomen::SOURCE_GITHUB_TOPIC, Nomen::SOURCE_PACKAGE],
            Nomen::where('context_type', Nomen::CONTEXT_IMPLEMENTATION)
                ->where('context_id', $project->id)
                ->orderBy('source')
                ->pluck('source')
                ->unique()
                ->values()
                ->all(),
        );
    }

    #[TestDox('改作的 repo 已經連上的舊邊不刪、不更新 last_seen_at，也不因為同步多出別的邊')]
    public function test_existing_edge_of_derived_repo_is_left_alone(): void
    {
        $project = $this->derivedProject(111);
        $technique = Technique::factory()->create(['title' => 'PHP', 'type' => Scope::where('name', 'language')->value('id')]);
        $old = Carbon::parse('2026-09-01 00:00:00');
        DB::table('technique_implementation')->insert([
            'implementation_id' => $project->id,
            'technique_id' => $technique->id,
            'relation_id' => Relation::where('name', 'usedBy')->value('id'),
            'last_seen_at' => $old,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        $this->sync([$this->repo('my-profile', 111)]);

        $rows = DB::table('technique_implementation')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($old->format('Y-m-d H:i:s'), Carbon::parse($rows[0]->last_seen_at)->format('Y-m-d H:i:s'));
    }

    #[TestDox('改作的 repo 排在前面，後面的 repo 照常寫邊')]
    public function test_other_repos_are_unaffected(): void
    {
        $this->derivedProject(111);

        $this->sync([
            $this->repo('my-profile', 111),
            $this->repo('some-other-repo', 222),
        ]);

        $this->assertSame(0, $this->edgeCount(111));
        $this->assertSame(
            ['Alpine.js', 'Laravel', 'PHP', 'Vue'],
            Implementation::where('git_repo_id', 222)->firstOrFail()->techniques()->pluck('title')->sort()->values()->all(),
        );
    }
}
