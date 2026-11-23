<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\NomenRecorder;
use App\Services\NomenResolver;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026_11_23_000000_link_my_profile_to_origin_and_downgrade_topic_only_edges：
 * my-profile 連到原作、清掉技術邊；名單上的例外維持使用（標為手動指定）或刪掉；只有 topic 證據的使用邊改成關於；
 * down() 照備份還原。
 *
 * 資料照正式資料的形狀重播：my-profile 有 React、tailwind、TypeScript、JavaScript、CSS、Dockerfile 六條使用邊，
 * GitHub Pages（建置產物）語言只有 HTML、JavaScript、CSS，topic 是 php、laravel。nomens 用 NomenRecorder 寫，
 * 跟每日同步記下的一樣。
 */
class LinkMyProfileToOriginMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_23_000000_link_my_profile_to_origin_and_downgrade_topic_only_edges.php';

    private const MY_PROFILE_URL = 'https://github.com/jerryyehself/my-profile';

    private const PAGES_URL = 'https://github.com/jerryyehself/laravel-migration-visualizer-pages';

    private const ORIGIN_URL = 'https://github.com/memochou1993/nyan-profile';

    private object $migration;

    private NomenRecorder $nomens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-23 03:17:00');
        $this->migration = require database_path(self::MIGRATION);
        // RefreshDatabase 已經對空資料庫跑過一次 up()（只建了備份表），先退掉再造舊資料
        $this->migration->down();
        $this->nomens = new NomenRecorder(new NomenResolver);
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
     * @return array<string, mixed>
     */
    private function repoData(int $id, string $url, array $languages, array $topics, array $packages = []): array
    {
        return [
            'id' => $id,
            'git_repo_id' => $id,
            'title' => basename($url),
            'html_url' => $url,
            'description' => null,
            'topics' => $topics,
            'languages' => $languages,
            'language_bytes' => array_fill_keys($languages, 5000),
            'packages' => $packages,
            'package_versions' => [],
            'created_at' => '2025-02-09T16:24:54Z',
            'archived' => false,
            'owner' => basename(dirname($url)),
        ];
    }

    private function project(array $repo): Implementation
    {
        return Implementation::factory()->create([
            'git_repo_id' => $repo['git_repo_id'],
            'title' => $repo['title'],
            'url' => $repo['html_url'],
            'type' => Scope::where('name', 'project')->value('id'),
        ]);
    }

    private function technique(string $title): int
    {
        $scope = match (mb_strtolower($title)) {
            'php', 'html', 'javascript', 'css', 'typescript', 'dockerfile', 'hack' => 'language',
            'laravel', 'react', 'vue' => 'framework',
            default => 'packagetool',
        };

        return Technique::whereRaw('lower(title) = ?', [mb_strtolower($title)])->value('id')
            ?? Technique::factory()->create(['title' => $title, 'version' => null, 'type' => Scope::where('name', $scope)->value('id')])->id;
    }

    /** 同步以前寫的使用邊 */
    private function usedBy(Implementation $project, string $technique, array $extra = []): int
    {
        $old = '2026-09-01 00:00:00';

        return DB::table('technique_implementation')->insertGetId([
            'implementation_id' => $project->id,
            'technique_id' => $this->technique($technique),
            'relation_id' => Relation::where('name', 'usedBy')->value('id'),
            'last_seen_at' => $old,
            'created_at' => $old,
            'updated_at' => $old,
        ] + $extra);
    }

    /** 每日同步記下的 nomens：寫一次再重算對應節點 */
    private function record(Implementation $project, array $repo, string $at = '2026-11-22 03:17:00'): void
    {
        $this->nomens->recordRepo($project, $repo, Carbon::parse($at));
        $this->nomens->resolve();
    }

    /** @return array<string, list<string>> 技術 title（小寫）=> 這一對所有列的述詞 */
    private function edges(Implementation $project): array
    {
        return DB::table('technique_implementation')
            ->join('techniques', 'techniques.id', '=', 'technique_implementation.technique_id')
            ->join('relations', 'relations.id', '=', 'technique_implementation.relation_id')
            ->where('technique_implementation.implementation_id', $project->id)
            ->orderBy('relations.name')
            ->get(['techniques.title', 'relations.name'])
            ->groupBy(fn (object $row) => mb_strtolower($row->title))
            ->map(fn ($rows) => $rows->pluck('name')->all())
            ->sortKeys()
            ->all();
    }

    private function myProfileRepo(): array
    {
        return $this->repoData(929886110, self::MY_PROFILE_URL, ['TypeScript', 'JavaScript', 'Dockerfile', 'CSS'], [], ['npm' => ['react', 'tailwindcss', 'typescript']]);
    }

    private function pagesRepo(): array
    {
        return $this->repoData(1001, self::PAGES_URL, ['HTML', 'JavaScript', 'CSS'], ['php', 'laravel']);
    }

    /** 正式資料的形狀：my-profile 六條、pages 五條使用邊，nomens 已經記過 */
    private function productionShape(): array
    {
        $myProfile = $this->project($this->myProfileRepo());
        foreach (['React', 'tailwind', 'TypeScript', 'JavaScript', 'CSS', 'Dockerfile'] as $title) {
            $this->usedBy($myProfile, $title);
        }
        $pages = $this->project($this->pagesRepo());
        foreach (['PHP', 'laravel', 'HTML', 'JavaScript', 'CSS'] as $title) {
            $this->usedBy($pages, $title);
        }
        $this->record($myProfile, $this->myProfileRepo());
        $this->record($pages, $this->pagesRepo());

        return [$myProfile, $pages];
    }

    private function snapshot(): array
    {
        return collect(['technique_implementation', 'documentations', 'documentation_implementation'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])
            ->all();
    }

    #[TestDox('my-profile：建原作的 Documentation（repository、已發布）與 isBasisFor 連結，六條技術邊全部刪掉')]
    public function test_my_profile_is_linked_to_its_origin_and_its_edges_are_cleared(): void
    {
        [$myProfile] = $this->productionShape();
        $this->assertCount(6, $this->edges($myProfile));

        $this->migration->up();

        $this->assertSame([], $this->edges($myProfile));
        $origin = DB::table('documentations')->where('url', self::ORIGIN_URL)->get();
        $this->assertCount(1, $origin);
        $this->assertSame('memochou1993/nyan-profile', $origin[0]->title);
        $this->assertSame(1, (int) $origin[0]->status);
        $this->assertSame((int) Scope::where('name', 'repository')->value('id'), (int) $origin[0]->type);
        $this->assertSame('my-profile 改作自這個 repo。', $origin[0]->body);
        $this->assertSame(
            [[(int) $origin[0]->id, $myProfile->id, (int) Relation::where('name', 'isBasisFor')->value('id')]],
            DB::table('documentation_implementation')->get()->map(fn ($r) => [(int) $r->documentation_id, (int) $r->implementation_id, (int) $r->relation_id])->all(),
        );
    }

    #[TestDox('pages：只有 topic 證據的 PHP、laravel 改成 subjectOfProject，語言統計裡的 HTML、JavaScript、CSS 不動')]
    public function test_topic_only_edges_of_pages_become_subject_of_project(): void
    {
        [, $pages] = $this->productionShape();

        $this->migration->up();

        $this->assertSame([
            'css' => ['usedBy'],
            'html' => ['usedBy'],
            'javascript' => ['usedBy'],
            'laravel' => ['subjectOfProject'],
            'php' => ['subjectOfProject'],
        ], $this->edges($pages));
    }

    #[TestDox('執行前後的筆數記成一筆 info log')]
    public function test_counts_before_and_after_are_logged(): void
    {
        $this->productionShape();
        Log::spy();

        $this->migration->up();

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) {
            return $context['before']['my_profile_edges'] === 6
                && $context['after']['my_profile_edges'] === 0
                && $context['before']['technique_implementation'] === 11
                && $context['after']['technique_implementation'] === 5
                && $context['before']['subject_of_project'] === 0
                && $context['after']['subject_of_project'] === 2
                && $context['my_profile']['edges_deleted'] === 6
                && $context['my_profile']['documentation_created'] === true
                && $context['my_profile']['link_created'] === true
                && $context['downgraded'] === ['laravel-migration-visualizer-pages → PHP', 'laravel-migration-visualizer-pages → laravel'];
        });
    }

    /**
     * @param  list<string>  $languages
     * @param  list<string>  $topics
     * @param  array<string, list<string>>  $packages
     */
    #[DataProvider('evidenceCases')]
    #[TestDox('依證據判斷：$_dataName')]
    public function test_downgrade_follows_the_same_evidence_rule_as_the_sync(string $technique, array $languages, array $topics, array $packages, string $expected): void
    {
        $repo = $this->repoData(2001, 'https://github.com/example/demo', $languages, $topics, $packages);
        $project = $this->project($repo);
        $this->usedBy($project, $technique);
        $this->record($project, $repo);

        $this->migration->up();

        $this->assertSame([mb_strtolower($technique) => [$expected]], $this->edges($project));
    }

    /** @return iterable<string, array{string, list<string>, list<string>, array<string, list<string>>, string}> */
    public static function evidenceCases(): iterable
    {
        yield '只有 topic → 關於' => ['laravel', ['HTML'], ['laravel'], [], 'subjectOfProject'];
        yield 'topic 經權威表換名（tailwindcss → tailwind）也算 topic 證據 → 關於' => ['tailwind', ['CSS'], ['tailwindcss'], [], 'subjectOfProject'];
        yield '被同步略過的語言（Hack）不算程式證據 → 關於' => ['Hack', ['Hack'], ['hack'], [], 'subjectOfProject'];
        yield 'topic 加套件 → 不動' => ['laravel', ['PHP'], ['laravel'], ['composer' => ['laravel/framework']], 'usedBy'];
        yield 'topic php 加語言 PHP（大小寫不同）→ 不動' => ['PHP', ['PHP'], ['php'], [], 'usedBy'];
        yield 'Linguist 的語言 Vue 算框架 Vue 的證據 → 不動' => ['Vue', ['Vue'], ['vue'], [], 'usedBy'];
        yield 'topic vue3、套件 vue：不看版本 → 不動' => ['Vue', ['JavaScript'], ['vue3'], ['npm' => ['vue']], 'usedBy'];
        yield 'topic 經權威表換名、套件也有 → 不動' => ['tailwind', ['CSS'], ['tailwindcss'], ['npm' => ['tailwindcss']], 'usedBy'];
        yield '沒有 topic 證據（沒有紀錄可判斷）→ 不動' => ['laravel', ['HTML'], [], [], 'usedBy'];
        yield '只共用前綴的 topic 不算同一個技術 → 不動' => ['laravel', ['HTML'], ['laravel-pages'], [], 'usedBy'];
        yield '有一筆套件對不到節點（分不出是誰的證據）→ 整個專案不動' => ['laravel', ['HTML'], ['laravel'], ['composer' => ['filament/filament']], 'usedBy'];
    }

    #[TestDox('以前同步看過的語言也算證據：最新一次沒讀到（讀取失敗）不會被誤降')]
    public function test_language_seen_in_an_earlier_sync_still_counts(): void
    {
        $repo = $this->repoData(2001, 'https://github.com/example/demo', ['PHP'], ['php']);
        $project = $this->project($repo);
        $this->usedBy($project, 'PHP');
        $this->record($project, $repo, '2026-11-20 03:17:00');
        $this->record($project, ['languages' => [], 'language_bytes' => []] + $repo, '2026-11-22 03:17:00');

        $this->migration->up();

        $this->assertSame(['php' => ['usedBy']], $this->edges($project));
    }

    #[TestDox('同一對有站主手動指定的列、或已經有 subjectOfProject 列、或邊本身手動指定過：都不動')]
    public function test_owner_set_or_already_about_pairs_are_left_alone(): void
    {
        [, $pages] = $this->productionShape();
        $manualAt = ['manually_set_at' => '2026-11-21 10:00:00'];
        DB::table('technique_implementation')->where('implementation_id', $pages->id)
            ->where('technique_id', $this->technique('PHP'))->update($manualAt);
        DB::table('technique_implementation')->insert([
            'implementation_id' => $pages->id,
            'technique_id' => $this->technique('laravel'),
            'relation_id' => Relation::where('name', 'subjectOfProject')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $other = $this->project($repo = $this->repoData(2002, 'https://github.com/example/other', ['HTML'], ['laravel']));
        $this->usedBy($other, 'laravel');
        DB::table('technique_implementation')->insert([
            'implementation_id' => $other->id,
            'technique_id' => $this->technique('laravel'),
            'relation_id' => Relation::where('name', 'assists')->value('id'),
            'manually_set_at' => '2026-11-21 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->record($other, $repo);
        $before = $this->snapshot();

        $this->migration->up();

        // 只少了 my-profile 的六條，其他一列都沒改
        $this->assertSame(
            collect($before['technique_implementation'])->reject(fn ($r) => (int) $r['implementation_id'] === Implementation::where('url', self::MY_PROFILE_URL)->value('id'))->values()->all(),
            $this->snapshot()['technique_implementation'],
        );
    }

    #[TestDox('已刪除的專案或技術的邊不動')]
    public function test_soft_deleted_projects_and_techniques_are_left_alone(): void
    {
        [, $pages] = $this->productionShape();
        $pages->delete();
        $other = $this->project($repo = $this->repoData(2002, 'https://github.com/example/other', ['HTML'], ['laravel', 'vite']));
        $this->usedBy($other, 'laravel');
        $this->usedBy($other, 'vite');
        $this->record($other, $repo);
        Technique::where('title', 'vite')->firstOrFail()->delete();

        $this->migration->up();

        $this->assertSame(['laravel' => ['subjectOfProject'], 'vite' => ['usedBy']], $this->edges($other));
        $this->assertSame(['usedBy'], array_unique(array_merge(...array_values($this->edges($pages)))));
    }

    #[DataProvider('notMyProfile')]
    #[TestDox('只認 my-profile 那一個網址、沒刪除的那一筆：$_dataName 不動')]
    public function test_only_the_exact_my_profile_url_is_touched(string $url, bool $softDeleted): void
    {
        $project = $this->project($repo = $this->repoData(3001, $url, ['TypeScript'], []));
        $this->usedBy($project, 'TypeScript');
        $this->record($project, $repo);
        if ($softDeleted) {
            $project->delete();
        }

        $this->migration->up();

        $this->assertSame(1, DB::table('technique_implementation')->where('implementation_id', $project->id)->count());
        $this->assertSame(0, DB::table('documentation_implementation')->count());
        $this->assertFalse(DB::table('documentations')->where('url', self::ORIGIN_URL)->exists());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function notMyProfile(): iterable
    {
        yield '只共用前綴（my-profile-2）' => ['https://github.com/jerryyehself/my-profile-2', false];
        yield '別人名下的同名 repo' => ['https://github.com/someone-else/my-profile', false];
        yield '結尾多一個斜線' => ['https://github.com/jerryyehself/my-profile/', false];
        yield '已軟刪除的 my-profile' => [self::MY_PROFILE_URL, true];
    }

    #[TestDox('已經有原作的 Documentation 與連結：沿用，不重複建；down() 也不刪它們')]
    public function test_existing_origin_and_link_are_reused_and_kept_on_down(): void
    {
        [$myProfile] = $this->productionShape();
        $documentationId = DB::table('documentations')->insertGetId([
            'type' => Scope::where('name', 'repository')->value('id'), 'title' => 'nyan-profile', 'url' => self::ORIGIN_URL,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('documentation_implementation')->insert([
            'documentation_id' => $documentationId, 'implementation_id' => $myProfile->id,
            'relation_id' => Relation::where('name', 'isBasisFor')->value('id'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->snapshot();

        $this->migration->up();

        $this->assertSame(1, DB::table('documentations')->where('url', self::ORIGIN_URL)->count());
        $this->assertSame(1, DB::table('documentation_implementation')->count());
        $this->assertSame([], $this->edges($myProfile));

        $this->migration->down();

        $this->assertSame($before, $this->snapshot());
    }

    #[TestDox('同網址的 Documentation 已軟刪除：另建一筆（刪掉的那筆不算原作節點）')]
    public function test_soft_deleted_origin_is_not_reused(): void
    {
        $this->productionShape();
        DB::table('documentations')->insert([
            'type' => Scope::where('name', 'repository')->value('id'), 'title' => 'nyan-profile', 'url' => self::ORIGIN_URL,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
        ]);

        $this->migration->up();

        $this->assertSame(1, DB::table('documentations')->where('url', self::ORIGIN_URL)->whereNull('deleted_at')->count());
        $this->assertSame(1, DB::table('documentation_implementation')->count());
    }

    #[TestDox('down() 照備份還原：邊、述詞、Documentation、連結都回到執行前，備份表刪掉')]
    public function test_down_restores_everything(): void
    {
        [$myProfile] = $this->productionShape();
        DB::table('technique_implementation')->where('implementation_id', $myProfile->id)->limit(1)->update(['manually_set_at' => '2026-11-21 10:00:00']);
        $before = $this->snapshot();

        $this->migration->up();
        $this->assertNotSame($before, $this->snapshot());

        $this->migration->down();

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::hasTable('sync_predicate_fix_backups'));
    }

    #[TestDox('跑兩次 up()（中間 down()）結果一樣')]
    public function test_up_down_up_gives_the_same_result(): void
    {
        $this->productionShape();

        $this->migration->up();
        $first = collect($this->snapshot()['technique_implementation'])->map(fn ($r) => [$r['implementation_id'], $r['technique_id'], $r['relation_id']])->all();
        $this->migration->down();
        $this->migration->up();

        $this->assertSame($first, collect($this->snapshot()['technique_implementation'])->map(fn ($r) => [$r['implementation_id'], $r['technique_id'], $r['relation_id']])->all());
    }

    #[TestDox('網址是 my-profile 的專案有兩筆：丟例外，什麼都不改')]
    public function test_two_my_profile_rows_abort(): void
    {
        $this->productionShape();
        $this->project($this->repoData(929886111, self::MY_PROFILE_URL, [], []));
        $before = $this->snapshot();

        $this->assertAborts();
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('missingReferenceData')]
    #[TestDox('缺預設資料（$_dataName）：丟例外，什麼都不改')]
    public function test_missing_reference_data_aborts(string $table, string $name): void
    {
        $this->productionShape();
        DB::table($table)->where('name', $name)->update(['deleted_at' => now()]);
        $before = $this->snapshot();

        $this->assertAborts();
        $this->assertSame($before, $this->snapshot());
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingReferenceData(): iterable
    {
        yield '述詞 isBasisFor' => ['relations', 'isBasisFor'];
        yield '分類 repository' => ['scopes', 'repository'];
        yield '述詞 subjectOfProject' => ['relations', 'subjectOfProject'];
    }

    private function assertAborts(): void
    {
        try {
            $this->migration->up();
            $this->fail('應該丟例外');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('這支 migration 不執行', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('sync_predicate_fix_backups'));
    }

    #[TestDox('跑完 migration 再同步：my-profile 沒有技術連線，圖上連到原作；pages 的 laravel、PHP 是「關於」')]
    public function test_sync_after_the_migration(): void
    {
        [$myProfile, $pages] = $this->productionShape();

        $this->migration->up();
        foreach (['2026-11-24 03:17:00', '2026-11-25 03:17:00'] as $day) {
            Carbon::setTestNow($day);
            (new SaveReposDataService(collect([$this->myProfileRepo(), $this->pagesRepo()])))->save_repos_data();
        }

        $this->assertSame([], $this->edges($myProfile));
        $this->assertSame([
            'css' => ['usedBy'],
            'html' => ['usedBy'],
            'javascript' => ['usedBy'],
            'laravel' => ['subjectOfProject'],
            'php' => ['subjectOfProject'],
        ], $this->edges($pages));

        $node = "implementation-{$myProfile->id}";
        $edges = collect($this->getJson('/api/graph')->assertOk()->json('edges'))
            ->filter(fn (array $e) => $e['source'] === $node || $e['target'] === $node);
        $this->assertSame(
            [['documentation-'.DB::table('documentations')->where('url', self::ORIGIN_URL)->value('id'), $node, 'isBasisFor']],
            $edges->map(fn (array $e) => [$e['source'], $e['target'], $e['predicate']])->values()->all(),
        );
    }

    /**
     * 上線前在正式資料庫跑的唯讀預覽列出 31 條會降級的邊（9 個專案）。這裡照預覽的「why」欄重建每個專案的
     * 語言、topic、套件證據與那 31 條邊，另外每個專案再放幾條有程式證據、本來就不動的使用邊。
     * my-dev-grid 的兩條 Vue 邊在預覽裡只看得到 title，這裡用 Vue 與 Vue 3 兩個節點重現（同一對不能有兩列同述詞）。
     *
     * @return array<string, array{languages: list<string>, topics: list<string>, packages: array<string, list<string>>, edges: list<string>, stays: list<string>}>
     */
    private static function previewShape(): array
    {
        $visualizerTopics = ['database', 'erd', 'laravel', 'migrations', 'php', 'schema', 'static-analysis', 'visualization'];

        return [
            'my-dev-grid' => [
                'languages' => ['Dockerfile', 'PHP', 'Shell'],
                'topics' => ['vue3', 'tailwind', 'laravel'],
                'packages' => ['composer' => ['dedoc/scramble', 'laravel/framework', 'laravel/sanctum', 'laravel/socialite']],
                'edges' => ['Vue', 'Vue 3', 'tailwind'],
                'stays' => ['PHP', 'laravel'],
            ],
            'Laravel-LearningLibrary' => [
                'languages' => ['Blade', 'PHP', 'Procfile'],
                'topics' => ['bootstrap5'],
                'packages' => ['composer' => ['laravel/framework', 'laravel/sanctum', 'rebing/graphql-laravel'], 'npm' => ['chart.js']],
                'edges' => ['Bootstrap'],
                'stays' => [],
            ],
            'gym-people-count-parser' => [
                'languages' => ['JavaScript'],
                'topics' => ['appscript', 'cheerio', 'linebot'],
                'packages' => [],
                'edges' => ['appscript', 'cheerio', 'linebot'],
                'stays' => ['JavaScript'],
            ],
            'api-demo' => [
                'languages' => [],
                'topics' => ['php'],
                'packages' => ['composer' => ['laravel/framework', 'laravel/sanctum']],
                'edges' => ['PHP'],
                'stays' => [],
            ],
            'laravel-demo' => [
                'languages' => [],
                'topics' => ['php'],
                'packages' => ['composer' => ['laravel/framework', 'laravel/sanctum'], 'npm' => ['bootstrap', 'vue']],
                'edges' => ['PHP'],
                'stays' => [],
            ],
            'leetcode-practice' => [
                'languages' => ['PHP'],
                'topics' => ['leetcode'],
                'packages' => [],
                'edges' => ['leetcode'],
                'stays' => [],
            ],
            'thesis' => [
                'languages' => ['Jupyter Notebook'],
                'topics' => ['graphviz', 'json', 'python', 'rdflib'],
                'packages' => [],
                'edges' => ['graphviz', 'json', 'Python', 'rdflib'],
                'stays' => [],
            ],
            'laravel-migration-visualizer' => [
                'languages' => ['CSS', 'HTML', 'JavaScript', 'Python', 'Shell', 'TypeScript'],
                'topics' => [...$visualizerTopics, 'react'],
                'packages' => ['npm' => ['php-parser', 'react', 'typescript', 'vite']],
                'edges' => ['database', 'erd', 'laravel', 'migrations', 'PHP', 'schema', 'static-analysis', 'visualization'],
                'stays' => ['React', 'TypeScript'],
            ],
            'laravel-migration-visualizer-pages' => [
                'languages' => ['HTML'],
                'topics' => [...$visualizerTopics, 'github-pages'],
                'packages' => [],
                'edges' => ['database', 'erd', 'github-pages', 'laravel', 'migrations', 'PHP', 'schema', 'static-analysis', 'visualization'],
                'stays' => ['HTML'],
            ],
        ];
    }

    /** 建 previewShape() 的專案、邊與 nomens；回傳 [專案 title => Implementation] */
    private function buildPreviewShape(): array
    {
        // 套件要對得到唯一的技術節點，nomens 才不會把整個專案標成不確定（正式環境這些節點都在）
        foreach (['Vue', 'Scramble', 'laravel', 'Sanctum', 'Socialite', 'GraphQL', 'chartjs', 'Bootstrap', 'php-parser', 'React', 'TypeScript', 'Vite'] as $title) {
            $this->technique($title);
        }
        $vue3 = Technique::factory()->create(['title' => 'Vue', 'version' => '3', 'type' => Scope::where('name', 'framework')->value('id')]);

        $projects = [];
        $id = 5000;
        foreach (self::previewShape() as $title => $shape) {
            $repo = $this->repoData(++$id, "https://github.com/example/{$title}", $shape['languages'], $shape['topics'], $shape['packages']);
            $projects[$title] = $project = $this->project($repo);
            foreach ([...$shape['edges'], ...$shape['stays']] as $technique) {
                if ($technique === 'Vue 3') {
                    DB::table('technique_implementation')->insert([
                        'implementation_id' => $project->id, 'technique_id' => $vue3->id, 'relation_id' => Relation::where('name', 'usedBy')->value('id'),
                        'last_seen_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
                    ]);
                } else {
                    $this->usedBy($project, $technique);
                }
            }
            $this->record($project, $repo);
        }

        return $projects;
    }

    /** @return array<string, string> `專案 → 技術（含版本）` => `述詞`，手動指定的後面加 ` (manual)` */
    private function allEdges(): array
    {
        return DB::table('technique_implementation')
            ->join('implementations', 'implementations.id', '=', 'technique_implementation.implementation_id')
            ->join('techniques', 'techniques.id', '=', 'technique_implementation.technique_id')
            ->join('relations', 'relations.id', '=', 'technique_implementation.relation_id')
            ->get(['implementations.title as project', 'techniques.title', 'techniques.version', 'relations.name', 'technique_implementation.manually_set_at'])
            ->mapWithKeys(fn (object $r) => [
                $r->project.' → '.Technique::labelFrom($r->title, $r->version) => $r->name.($r->manually_set_at === null ? '' : ' (manual)'),
            ])
            ->sortKeys()
            ->all();
    }

    #[TestDox('預覽的 31 條：9 條維持使用並標為手動指定、3 條刪掉、19 條降為關於，有程式證據的邊不動')]
    public function test_preview_list_of_31_edges(): void
    {
        $this->buildPreviewShape();
        $this->assertCount(31, $this->migration->topicOnlyEdges());
        Log::spy();

        $this->migration->up();

        $edges = $this->allEdges();
        $kept = [
            'Laravel-LearningLibrary → Bootstrap', 'api-demo → PHP', 'gym-people-count-parser → appscript',
            'gym-people-count-parser → cheerio', 'gym-people-count-parser → linebot', 'laravel-demo → PHP',
            'thesis → Python', 'thesis → graphviz', 'thesis → rdflib',
        ];
        $deleted = ['my-dev-grid → Vue', 'my-dev-grid → Vue 3', 'my-dev-grid → tailwind'];
        $stays = [
            'gym-people-count-parser → JavaScript', 'laravel-migration-visualizer → React', 'laravel-migration-visualizer → TypeScript',
            'laravel-migration-visualizer-pages → HTML', 'my-dev-grid → PHP', 'my-dev-grid → laravel',
        ];

        $this->assertSame(array_fill_keys($kept, 'usedBy (manual)'), array_intersect_key($edges, array_flip($kept)));
        $this->assertSame([], array_intersect_key($edges, array_flip($deleted)));
        $this->assertSame(array_fill_keys($stays, 'usedBy'), array_intersect_key($edges, array_flip($stays)));
        $downgraded = array_diff_key($edges, array_flip([...$kept, ...$stays]));
        $this->assertCount(19, $downgraded);
        $this->assertSame(['subjectOfProject'], array_values(array_unique($downgraded)));
        $this->assertSame(9 + 19 + 6, count($edges));
        $this->assertSame(['2026-11-23 03:17:00'], DB::table('technique_implementation')->whereNotNull('manually_set_at')->pluck('manually_set_at')->map(fn ($at) => Carbon::parse($at)->toDateTimeString())->unique()->values()->all());

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => count($context['kept_used_by']) === 9
            && $context['deleted'] === ['my-dev-grid → Vue', 'my-dev-grid → Vue', 'my-dev-grid → tailwind']
            && $context['exclusions_unmatched'] === []
            && count($context['downgraded']) === 19);
    }

    #[TestDox('備份表每一列記下動作與原因：KEEP 9 列 updated、DELETE 3 列 deleted（整列原值）、降級 19 列 updated')]
    public function test_every_action_is_recorded_with_its_reason(): void
    {
        $this->buildPreviewShape();
        $deleteIds = DB::table('technique_implementation')
            ->whereIn('implementation_id', Implementation::where('title', 'my-dev-grid')->pluck('id'))
            ->whereIn('technique_id', Technique::whereIn('title', ['Vue', 'tailwind'])->pluck('id'))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->migration->up();

        $backup = DB::table('sync_predicate_fix_backups')->orderBy('id')->get();
        $this->assertSame([
            'deleted|'.$this->migration::REASON_DELETE_EDGE => 3,
            'updated|'.$this->migration::REASON_KEEP_USED_BY => 9,
            'updated|'.$this->migration::REASON_TOPIC_ONLY => 19,
        ], $backup->countBy(fn (object $r) => "{$r->action}|{$r->reason}")->sortKeys()->all());

        // 刪掉的列整列存下來，原本的 id 與述詞都在
        $deletedRows = $backup->where('action', 'deleted');
        $this->assertSame($deleteIds, $deletedRows->pluck('row_id')->map(fn ($id) => (int) $id)->values()->all());
        $original = json_decode($deletedRows->first()->original, true);
        $this->assertSame($deleteIds[0], (int) $original['id']);
        $this->assertSame((int) Relation::where('name', 'usedBy')->value('id'), (int) $original['relation_id']);
        // 維持的列存的是改之前的值（manually_set_at 是 null）
        $kept = $backup->where('reason', $this->migration::REASON_KEEP_USED_BY)->first();
        $this->assertNull(json_decode($kept->original, true)['manually_set_at']);
    }

    #[TestDox('down() 還原 KEEP（manually_set_at 改回 null）與 DELETE（整列插回、id 不變），跟執行前一樣')]
    public function test_down_restores_kept_and_deleted_edges(): void
    {
        $this->buildPreviewShape();
        $before = $this->snapshot();

        $this->migration->up();
        // 先確認真的有 KEEP 與 DELETE 可以還原
        $this->assertSame(9, DB::table('technique_implementation')->whereNotNull('manually_set_at')->count());
        $this->assertSame(['PHP', 'laravel'], array_values(array_map(
            fn (string $k) => substr($k, strlen('my-dev-grid → ')),
            array_filter(array_keys($this->allEdges()), fn (string $k) => str_starts_with($k, 'my-dev-grid → ')),
        )));

        $this->migration->down();

        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::hasTable('sync_predicate_fix_backups'));
    }

    #[TestDox('名單上的專案或邊都不在：不丟例外，11 對全部記進 info log 的 exclusions_unmatched')]
    public function test_unmatched_pairs_are_logged_without_aborting(): void
    {
        $this->productionShape();
        Log::spy();

        $this->migration->up();

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context['exclusions_unmatched'] === [
            'my-dev-grid → Vue', 'my-dev-grid → tailwind',
            'api-demo → PHP', 'laravel-demo → PHP', 'thesis → Python', 'thesis → rdflib', 'thesis → graphviz',
            'gym-people-count-parser → appscript', 'gym-people-count-parser → cheerio', 'gym-people-count-parser → linebot',
            'Laravel-LearningLibrary → Bootstrap',
        ] && $context['kept_used_by'] === [] && $context['deleted'] === []);
    }

    /**
     * 只動 usedBy、沒有手動指定、專案與技術都沒刪除、專案 title 只對到一個的列；其他一律不當成名單上的邊。
     */
    #[TestDox('名單只認完全相同的一對：$_dataName')]
    #[DataProvider('nearMisses')]
    public function test_near_misses_are_not_treated_as_listed_pairs(string $case): void
    {
        $projects = $this->buildPreviewShape();
        $thesisPython = fn () => DB::table('technique_implementation')->where('implementation_id', $projects['thesis']->id)
            ->where('technique_id', $this->technique('Python'));
        $devGridTailwind = fn () => DB::table('technique_implementation')->where('implementation_id', $projects['my-dev-grid']->id)
            ->where('technique_id', $this->technique('tailwind'));
        $expectedUnmatched = [];
        $expected = [];

        switch ($case) {
            case 'prefix':
                // 名稱只共用前綴的專案（my-dev-grid-front、thesis-notes）不算
                $front = $this->project($repo = $this->repoData(7001, 'https://github.com/example/my-dev-grid-front', ['TypeScript'], ['tailwind']));
                $this->usedBy($front, 'tailwind');
                $this->record($front, $repo);
                $notes = $this->project($repo = $this->repoData(7002, 'https://github.com/example/thesis-notes', ['HTML'], ['python']));
                $this->usedBy($notes, 'Python');
                $this->record($notes, $repo);
                $expected = ['my-dev-grid-front → tailwind' => 'subjectOfProject', 'thesis-notes → Python' => 'subjectOfProject'];
                break;
            case 'already-manual':
                // 已經手動指定過的不再改，也不算配對到
                $thesisPython()->update(['manually_set_at' => '2026-11-21 10:00:00']);
                $devGridTailwind()->update(['manually_set_at' => '2026-11-21 10:00:00']);
                $expectedUnmatched = ['my-dev-grid → tailwind', 'thesis → Python'];
                $expected = ['my-dev-grid → tailwind' => 'usedBy (manual)', 'thesis → Python' => 'usedBy (manual)'];
                break;
            case 'not-usedBy':
                // 述詞已經不是 usedBy 的列不碰
                $thesisPython()->update(['relation_id' => Relation::where('name', 'assists')->value('id')]);
                $devGridTailwind()->update(['relation_id' => Relation::where('name', 'assists')->value('id')]);
                $expectedUnmatched = ['my-dev-grid → tailwind', 'thesis → Python'];
                $expected = ['my-dev-grid → tailwind' => 'assists', 'thesis → Python' => 'assists'];
                break;
            case 'soft-deleted':
                // 專案或技術已刪除：不刪、不標
                $projects['my-dev-grid']->delete();
                Technique::where('title', 'Python')->firstOrFail()->delete();
                $expectedUnmatched = ['my-dev-grid → Vue', 'my-dev-grid → tailwind', 'thesis → Python'];
                break;
            case 'ambiguous-title':
                // 同一個 title 有兩個沒刪除的專案：分不出是哪一個，兩對都跳過，照一般規則降級
                $twin = $this->project($repo = $this->repoData(7003, 'https://github.com/example-2/my-dev-grid', ['Go'], ['tailwind']));
                $this->usedBy($twin, 'tailwind');
                $this->record($twin, $repo);
                $expectedUnmatched = ['my-dev-grid → Vue', 'my-dev-grid → tailwind'];
                $expected = ['my-dev-grid → Vue' => 'subjectOfProject', 'my-dev-grid → Vue 3' => 'subjectOfProject', 'my-dev-grid → tailwind' => 'subjectOfProject'];
                break;
        }
        $before = $this->allEdges();
        Log::spy();

        $this->migration->up();

        $sorted = fn (array $pairs) => collect($pairs)->sort()->values()->all();
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $sorted($context['exclusions_unmatched']) === $sorted($expectedUnmatched));
        $after = $this->allEdges();
        foreach ($expected as $edge => $relation) {
            $this->assertSame($relation, $after[$edge] ?? null, $edge);
        }
        if ($case === 'soft-deleted') {
            // 刪掉的專案、技術上的邊（含 my-dev-grid 的三條 Vue／tailwind）一列都沒少、沒改
            $this->assertSame(
                array_filter($before, fn ($k) => str_starts_with($k, 'my-dev-grid → ') || $k === 'thesis → Python', ARRAY_FILTER_USE_KEY),
                array_filter($after, fn ($k) => str_starts_with($k, 'my-dev-grid → ') || $k === 'thesis → Python', ARRAY_FILTER_USE_KEY),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nearMisses(): iterable
    {
        yield '只共用前綴的專案 title（my-dev-grid-front、thesis-notes）照一般規則降級' => ['prefix'];
        yield '那一對已經手動指定過 → 不動，記為配對不到' => ['already-manual'];
        yield '那一對的述詞不是 usedBy → 不動，記為配對不到' => ['not-usedBy'];
        yield '專案或技術已軟刪除 → 不動，記為配對不到' => ['soft-deleted'];
        yield '同一個 title 有兩個專案 → 跳過那一對，照一般規則降級' => ['ambiguous-title'];
    }
}
