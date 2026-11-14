<?php

namespace Tests\Feature;

use App\Models\Nomen;
use App\Models\Technique;
use App\Services\NomenSwitchPreview;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * `nomens:preview-switch`：列出改用 nomens 的解析結果寫邊後，專案與文章的邊會怎麼變。唯讀。
 */
class PreviewNomenSwitchTest extends TestCase
{
    use FakesArticleSourceRepo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-13 03:17:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function syncFixture(): void
    {
        (new SaveReposDataService(collect([
            $this->repo(111, 'shop', ['PHP' => 50000, 'Hack' => 2000], ['laravel', 'php']),
            $this->repo(222, 'front', ['TypeScript' => 90000, 'Vue' => 80000], ['vue3']),
        ])))->save_repos_data();
    }

    /**
     * @param  array<string, list<string>>|null  $packages  null 是沒有 `packages` 欄位的舊快照
     * @return array<string, mixed>
     */
    private function repo(int $id, string $name, array $bytes, array $topics, ?array $packages = null): array
    {
        return [
            'id' => $id, 'git_repo_id' => $id, 'title' => $name, 'html_url' => "https://github.com/example/{$name}",
            'description' => null, 'topics' => $topics, 'languages' => array_keys($bytes), 'language_bytes' => $bytes,
            'created_at' => '2026-06-15T00:00:00Z', 'archived' => false, 'owner' => 'example',
        ] + ($packages === null ? [] : ['packages' => $packages]);
    }

    private function importArticles(): void
    {
        $this->configureArticleSource();
        $this->fakeGitHub();
        $a = self::article('post-a', '文章甲', extra: ['about_techniques' => '[vuejs, Laravel, 漸進增強]', 'about_projects' => '[shop]']);
        $b = self::article('post-b', '文章乙', extra: ['about_techniques' => '["漸進 增強"]']);
        $commit = $this->publishSourceFiles(['a.md' => $a, 'b.md' => $b]);
        $this->actingAsOwner();
        $this->postJson('/api/article-sources/import', ['commit_sha' => $commit, 'items' => [
            ['key' => 'post-a', 'expected_blob_sha' => self::blobSha($a), 'action' => 'create'],
            ['key' => 'post-b', 'expected_blob_sha' => self::blobSha($b), 'action' => 'create'],
        ]])->assertOk();
    }

    #[TestDox('還沒有紀錄時提示先同步，正常結束')]
    public function test_empty_table_prints_a_hint(): void
    {
        $this->artisan('nomens:preview-switch')
            ->expectsOutputToContain('nomens 還沒有任何紀錄')
            ->assertSuccessful();
    }

    #[TestDox('只有語言撐得起的邊保留；只有 topic 的邊不再更新；文章新增規則對上的連結')]
    public function test_report_lists_what_would_change(): void
    {
        $this->syncFixture();
        $this->importArticles();

        $report = (new NomenSwitchPreview)->build();

        $projects = collect($report['projects'])->keyBy('title');
        $this->assertSame(['PHP'], $projects['shop']['keep']);
        $this->assertSame(['laravel'], $projects['shop']['stop']);
        $this->assertSame([], $projects['shop']['add']);
        // Linguist 的 Vue 只能對到 language 分類，對不上框架 Vue；vue3 topic 沒有佐證，Vue 3 的邊不再更新
        $this->assertSame(['TypeScript'], $projects['front']['keep']);
        $this->assertSame(['Vue 3'], $projects['front']['stop']);

        $articles = collect($report['articles'])->keyBy('title');
        $this->assertSame(['laravel', 'shop'], $articles['文章甲']['linked']);
        $this->assertSame(['Vue'], $articles['文章甲']['add']);

        $pending = collect($report['pending'])->map(fn ($p) => "{$p['source']}|{$p['string']}|{$p['resolution']}|{$p['contexts']}")->all();
        $this->assertContains('github_language|Hack|unresolved|1', $pending);
        $this->assertContains('github_language|Vue|unresolved|1', $pending);
        $this->assertContains('front_matter.about_techniques|漸進增強|unresolved|2', $pending);

        $this->assertSame([['string' => '漸進增強', 'contexts' => 2]], $report['concepts']);
    }

    #[TestDox('套件清單撐得起的邊保留；這次清單裡沒有的套件，邊不再更新')]
    public function test_package_evidence_keeps_edges(): void
    {
        Carbon::setTestNow('2026-11-12 03:17:00');
        (new SaveReposDataService(collect([
            $this->repo(111, 'shop', ['PHP' => 50000], [], ['composer' => ['laravel/framework', 'livewire/livewire']]),
        ])))->save_repos_data();
        Carbon::setTestNow('2026-11-13 03:17:00');
        (new SaveReposDataService(collect([
            $this->repo(111, 'shop', ['PHP' => 50000], ['laravel'], ['composer' => ['laravel/framework', 'phpunit/phpunit']]),
            // 只有套件、沒有語言與 topic 的 repo 也要列進報表
            $this->repo(333, 'widgets', [], [], ['npm' => ['vue', 'vite', 'eslint']]),
        ])))->save_repos_data();

        $projects = collect((new NomenSwitchPreview)->build()['projects'])->keyBy('title');

        // Livewire 只在上一次同步的清單裡；Laravel 這次同時有 topic 和套件，撐起邊的是套件
        $this->assertSame(['Laravel', 'PHP'], $projects['shop']['keep']);
        $this->assertSame(['Livewire'], $projects['shop']['stop']);
        $this->assertSame([], $projects['shop']['add']);
        $this->assertSame(['Vite', 'Vue'], $projects['widgets']['keep']);
        $this->assertSame([], $projects['widgets']['stop']);
    }

    #[TestDox('指令印出四段報表並正常結束，不寫任何資料')]
    public function test_command_prints_the_report_without_writing(): void
    {
        $this->syncFixture();
        $this->importArticles();
        $before = [Nomen::count(), DB::table('technique_implementation')->count(), DB::table('documentation_technique')->count(), Technique::count()];

        $this->artisan('nomens:preview-switch')
            ->expectsOutputToContain('== 專案的 usedBy 邊 ==')
            ->expectsOutputToContain('== 文章的 about 連結 ==')
            ->expectsOutputToContain('== 對不上或證據不夠的名稱 ==')
            ->expectsOutputToContain('== 會自動長出來的概念節點（出現在 2 篇以上文章） ==')
            ->assertSuccessful();

        $this->assertSame($before, [Nomen::count(), DB::table('technique_implementation')->count(), DB::table('documentation_technique')->count(), Technique::count()]);
    }

    #[TestDox('已軟刪除的技術不列進報表')]
    public function test_soft_deleted_techniques_are_left_out(): void
    {
        $this->syncFixture();
        Technique::where('title', 'laravel')->firstOrFail()->delete();

        $projects = collect((new NomenSwitchPreview)->build()['projects'])->keyBy('title');

        $this->assertSame(['PHP'], $projects['shop']['keep']);
        $this->assertSame([], $projects['shop']['stop']);
    }
}
