<?php

namespace Tests\Feature;

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
 * `config/repo_sync.php` 的 `skip_technique_edges`：改作或建置產物的 repo 只同步成專案，
 * 語言、topic、套件都不寫技術邊，nomen 照記；其他 repo 不受影響。
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

    private function edgeCount(string $title): int
    {
        return DB::table('technique_implementation')
            ->where('implementation_id', Implementation::where('title', $title)->value('id'))
            ->count();
    }

    private function edgeCountByRepoId(int $gitRepoId): int
    {
        return DB::table('technique_implementation')
            ->where('implementation_id', Implementation::where('git_repo_id', $gitRepoId)->value('id'))
            ->count();
    }

    #[TestDox('預設清單是 jerryyehself 名下的 my-profile 與 laravel-migration-visualizer-pages')]
    public function test_default_list(): void
    {
        $this->assertSame(
            ['jerryyehself/my-profile', 'jerryyehself/laravel-migration-visualizer-pages'],
            config('repo_sync.skip_technique_edges'),
        );
    }

    #[DataProvider('listedRepos')]
    #[TestDox('清單裡的 repo 照常同步成專案，但語言、topic、套件都不建技術邊、也不建技術')]
    public function test_listed_repo_gets_no_technique_edges(string $title): void
    {
        $techniquesBefore = Technique::count();

        $this->sync([$this->repo($title, 111)]);

        $this->assertSame(1, Implementation::where('title', $title)->count());
        $this->assertSame(0, $this->edgeCount($title));
        $this->assertSame(0, DB::table('technique_implementation')->count());
        $this->assertSame($techniquesBefore, Technique::count());
    }

    /** @return iterable<string, array{string}> */
    public static function listedRepos(): iterable
    {
        yield 'my-profile' => ['my-profile'];
        yield 'laravel-migration-visualizer-pages' => ['laravel-migration-visualizer-pages'];
        yield '大小寫不同' => ['My-Profile'];
    }

    #[TestDox('擁有者的大小寫不同也算（GitHub 的帳號名稱不分大小寫）')]
    public function test_owner_case_is_ignored(): void
    {
        $this->sync([$this->repo('my-profile', 111, 'JerryYehSelf')]);

        $this->assertSame(0, $this->edgeCountByRepoId(111));
    }

    #[TestDox('別的擁有者名下同名的 repo 不被略過：照常建邊、更新 last_seen_at')]
    public function test_same_name_repo_of_another_owner_is_synced_normally(): void
    {
        $this->sync([
            $this->repo('my-profile', 111),
            $this->repo('my-profile', 222, 'someone-else'),
        ]);

        $this->assertSame(2, Implementation::where('title', 'my-profile')->count());
        $this->assertSame(0, $this->edgeCountByRepoId(111));
        $this->assertSame(
            ['Alpine.js', 'Laravel', 'PHP', 'Vue'],
            Implementation::where('git_repo_id', 222)->firstOrFail()->techniques()->pluck('title')->sort()->values()->all(),
        );

        // 第二次同步：已存在的邊要更新 last_seen_at
        Carbon::setTestNow('2026-11-18 03:00:00');
        $this->sync([$this->repo('my-profile', 222, 'someone-else')]);

        $seen = DB::table('technique_implementation')
            ->where('implementation_id', Implementation::where('git_repo_id', 222)->value('id'))
            ->pluck('last_seen_at')
            ->map(fn ($value) => Carbon::parse($value)->format('Y-m-d H:i:s'))
            ->unique()
            ->all();
        $this->assertSame(['2026-11-18 03:00:00'], $seen);
    }

    #[DataProvider('unconfirmedOwners')]
    #[TestDox('擁有者讀不到、或只是長得像清單裡的擁有者，都不略過')]
    public function test_unconfirmed_owner_is_not_skipped(mixed $owner): void
    {
        $repo = $this->repo('my-profile', 111);
        $repo['owner'] = $owner;

        $this->sync([$repo]);

        $this->assertGreaterThan(0, $this->edgeCountByRepoId(111));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unconfirmedOwners(): iterable
    {
        yield 'null' => [null];
        yield '空字串' => [''];
        yield '不是字串' => [['login' => 'jerryyehself']];
        yield '擁有者只共用前綴' => ['jerryyehself2'];
        yield '擁有者只共用後綴' => ['not-jerryyehself'];
    }

    #[TestDox('清單項目只寫名稱（沒有擁有者）時不比對到任何 repo')]
    public function test_bare_name_entry_matches_nothing(): void
    {
        config(['repo_sync.skip_technique_edges' => ['my-profile']]);

        $this->sync([$this->repo('my-profile', 111)]);

        $this->assertGreaterThan(0, $this->edgeCountByRepoId(111));
    }

    #[TestDox('清單裡的 repo 的語言、topic、套件字串照記進 nomens')]
    public function test_listed_repo_still_records_nomens(): void
    {
        $this->sync([$this->repo('my-profile', 111)]);

        $project = Implementation::where('title', 'my-profile')->firstOrFail();
        $sources = Nomen::where('context_type', Nomen::CONTEXT_IMPLEMENTATION)
            ->where('context_id', $project->id)
            ->orderBy('source')
            ->pluck('source')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(
            [Nomen::SOURCE_GITHUB_LANGUAGE, Nomen::SOURCE_GITHUB_TOPIC, Nomen::SOURCE_PACKAGE],
            $sources,
        );
    }

    #[TestDox('已經連上的舊邊不刪、不更新 last_seen_at，也不因為同步多出別的邊')]
    public function test_existing_edge_is_left_alone(): void
    {
        $project = Implementation::factory()->create([
            'git_repo_id' => 111,
            'title' => 'my-profile',
            'type' => Scope::where('name', 'project')->value('id'),
        ]);
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

    #[TestDox('清單外的 repo 照舊建邊，排在清單內的 repo 後面也不被中斷')]
    public function test_other_repos_are_unaffected(): void
    {
        $this->sync([
            $this->repo('my-profile', 111),
            $this->repo('some-other-repo', 222),
        ]);

        $this->assertSame(0, $this->edgeCount('my-profile'));
        $this->assertGreaterThan(0, $this->edgeCount('some-other-repo'));
        $this->assertSame(
            ['Alpine.js', 'Laravel', 'PHP', 'Vue'],
            Implementation::where('title', 'some-other-repo')->firstOrFail()->techniques()->pluck('title')->sort()->values()->all(),
        );
    }

    #[TestDox('只共用前綴或後綴的名稱不算清單裡的 repo')]
    public function test_near_miss_names_are_not_skipped(): void
    {
        foreach (['my-profile-2', 'old-my-profile', 'my-profile-fork', 'laravel-migration-visualizer'] as $i => $title) {
            $this->sync([$this->repo($title, 300 + $i)]);

            $this->assertGreaterThan(0, $this->edgeCount($title), $title);
        }
    }

    #[DataProvider('emptyConfigs')]
    #[TestDox('清單是空的或讀不到時，沒有任何 repo 被略過')]
    public function test_empty_or_missing_config_skips_nothing(mixed $value): void
    {
        config(['repo_sync.skip_technique_edges' => $value]);

        $this->sync([$this->repo('my-profile', 111)]);

        $this->assertGreaterThan(0, $this->edgeCount('my-profile'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function emptyConfigs(): iterable
    {
        yield '空陣列' => [[]];
        yield 'null' => [null];
    }

    #[TestDox('清單可以改：加進清單的 repo 就不建邊')]
    public function test_list_is_configurable(): void
    {
        config(['repo_sync.skip_technique_edges' => ['jerryyehself/some-other-repo']]);

        $this->sync([$this->repo('some-other-repo', 222), $this->repo('my-profile', 111)]);

        $this->assertSame(0, $this->edgeCount('some-other-repo'));
        $this->assertGreaterThan(0, $this->edgeCount('my-profile'));
    }
}
