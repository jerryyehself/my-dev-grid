<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * `POST /api/article-sources/import`：依清單的選擇匯入、更新或連結文章。
 */
class ArticleSourceImportTest extends TestCase
{
    use CapturesSecurityEvents;
    use FakesArticleSourceRepo;
    use RefreshDatabase;

    /** 來源欄位全名：回應裡出現任何一個就是洩漏。 */
    private const SOURCE_COLUMNS = ['source_repo', 'source_key', 'source_path', 'source_blob_sha', 'source_commit_sha', 'source_imported_at', 'source_body_hash'];

    private Scope $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Scope::factory()->create(['name' => 'post']);
        $this->configureArticleSource();
        $this->fakeGitHub();
    }

    #[TestDox('匯入新文章：建成草稿，去掉 front matter 與 H1，記下來源')]
    public function test_create_imports_a_new_article_as_a_draft(): void
    {
        $content = self::article('first-post', '第一篇文章', "## 小節\n\n內文。\n", ['creation_date' => '2026-09-22']);
        $commit = $this->publishSourceFiles(['posts/first.md' => $content]);
        $user = $this->actingAsOwner();
        $this->captureLogs();

        $response = $this->import([$this->item('first-post', $content, 'create')], $commit)->assertOk();

        $id = $response->json('data.0.documentation_id');
        $response->assertJsonPath('commit_sha', $commit)
            ->assertJsonPath('data.0.action', 'create')
            ->assertJsonPath('data.0.published', false)
            ->assertJsonPath('data.0.title', '第一篇文章');
        $documentation = Documentation::findOrFail($id);
        $this->assertSame('第一篇文章', $documentation->title);
        $this->assertSame("## 小節\n\n內文。\n", $documentation->body);
        $this->assertSame(Documentation::STATUS_DRAFT, (int) $documentation->status);
        $this->assertSame($this->post->id, (int) $documentation->type);
        $this->assertStringStartsWith('2026-09-22', (string) $documentation->creation_date);
        $this->assertSame($this->sourceRepo, $documentation->source_repo);
        $this->assertSame('first-post', $documentation->source_key);
        $this->assertSame('posts/first.md', $documentation->source_path);
        $this->assertSame(self::blobSha($content), $documentation->source_blob_sha);
        $this->assertSame($commit, $documentation->source_commit_sha);
        $this->assertNotNull($documentation->source_imported_at);
        $this->assertSame(hash('sha256', "## 小節\n\n內文。\n"), $documentation->source_body_hash);

        $this->assertImportEvent('import', $user->id, $id, 'first-post', self::blobSha($content), $commit);
        $this->assertNotLogged('內文。');
        $this->assertNotLogged('posts/first.md');
    }

    #[TestDox('匯入後再列清單，那一篇變成 unchanged')]
    public function test_imported_article_is_unchanged_in_the_next_listing(): void
    {
        $content = self::article('first-post', '第一篇文章');
        $commit = $this->publishSourceFiles(['first.md' => $content]);
        $this->actingAsOwner();

        $id = $this->import([$this->item('first-post', $content, 'create')], $commit)->assertOk()->json('data.0.documentation_id');

        $this->getJson('/api/article-sources')->assertOk()
            ->assertJsonPath('items.0.status', 'unchanged')
            ->assertJsonPath('items.0.documentation_id', $id);
    }

    #[TestDox('更新已發布的文章：內容換成新版，維持已發布')]
    public function test_update_keeps_a_published_article_published(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $this->actingAsOwner();

        $this->import([$this->item('the-post', $content, 'update')], $commit)
            ->assertOk()
            ->assertJsonPath('data.0.documentation_id', $documentation->id)
            ->assertJsonPath('data.0.published', true);

        $documentation->refresh();
        $this->assertSame(Documentation::STATUS_PUBLISHED, (int) $documentation->status);
        $this->assertSame("新版內文。\n", $documentation->body);
        $this->assertSame('新版標題', $documentation->title);
        $this->assertSame(self::blobSha($content), $documentation->source_blob_sha);
        $this->assertSame(hash('sha256', "新版內文。\n"), $documentation->source_body_hash);
    }

    #[TestDox('更新草稿：內容換成新版，維持草稿')]
    public function test_update_keeps_a_draft_a_draft(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_DRAFT);
        $this->actingAsOwner();

        $this->import([$this->item('the-post', $content, 'update')], $commit)->assertOk()->assertJsonPath('data.0.published', false);

        $this->assertSame(Documentation::STATUS_DRAFT, (int) $documentation->refresh()->status);
        $this->assertSame("新版內文。\n", $documentation->body);
    }

    #[TestDox('站上改過內文時，沒帶 overwrite_site_edits 回 409，帶了才覆蓋')]
    public function test_conflict_requires_overwrite_site_edits(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $documentation->forceFill(['body' => "站上改過。\n"])->save();
        $this->actingAsOwner();

        $this->import([$this->item('the-post', $content, 'update')], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict')
            ->assertJsonStructure(['errors' => ['items.0.overwrite_site_edits']]);
        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => false])], $commit)->assertConflict();
        $this->assertSame("站上改過。\n", $documentation->refresh()->body);

        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => true])], $commit)->assertOk();
        $this->assertSame("新版內文。\n", $documentation->refresh()->body);
    }

    #[TestDox('連結既有文章：只寫來源欄位，標題與內文都不動')]
    public function test_link_only_writes_source_columns(): void
    {
        $content = self::article('legacy', '既有文章', "來源的版本。\n");
        $commit = $this->publishSourceFiles(['legacy.md' => $content]);
        $documentation = Documentation::factory()->create([
            'type' => $this->post->id, 'title' => '既有文章', 'body' => "站上的版本。\n", 'status' => Documentation::STATUS_PUBLISHED,
        ]);
        $before = $documentation->refresh()->only(['title', 'body', 'status', 'type', 'url', 'note', 'creation_date']);
        $user = $this->actingAsOwner();
        $this->getJson('/api/article-sources')->assertJsonPath('items.0.status', 'possible_match');
        $contentRequests = count($this->githubRequests('/contents/'));
        $this->captureLogs();

        $this->import([$this->item('legacy', $content, 'link', ['documentation_id' => $documentation->id])], $commit)
            ->assertOk()
            ->assertJsonPath('data.0.documentation_id', $documentation->id);

        $documentation->refresh();
        $this->assertSame($before, $documentation->only(array_keys($before)));
        $this->assertSame('legacy', $documentation->source_key);
        $this->assertSame(self::blobSha($content), $documentation->source_blob_sha);
        $this->assertSame(hash('sha256', "站上的版本。\n"), $documentation->source_body_hash);
        // 連結不用下載內容
        $this->assertCount($contentRequests, $this->githubRequests('/contents/'));
        $this->assertImportEvent('link', $user->id, $documentation->id, 'legacy', self::blobSha($content), $commit);

        $this->travel(61)->seconds();
        $this->getJson('/api/article-sources')->assertJsonPath('items.0.status', 'unchanged');
    }

    #[TestDox('slug 改名後，可以把新 slug 連回原本那篇文章')]
    public function test_link_can_reattach_an_article_whose_slug_was_renamed(): void
    {
        $content = self::article('new-slug', '改過 slug 的文章');
        $commit = $this->publishSourceFiles(['post.md' => $content]);
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'title' => '改過 slug 的文章', 'body' => "內文第一段。\n"]);
        $documentation->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'old-slug', 'source_path' => 'post.md', 'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => hash('sha256', "內文第一段。\n")])->save();
        $this->actingAsOwner();

        $statuses = collect($this->getJson('/api/article-sources')->json('items'))->pluck('status', 'key')->all();
        $this->assertSame(['new-slug' => 'new', 'old-slug' => 'removed'], $statuses);

        $this->import([$this->item('new-slug', $content, 'link', ['documentation_id' => $documentation->id])], $commit)->assertOk();
        $this->assertSame('new-slug', $documentation->refresh()->source_key);
    }

    #[TestDox('連到已經連結其他來源檔案的文章回 409')]
    public function test_link_to_an_article_linked_to_a_present_file_is_a_conflict(): void
    {
        $taken = self::article('taken', '已連結');
        $content = self::article('another', '另一篇');
        $commit = $this->publishSourceFiles(['taken.md' => $taken, 'another.md' => $content]);
        $documentation = Documentation::factory()->create(['type' => $this->post->id]);
        $documentation->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'taken', 'source_path' => 'taken.md', 'source_blob_sha' => self::blobSha($taken), 'source_body_hash' => hash('sha256', '')])->save();
        $this->actingAsOwner();

        $this->import([$this->item('another', $content, 'link', ['documentation_id' => $documentation->id])], $commit)
            ->assertConflict()
            ->assertJsonStructure(['errors' => ['items.0.documentation_id']]);
        $this->assertSame('taken', $documentation->refresh()->source_key);
    }

    #[TestDox('連到不存在或已刪除的文章回 422')]
    public function test_link_to_a_missing_or_deleted_article_is_rejected(): void
    {
        $content = self::article('legacy', '既有文章');
        $commit = $this->publishSourceFiles(['legacy.md' => $content]);
        $deleted = Documentation::factory()->create(['type' => $this->post->id]);
        $deleted->delete();
        $this->actingAsOwner();

        foreach ([$deleted->id, $deleted->id + 1000] as $id) {
            $this->import([$this->item('legacy', $content, 'link', ['documentation_id' => $id])], $commit)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['items.0.documentation_id']);
        }
        $this->assertNull(Documentation::withTrashed()->find($deleted->id)->source_key);
        $this->assertTrue(Documentation::withTrashed()->find($deleted->id)->trashed());
    }

    #[TestDox('站上已刪除的文章不能再匯入，也不會復活')]
    public function test_site_deleted_article_is_never_resurrected(): void
    {
        $content = self::article('gone', '刪掉的文章', "新版。\n");
        $commit = $this->publishSourceFiles(['gone.md' => $content]);
        $deleted = Documentation::factory()->create(['type' => $this->post->id, 'body' => "舊版。\n"]);
        $deleted->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'gone', 'source_path' => 'gone.md', 'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => hash('sha256', "舊版。\n")])->save();
        $deleted->delete();
        $this->actingAsOwner();
        $this->getJson('/api/article-sources')->assertJsonPath('items.0.status', 'site_deleted');

        foreach (['create', 'update', 'link'] as $action) {
            $extra = $action === 'link' ? ['documentation_id' => $deleted->id] : [];
            $this->import([$this->item('gone', $content, $action, $extra)], $commit)->assertConflict();
        }

        $this->assertSame(1, Documentation::withTrashed()->count());
        $row = Documentation::withTrashed()->findOrFail($deleted->id);
        $this->assertTrue($row->trashed());
        $this->assertSame("舊版。\n", $row->body);
    }

    #[TestDox('expected_blob_sha 跟這個版本的檔案不同時回 409，什麼都不寫')]
    public function test_blob_mismatch_returns_409_and_writes_nothing(): void
    {
        $content = self::article('first-post', '第一篇文章');
        $commit = $this->publishSourceFiles(['first.md' => $content]);
        $this->actingAsOwner();

        $this->import([['key' => 'first-post', 'expected_blob_sha' => str_repeat('a', 40), 'action' => 'create']], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'stale')
            ->assertJsonStructure(['errors' => ['items.0.expected_blob_sha']]);
        $this->import([['key' => 'not-in-repo', 'expected_blob_sha' => self::blobSha($content), 'action' => 'create']], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'stale');

        $this->assertSame(0, Documentation::count());
    }

    #[TestDox('用舊 commit 匯入時拿的是那個版本的內容')]
    public function test_import_reads_the_listed_commit_not_the_newest(): void
    {
        $old = self::article('first-post', '舊標題', "舊內文。\n");
        $oldCommit = $this->publishSourceFiles(['first.md' => $old]);
        $this->publishSourceFiles(['first.md' => self::article('first-post', '新標題', "新內文。\n")]);
        $this->actingAsOwner();

        $id = $this->import([$this->item('first-post', $old, 'create')], $oldCommit)->assertOk()->json('data.0.documentation_id');

        $this->assertSame('舊標題', Documentation::findOrFail($id)->title);
        $this->assertNotEmpty(array_filter($this->githubRequests('/contents/'), fn (Request $request) => str_contains($request->url(), 'ref='.$oldCommit)));
    }

    #[TestDox('GitHub 找不到 commit_sha 時回 409')]
    public function test_unknown_commit_returns_409(): void
    {
        $content = self::article('first-post', '第一篇文章');
        $this->publishSourceFiles(['first.md' => $content]);
        $this->actingAsOwner();

        $this->import([$this->item('first-post', $content, 'create')], str_repeat('f', 40))
            ->assertConflict()
            ->assertJsonPath('reason', 'stale')
            ->assertJsonStructure(['errors' => ['commit_sha']]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function actionsThatDoNotFitTheStatus(): iterable
    {
        yield '新文章不能 update' => ['new', 'update'];
        yield '沒變的不能 create' => ['unchanged', 'create'];
        yield '沒變的不能 update' => ['unchanged', 'update'];
        yield '有更新的不能 create' => ['changed', 'create'];
        yield '有更新的不能 link' => ['changed', 'link'];
    }

    #[DataProvider('actionsThatDoNotFitTheStatus')]
    #[TestDox('動作跟目前狀態不符時回 409')]
    public function test_action_must_fit_the_current_status(string $status, string $action): void
    {
        $content = self::article('the-post', '標題');
        $commit = $this->publishSourceFiles(['post.md' => $content]);
        $other = Documentation::factory()->create(['type' => $this->post->id]);
        if ($status !== 'new') {
            $documentation = Documentation::factory()->create(['type' => $this->post->id, 'body' => "內文第一段。\n"]);
            $documentation->forceFill([
                'source_repo' => $this->sourceRepo, 'source_key' => 'the-post', 'source_path' => 'post.md',
                'source_blob_sha' => $status === 'unchanged' ? self::blobSha($content) : str_repeat('1', 40),
                'source_body_hash' => hash('sha256', "內文第一段。\n"),
            ])->save();
        }
        $this->actingAsOwner();
        $count = Documentation::count();

        $extra = $action === 'link' ? ['documentation_id' => $other->id] : [];
        $this->import([$this->item('the-post', $content, $action, $extra)], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict')
            ->assertJsonStructure(['errors' => ['items.0.action']]);

        $this->assertSame($count, Documentation::count());
        $this->assertNull($other->refresh()->source_key);
    }

    #[TestDox('整批裡有一項不行時，其他項也不寫入')]
    public function test_one_bad_item_rolls_back_the_whole_batch(): void
    {
        $good = self::article('good', '可以匯入');
        [$documentation, $changed] = $this->changedArticle(Documentation::STATUS_PUBLISHED, extraFiles: ['good.md' => $good]);
        $documentation->forceFill(['body' => "站上改過。\n"])->save();
        $commit = $this->headCommit;
        $this->actingAsOwner();
        $this->captureLogs();

        $this->import([
            $this->item('good', $good, 'create'),
            $this->item('the-post', $changed, 'update'),
        ], $commit)->assertConflict();

        $this->assertSame(1, Documentation::count());
        $this->assertNoSecurityEvent('authz_admin');
        $this->assertSame("站上改過。\n", $documentation->refresh()->body);
    }

    #[TestDox('寫到一半失敗（第二項找不到 post 分類）時，第一項已寫的更新會整批回滾')]
    public function test_failure_on_a_later_write_rolls_back_the_earlier_write(): void
    {
        $newPost = self::article('new-post', '新文章');
        [$documentation, $changed] = $this->changedArticle(Documentation::STATUS_PUBLISHED, extraFiles: ['new.md' => $newPost]);
        $commit = $this->headCommit;
        // 軟刪除 post 分類：所有事前檢查都會過，要到第二項 create 查分類時才失敗，那時第一項 update 已經寫進資料庫
        $this->post->delete();
        $before = $documentation->refresh()->only(['title', 'body', 'status', 'source_key', 'source_path', 'source_blob_sha', 'source_commit_sha', 'source_imported_at', 'source_body_hash']);
        $this->actingAsOwner();

        $this->import([
            $this->item('the-post', $changed, 'update'),
            $this->item('new-post', $newPost, 'create'),
        ], $commit)
            ->assertStatus(503)
            ->assertJsonPath('reason', 'post_scope_missing');

        $this->assertSame($before, $documentation->refresh()->only(array_keys($before)));
        $this->assertSame("舊版內文。\n", $documentation->body);
        $this->assertSame(str_repeat('1', 40), $documentation->source_blob_sha);
        $this->assertSame(1, Documentation::withTrashed()->count());
    }

    #[TestDox('同一批兩項連到同一篇文章時，請求格式就擋下回 422，什麼都不寫')]
    public function test_two_links_to_the_same_article_in_one_batch_are_rejected(): void
    {
        $a = self::article('first-link', '甲');
        $b = self::article('second-link', '乙');
        $commit = $this->publishSourceFiles(['a.md' => $a, 'b.md' => $b]);
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'body' => "站上的版本。\n"]);
        $this->actingAsOwner();

        $this->import([
            $this->item('first-link', $a, 'link', ['documentation_id' => $documentation->id]),
            $this->item('second-link', $b, 'link', ['documentation_id' => $documentation->id]),
        ], $commit)->assertUnprocessable()->assertJsonValidationErrors(['items.0.documentation_id']);

        $this->assertNull($documentation->refresh()->source_key);
        Http::assertNothingSent();
    }

    #[TestDox('站上已刪除、來源檔案也不在了的文章，清單上不會出現')]
    public function test_site_deleted_article_whose_file_is_gone_is_not_listed(): void
    {
        $other = self::article('other', '還在的文章');
        $this->publishSourceFiles(['other.md' => $other]);
        $deleted = Documentation::factory()->create(['type' => $this->post->id]);
        $deleted->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'gone', 'source_path' => 'gone.md', 'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => hash('sha256', '')])->save();
        $deleted->delete();
        $this->actingAsOwner();

        $items = $this->getJson('/api/article-sources')->assertOk()->json('items');

        $this->assertSame([['other', 'new']], array_map(fn (array $item) => [$item['key'], $item['status']], $items));
    }

    #[TestDox('已連結的檔案改成不能匯入（沒有 slug）時，列成 invalid，不重複列成 removed，也不能匯入')]
    public function test_linked_file_that_becomes_invalid_is_listed_once_as_invalid(): void
    {
        [$documentation] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $broken = self::article(null, '沒有 slug');
        $commit = $this->publishSourceFiles(['post.md' => $broken]);
        $this->actingAsOwner();

        $items = $this->getJson('/api/article-sources')->assertOk()->json('items');

        $this->assertCount(1, $items);
        $this->assertSame('invalid', $items[0]['status']);
        $this->assertSame('post.md', $items[0]['path']);
        $this->assertSame($documentation->id, $items[0]['documentation_id']);
        $this->import([$this->item('the-post', $broken, 'update')], $commit)->assertConflict()->assertJsonPath('reason', 'stale');
        $this->assertSame("舊版內文。\n", $documentation->refresh()->body);
    }

    #[TestDox('slug 重複的檔案不能匯入')]
    public function test_duplicate_slug_cannot_be_imported(): void
    {
        $a = self::article('same', '第一篇');
        $commit = $this->publishSourceFiles(['a.md' => $a, 'b.md' => self::article('same', '第二篇')]);
        $this->actingAsOwner();

        $this->import([$this->item('same', $a, 'create')], $commit)->assertConflict()->assertJsonPath('reason', 'stale');
        $this->assertSame(0, Documentation::count());
    }

    #[TestDox('找不到 post 分類時回 503，不寫入')]
    public function test_missing_post_scope_returns_503(): void
    {
        $this->post->forceDelete();
        $content = self::article('first-post', '第一篇文章');
        $commit = $this->publishSourceFiles(['first.md' => $content]);
        $this->actingAsOwner();

        $this->import([$this->item('first-post', $content, 'create')], $commit)
            ->assertStatus(503)
            ->assertJsonPath('reason', 'post_scope_missing');
        $this->assertSame(0, Documentation::count());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        $sha = str_repeat('a', 40);
        $item = ['key' => 'k', 'expected_blob_sha' => $sha, 'action' => 'create'];

        yield '沒有 commit_sha' => [['items' => [$item]], 'commit_sha'];
        yield 'commit_sha 不是 40 位 hex' => [['commit_sha' => 'main', 'items' => [$item]], 'commit_sha'];
        yield 'commit_sha 大寫' => [['commit_sha' => strtoupper($sha), 'items' => [$item]], 'commit_sha'];
        yield '沒有 items' => [['commit_sha' => $sha], 'items'];
        yield 'items 是空的' => [['commit_sha' => $sha, 'items' => []], 'items'];
        yield 'items 超過上限' => [['commit_sha' => $sha, 'items' => array_map(fn ($i) => ['key' => "k{$i}"] + $item, range(1, 51))], 'items'];
        yield '動作不在清單上' => [['commit_sha' => $sha, 'items' => [['action' => 'delete'] + $item]], 'items.0.action'];
        yield 'expected_blob_sha 格式不對' => [['commit_sha' => $sha, 'items' => [['expected_blob_sha' => 'abc'] + $item]], 'items.0.expected_blob_sha'];
        yield 'key 重複' => [['commit_sha' => $sha, 'items' => [$item, $item]], 'items.0.key'];
        yield 'link 沒帶 documentation_id' => [['commit_sha' => $sha, 'items' => [['action' => 'link'] + $item]], 'items.0.documentation_id'];
        yield 'create 帶了 documentation_id' => [['commit_sha' => $sha, 'items' => [['documentation_id' => 1] + $item]], 'items.0.documentation_id'];
        yield 'documentation_id 不是整數' => [['commit_sha' => $sha, 'items' => [['action' => 'link', 'documentation_id' => 'abc'] + $item]], 'items.0.documentation_id'];
        yield 'documentation_id 超出 bigint' => [['commit_sha' => $sha, 'items' => [['action' => 'link', 'documentation_id' => '99999999999999999999'] + $item]], 'items.0.documentation_id'];
        yield 'overwrite_site_edits 不是布林' => [['commit_sha' => $sha, 'items' => [['overwrite_site_edits' => 'yes'] + $item]], 'items.0.overwrite_site_edits'];
    }

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('invalidPayloads')]
    #[TestDox('請求格式不對時回 422，不打 GitHub')]
    public function test_invalid_payload_returns_422_without_calling_github(array $payload, string $field): void
    {
        $this->actingAsOwner();

        $this->postJson('/api/article-sources/import', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        Http::assertNothingSent();
    }

    #[TestDox('沒登入時匯入回 401，也不打 GitHub')]
    public function test_guest_cannot_import(): void
    {
        $content = self::article('first-post', '第一篇文章');
        $commit = $this->publishSourceFiles(['first.md' => $content]);

        $this->import([$this->item('first-post', $content, 'create')], $commit)->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(0, Documentation::count());
    }

    #[TestDox('匯入後的文章，公開 API 與編輯用的回應都不含來源欄位')]
    public function test_imported_article_does_not_expose_source_columns(): void
    {
        $content = self::article('first-post', 'imported-title-marker');
        $commit = $this->publishSourceFiles(['private-dir/first.md' => $content]);
        $this->actingAsOwner();
        $import = $this->import([$this->item('first-post', $content, 'create')], $commit)->assertOk();
        $id = $import->json('data.0.documentation_id');
        $this->assertNoSourceColumns($import);
        Documentation::findOrFail($id)->update(['status' => Documentation::STATUS_PUBLISHED]);

        foreach (["/api/documentations/{$id}", '/api/documentations', '/api/graph'] as $url) {
            $this->assertNoSourceColumns($this->getJson($url)->assertOk()->assertSee('imported-title-marker'));
        }
        auth()->guard('web')->logout();
        foreach (["/api/documentations/{$id}", '/api/documentations', '/api/graph'] as $url) {
            $this->assertNoSourceColumns($this->getJson($url)->assertOk()->assertSee('imported-title-marker'));
        }
    }

    #[TestDox('匯入結果帶公開安全警告')]
    public function test_import_result_carries_warnings(): void
    {
        $content = self::article('mail', '有 email 的文章', "聯絡 someone@corp.invalid-domain.io\n");
        $commit = $this->publishSourceFiles(['mail.md' => $content]);
        $this->actingAsOwner();

        $this->import([$this->item('mail', $content, 'create')], $commit)
            ->assertOk()
            ->assertJsonPath('data.0.warnings.0.code', 'email')
            ->assertJsonPath('data.0.warnings.0.line', 7);
    }

    /**
     * 已連結、來源有新版的文章。
     *
     * @param  array<string, string>  $extraFiles
     * @return array{0: Documentation, 1: string, 2: string}
     */
    private function changedArticle(int $status, array $extraFiles = []): array
    {
        $content = self::article('the-post', '新版標題', "新版內文。\n");
        $commit = $this->publishSourceFiles(['post.md' => $content] + $extraFiles);
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'title' => '舊版標題', 'body' => "舊版內文。\n", 'status' => $status]);
        $documentation->forceFill([
            'source_repo' => $this->sourceRepo, 'source_key' => 'the-post', 'source_path' => 'post.md',
            'source_blob_sha' => str_repeat('1', 40), 'source_commit_sha' => str_repeat('2', 40),
            'source_body_hash' => hash('sha256', "舊版內文。\n"),
        ])->save();

        return [$documentation, $content, $commit];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(string $key, string $content, string $action, array $extra = []): array
    {
        return ['key' => $key, 'expected_blob_sha' => self::blobSha($content), 'action' => $action] + $extra;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function import(array $items, string $commit): TestResponse
    {
        return $this->postJson('/api/article-sources/import', ['commit_sha' => $commit, 'items' => $items]);
    }

    private function assertImportEvent(string $action, int $userId, int $documentationId, string $key, string $blobSha, string $commit): void
    {
        $events = array_values(array_filter(
            $this->securityEvents('authz_admin'),
            fn (MessageLogged $message) => $message->context['action'] === $action,
        ));
        $this->assertCount(1, $events);
        $this->assertSame([
            'event' => 'authz_admin',
            'user_id' => $userId,
            'action' => $action,
            'object' => 'documentation',
            'object_id' => $documentationId,
            'source_key' => $key,
            'blob_sha' => $blobSha,
            'commit_sha' => $commit,
        ], $events[0]->context);
        $this->assertSame('warning', $events[0]->level);
    }

    private function assertNoSourceColumns(TestResponse $response): void
    {
        $content = $response->getContent();
        foreach (self::SOURCE_COLUMNS as $column) {
            $this->assertStringNotContainsString($column, $content);
        }
        $this->assertStringNotContainsString('private-dir', $content);
        $this->assertStringNotContainsString($this->sourceRepo, $content);
    }
}
