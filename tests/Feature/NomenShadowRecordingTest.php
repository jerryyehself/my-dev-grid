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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * 影子模式的寫入：同步與文章匯入照舊寫邊，另外把讀到的字串記進 `nomens`。
 *
 * 解析規則（哪個字串對到哪個節點）在 `NomenResolverTest`；這裡守的是「記了什麼、記幾列、
 * 出錯時不影響原本的同步與匯入」。
 */
class NomenShadowRecordingTest extends TestCase
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

    /**
     * @param  array<string, int>  $bytes
     * @param  list<string>  $topics
     */
    private function sync(array $bytes, array $topics, int $id = 111, bool $withBytes = true): void
    {
        (new SaveReposDataService(collect([[
            'id' => $id,
            'git_repo_id' => $id,
            'title' => "demo-{$id}",
            'html_url' => "https://github.com/example/demo-{$id}",
            'description' => null,
            'topics' => $topics,
            'languages' => array_keys($bytes),
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => 'example',
        ] + ($withBytes ? ['language_bytes' => $bytes] : [])])))->save_repos_data();
    }

    private function importArticle(string $techniques, string $projects): int
    {
        $this->configureArticleSource();
        $this->fakeGitHub();
        $content = self::article('post-a', '文章', extra: ['about_techniques' => $techniques, 'about_projects' => $projects]);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        return $this->postJson('/api/article-sources/import', [
            'commit_sha' => $commit,
            'items' => [['key' => 'post-a', 'expected_blob_sha' => self::blobSha($content), 'action' => 'create']],
        ])->assertOk()->json('data.0.documentation_id');
    }

    private function relationId(string $name): int
    {
        return Relation::where('name', $name)->value('id');
    }

    #[TestDox('同步把語言與 topic 各記成一列：語言帶位元組數並主張 usedBy，topic 不主張述詞')]
    public function test_sync_records_languages_and_topics_as_separate_rows(): void
    {
        $this->sync(['PHP' => 50000], ['laravel']);

        $project = Implementation::where('git_repo_id', 111)->firstOrFail();
        $rows = Nomen::orderBy('source')->get();

        $this->assertSame([
            ['github_language', 'PHP', 'php', 'linguist', 'implementation', $project->id, $this->relationId('usedBy'), 50000],
            ['github_topic', 'laravel', 'laravel', 'github-topics', 'implementation', $project->id, null, null],
        ], $rows->map(fn (Nomen $r) => [$r->source, $r->string, $r->normalized, $r->scheme, $r->context_type, $r->context_id, $r->predicate_id, $r->weight])->all());
        $this->assertSame('2026-11-13 03:17:00', $rows[0]->first_seen_at->format('Y-m-d H:i:s'));
    }

    #[TestDox('同一個 repo 的語言 PHP 與 topic php 是兩列，不會互相蓋掉')]
    public function test_same_string_from_language_and_topic_in_one_repo_is_two_rows(): void
    {
        $this->sync(['PHP' => 50000], ['php']);

        $this->assertSame(['github_language', 'github_topic'], Nomen::where('normalized', 'php')->orderBy('source')->pluck('source')->all());
    }

    #[TestDox('重複同步不多出列：更新最後看到時間與位元組數，第一次的寫法與時間不變')]
    public function test_resync_updates_the_existing_row(): void
    {
        $this->sync(['PHP' => 50000], ['Laravel']);
        Carbon::setTestNow('2026-11-14 03:17:00');
        $this->sync(['PHP' => 60000], ['laravel']);

        $this->assertSame(2, Nomen::count());
        $php = Nomen::where('normalized', 'php')->firstOrFail();
        $this->assertSame(60000, $php->weight);
        $this->assertSame('2026-11-13 03:17:00', $php->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-14 03:17:00', $php->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('Laravel', Nomen::where('normalized', 'laravel')->value('string'));
    }

    #[TestDox('被同步略過的 Hack 照樣記一列，但不建技術')]
    public function test_ignored_language_is_still_recorded(): void
    {
        $this->sync(['PHP' => 50000, 'Hack' => 2000], []);

        $this->assertTrue(Nomen::where('normalized', 'hack')->where('resolution', Nomen::UNRESOLVED)->exists());
        $this->assertFalse(Technique::whereRaw('lower(title) = ?', ['hack'])->exists());
    }

    #[TestDox('舊快照沒有位元組數時，權重是空值')]
    public function test_missing_language_bytes_leave_the_weight_empty(): void
    {
        $this->sync(['PHP' => 50000], [], withBytes: false);

        $this->assertNull(Nomen::where('normalized', 'php')->value('weight'));
    }

    #[TestDox('文章兩個欄位寫了同一個名稱時是兩列，各自帶自己的述詞')]
    public function test_same_name_in_both_front_matter_fields_is_two_rows(): void
    {
        Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id'), 'title' => 'Laravel']);
        $id = $this->importArticle('[Laravel]', '[Laravel]');

        $rows = Nomen::where('context_type', 'documentation')->where('context_id', $id)->orderBy('source')->get();
        $this->assertSame([
            ['front_matter.about_projects', $this->relationId('aboutProject')],
            ['front_matter.about_techniques', $this->relationId('about')],
        ], $rows->map(fn (Nomen $r) => [$r->source, $r->predicate_id])->all());
        $this->assertSame(['implementation', null], [$rows[0]->res_type, $rows[1]->res_type]);
    }

    #[TestDox('重新匯入同一篇文章不多出列，兩個欄位的列都還在')]
    public function test_reimport_keeps_one_row_per_field(): void
    {
        $this->configureArticleSource();
        $this->fakeGitHub();
        $this->actingAsOwner();
        $first = self::article('post-a', '文章', extra: ['about_techniques' => '[Laravel]', 'about_projects' => '[Laravel]']);
        $commit = $this->publishSourceFiles(['a.md' => $first]);
        $id = $this->postJson('/api/article-sources/import', ['commit_sha' => $commit, 'items' => [
            ['key' => 'post-a', 'expected_blob_sha' => self::blobSha($first), 'action' => 'create'],
        ]])->assertOk()->json('data.0.documentation_id');

        Carbon::setTestNow('2026-11-14 03:17:00');
        $second = self::article('post-a', '文章', "改過的內文。\n", ['about_techniques' => '[Laravel]', 'about_projects' => '[Laravel]']);
        $commit = $this->publishSourceFiles(['a.md' => $second]);
        $this->postJson('/api/article-sources/import', ['commit_sha' => $commit, 'items' => [
            ['key' => 'post-a', 'expected_blob_sha' => self::blobSha($second), 'action' => 'update'],
        ]])->assertOk();

        $this->assertSame(
            ['front_matter.about_projects', 'front_matter.about_techniques'],
            Nomen::where('context_id', $id)->where('context_type', 'documentation')->orderBy('source')->pluck('source')->all(),
        );
    }

    #[TestDox('連結文章（只寫來源欄位）不記任何名稱')]
    public function test_link_action_records_nothing(): void
    {
        $documentation = Documentation::factory()->create(['type' => Scope::where('name', 'post')->value('id')]);
        $this->configureArticleSource();
        $this->fakeGitHub();
        $this->actingAsOwner();
        $content = self::article('post-a', '文章', extra: ['about_techniques' => '[Laravel]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);

        $this->postJson('/api/article-sources/import', ['commit_sha' => $commit, 'items' => [
            ['key' => 'post-a', 'expected_blob_sha' => self::blobSha($content), 'action' => 'link', 'documentation_id' => $documentation->id],
        ]])->assertOk();

        $this->assertSame(0, Nomen::count());
    }

    #[TestDox('nomens 寫不進去時，同步照常寫邊，只記一則警告')]
    public function test_a_failing_shadow_write_does_not_stop_the_sync(): void
    {
        $warnings = $this->captureWarnings();
        Schema::drop('nomens');

        $this->sync(['PHP' => 50000], ['laravel']);

        $project = Implementation::where('git_repo_id', 111)->firstOrFail();
        $this->assertEqualsCanonicalizing(['PHP', 'laravel'], $project->techniques()->pluck('title')->all());
        $this->assertNotEmpty($warnings());
        $this->assertStringContainsString('nomens 影子寫入失敗', $warnings()[0]);
    }

    #[TestDox('nomens 寫不進去時，文章照常匯入並建立連結，只記一則警告')]
    public function test_a_failing_shadow_write_does_not_stop_the_article_import(): void
    {
        $laravel = Technique::factory()->create(['type' => Scope::where('name', 'framework')->value('id'), 'title' => 'Laravel']);
        $warnings = $this->captureWarnings();
        Schema::drop('nomens');

        $id = $this->importArticle('[Laravel]', '[]');

        $this->assertSame([$laravel->id], DB::table('documentation_technique')->where('documentation_id', $id)->pluck('technique_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('文章', Documentation::findOrFail($id)->title);
        $this->assertNotEmpty($warnings());
    }

    /** @return callable(): list<string> 目前為止記到的 warning 訊息 */
    private function captureWarnings(): callable
    {
        $messages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$messages) {
            if ($event->level === 'warning') {
                $messages[] = $event->message;
            }
        });

        return function () use (&$messages) {
            return $messages;
        };
    }
}
