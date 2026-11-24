<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Scope;
use App\Services\ArticleSourceService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
        // 讀回來的這一列算出的雜湊要跟寫入時記的一樣（PostgreSQL 讀回的日期多了時間），不然下次就會誤判成站上改過
        $this->assertSame(ArticleSourceService::importedContentHash($documentation), $documentation->source_body_hash);

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
        $this->assertSame(ArticleSourceService::importedContentHash($documentation), $documentation->source_body_hash);
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

    #[TestDox('站上改過內文時，沒帶 overwrite_site_edits 回 409，帶了（加上清單上的 site_version）才覆蓋')]
    public function test_conflict_requires_overwrite_site_edits(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $documentation->forceFill(['body' => "站上改過。\n"])->save();
        $this->actingAsOwner();
        $version = $this->listedSiteVersion('the-post');

        $this->import([$this->item('the-post', $content, 'update')], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict')
            ->assertJsonStructure(['errors' => ['items.0.overwrite_site_edits']]);
        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => false, 'expected_site_version' => $version])], $commit)
            ->assertConflict()
            ->assertJsonStructure(['errors' => ['items.0.overwrite_site_edits']]);
        $this->assertSame("站上改過。\n", $documentation->refresh()->body);

        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => true, 'expected_site_version' => $version])], $commit)->assertOk();
        $this->assertSame("新版內文。\n", $documentation->refresh()->body);
    }

    #[TestDox('清單上只有 conflict 的項目附 site_version，值是站上目前標題、日期與內文的雜湊')]
    public function test_listing_carries_site_version_only_for_conflicts(): void
    {
        $new = self::article('new-post', '新文章');
        [$documentation] = $this->changedArticle(Documentation::STATUS_PUBLISHED, extraFiles: ['new.md' => $new]);
        $this->actingAsOwner();

        $items = collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->keyBy('key');
        $this->assertSame('changed', $items['the-post']['status']);
        $this->assertNull($items['the-post']['site_version']);
        $this->assertSame('new', $items['new-post']['status']);
        $this->assertNull($items['new-post']['site_version']);

        $documentation->forceFill(['body' => "站上改過。\n"])->save();
        $item = collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->firstWhere('key', 'the-post');
        $this->assertSame('conflict', $item['status']);
        $this->assertSame(ArticleSourceService::importedContentHash($documentation->refresh()), $item['site_version']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function siteFieldsEditedAgain(): iterable
    {
        yield '內文' => ['body', "列清單之後又改的內文。\n"];
        yield '標題' => ['title', '列清單之後又改的標題'];
        yield '日期' => ['creation_date', '2002-03-04'];
    }

    #[DataProvider('siteFieldsEditedAgain')]
    #[TestDox('勾了覆蓋，但列清單之後站上又改過標題、日期或內文：回 409，什麼都不寫')]
    public function test_site_edit_after_listing_blocks_overwrite(string $column, string $value): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $documentation->forceFill(['body' => "站上改過。\n"])->save();
        $this->actingAsOwner();
        $version = $this->listedSiteVersion('the-post');
        // 看完清單、勾了覆蓋，送出之前站上又被改了一次
        $documentation->forceFill([$column => $value])->save();
        $before = $documentation->refresh()->only(['title', 'creation_date', 'body', 'status', 'source_blob_sha', 'source_commit_sha', 'source_imported_at', 'source_body_hash']);
        $this->captureLogs();

        $response = $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => true, 'expected_site_version' => $version])], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict');
        $this->assertSame(['items.0.expected_site_version'], array_keys($response->json('errors')));

        $this->assertSame($before, $documentation->refresh()->only(array_keys($before)));
        $this->assertNoSecurityEvent('authz_admin');

        // 重新列清單拿到新的 site_version，就能覆蓋
        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => true, 'expected_site_version' => $this->listedSiteVersion('the-post')])], $commit)->assertOk();
        $this->assertSame("新版內文。\n", $documentation->refresh()->body);
    }

    #[TestDox('列清單之後站上只改了發布狀態（覆蓋不會動到的欄位）時，照樣能覆蓋')]
    public function test_status_change_after_listing_does_not_block_overwrite(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $documentation->forceFill(['body' => "站上改過。\n"])->save();
        $this->actingAsOwner();
        $version = $this->listedSiteVersion('the-post');
        $this->travel(5)->seconds();
        $documentation->forceFill(['status' => Documentation::STATUS_DRAFT])->save();

        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => true, 'expected_site_version' => $version])], $commit)->assertOk();
        $this->assertSame("新版內文。\n", $documentation->refresh()->body);
        $this->assertSame(Documentation::STATUS_DRAFT, (int) $documentation->status);
    }

    #[TestDox('勾了覆蓋卻沒帶 expected_site_version：回 422，不打 GitHub，什麼都不寫')]
    public function test_overwrite_without_site_version_is_rejected(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $documentation->forceFill(['body' => "站上改過。\n"])->save();
        $this->actingAsOwner();
        $this->captureLogs();

        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => true])], $commit)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.expected_site_version']);

        $this->assertSame("站上改過。\n", $documentation->refresh()->body);
        $this->assertSame(str_repeat('1', 40), $documentation->source_blob_sha);
        $this->assertNoSecurityEvent('authz_admin');
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string, string}> */
    public static function titleOrDateEdits(): iterable
    {
        yield '標題' => ['title', '站上改的標題'];
        yield '日期' => ['creation_date', '2001-02-03'];
    }

    #[DataProvider('titleOrDateEdits')]
    #[TestDox('站上只改過標題或日期時，也要帶 overwrite_site_edits 才覆蓋')]
    public function test_title_or_date_edit_requires_overwrite_site_edits(string $column, string $siteValue): void
    {
        [$documentation] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $content = self::article('the-post', '新版標題', "新版內文。\n", ['creation_date' => '2026-10-01']);
        $commit = $this->publishSourceFiles(['post.md' => $content]);
        $documentation->forceFill([$column => $siteValue])->save();
        $this->actingAsOwner();

        $this->import([$this->item('the-post', $content, 'update')], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict')
            ->assertJsonStructure(['errors' => ['items.0.overwrite_site_edits']]);
        $documentation->refresh();
        $this->assertStringStartsWith($siteValue, (string) $documentation->{$column});
        $this->assertSame("舊版內文。\n", $documentation->body);

        $this->import([$this->item('the-post', $content, 'update', ['overwrite_site_edits' => true, 'expected_site_version' => $this->listedSiteVersion('the-post')])], $commit)->assertOk();
        $documentation->refresh();
        $this->assertSame('新版標題', $documentation->title);
        $this->assertStringStartsWith('2026-10-01', (string) $documentation->creation_date);
        $this->assertSame("新版內文。\n", $documentation->body);
    }

    /**
     * 匯入時記下的雜湊，要跟之後從資料庫讀回同一列算出的一樣，不然沒人改過的文章下次也會變成 conflict。
     * 日期最容易出錯：寫入的是 `2026-09-22`，PostgreSQL 讀回來是 `2026-09-22 00:00:00`。
     * 來源沒寫日期時，update 不改站上的日期，雜湊要用站上那個日期算。
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function sourceDates(): iterable
    {
        yield '來源有日期' => [['creation_date' => '2026-09-22']];
        yield '來源沒有日期' => [[]];
    }

    /**
     * @param  array<string, string>  $date
     */
    #[DataProvider('sourceDates')]
    #[TestDox('新增、更新、連結過的文章站上沒改時，來源再改版是 changed')]
    public function test_untouched_article_is_changed_not_conflict_after_each_write(array $date): void
    {
        $this->actingAsOwner();
        $v1 = self::article('the-post', '第一版', "第一版內文。\n", $date);
        $c1 = $this->publishSourceFiles(['post.md' => $v1, 'legacy.md' => self::article('legacy', '既有文章')]);
        $legacy = Documentation::factory()->create(['type' => $this->post->id, 'title' => '既有文章', 'body' => "站上的版本。\n", 'creation_date' => '2025-05-06']);
        $this->import([
            $this->item('the-post', $v1, 'create'),
            $this->item('legacy', self::article('legacy', '既有文章'), 'link', ['documentation_id' => $legacy->id]),
        ], $c1)->assertOk();

        $v2 = self::article('the-post', '第二版', "第二版內文。\n", $date);
        $c2 = $this->publishSourceFiles(['post.md' => $v2, 'legacy.md' => self::article('legacy', '既有文章', "來源改版。\n")]);
        $this->assertSame(['legacy' => 'changed', 'the-post' => 'changed'], $this->statuses());

        $this->import([$this->item('the-post', $v2, 'update')], $c2)->assertOk();
        // 分支最新 commit 的快取 60 秒內不重打 GitHub
        $this->travel(61)->seconds();
        $this->publishSourceFiles(['post.md' => self::article('the-post', '第三版', "第三版內文。\n", $date), 'legacy.md' => self::article('legacy', '既有文章', "來源改版。\n")]);
        $this->assertSame(['legacy' => 'changed', 'the-post' => 'changed'], $this->statuses());
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
        $this->assertSame(ArticleSourceService::importedContentHash($documentation), $documentation->source_body_hash);
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
        $documentation->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'old-slug', 'source_path' => 'post.md', 'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => ArticleSourceService::importedContentHash($documentation)])->save();
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
        $documentation->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'taken', 'source_path' => 'taken.md', 'source_blob_sha' => self::blobSha($taken), 'source_body_hash' => ArticleSourceService::importedContentHash($documentation)])->save();
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
        $deleted->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'gone', 'source_path' => 'gone.md', 'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => ArticleSourceService::importedContentHash($deleted)])->save();
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

    #[TestDox('用比站上已匯入的版本還舊的 commit 更新時回 409，不把文章倒回舊內容')]
    public function test_update_from_a_commit_older_than_the_imported_one_is_rejected(): void
    {
        [$documentation, $v2, $c2, $v3, $c3] = $this->importedFromNewerCommit();

        $this->import([$this->item('the-post', $v2, 'update')], $c2)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict')
            ->assertJsonStructure(['errors' => ['items.0.action']]);

        $documentation->refresh();
        $this->assertSame("第三版內文。\n", $documentation->body);
        $this->assertSame('第三版', $documentation->title);
        $this->assertSame(self::blobSha($v3), $documentation->source_blob_sha);
        $this->assertSame($c3, $documentation->source_commit_sha);
    }

    #[TestDox('站上改過內文時，帶 overwrite_site_edits 也不能用較舊的 commit 覆蓋')]
    public function test_older_commit_is_rejected_even_when_overwriting_site_edits(): void
    {
        [$documentation, $v2, $c2] = $this->importedFromNewerCommit();
        $documentation->forceFill(['body' => "站上改過。\n"])->save();

        $version = ArticleSourceService::importedContentHash($documentation->refresh());

        $this->import([$this->item('the-post', $v2, 'update', ['overwrite_site_edits' => true, 'expected_site_version' => $version])], $c2)
            ->assertConflict()
            ->assertJsonStructure(['errors' => ['items.0.action']]);

        $this->assertSame("站上改過。\n", $documentation->refresh()->body);
    }

    #[TestDox('比站上已匯入的版本新的 commit 照常更新；新增時不用比對版本')]
    public function test_update_from_a_commit_newer_than_the_imported_one_succeeds(): void
    {
        [$documentation, , , , $c3] = $this->importedFromNewerCommit();
        $v4 = self::article('the-post', '第四版', "第四版內文。\n");
        $c4 = $this->publishSourceFiles(['post.md' => $v4]);

        $this->import([$this->item('the-post', $v4, 'update')], $c4)->assertOk();

        $this->assertSame("第四版內文。\n", $documentation->refresh()->body);
        $this->assertSame($c4, $documentation->source_commit_sha);
        $this->assertCount(1, $this->githubRequests("/compare/{$c3}...{$c4}"));
        // 第一次新增（importedFromNewerCommit 裡）沒有站上的版本可比，不打 Compare API
        $this->assertCount(2, $this->githubRequests('/compare/'));
    }

    #[TestDox('歷史被改寫（兩個 commit 互不包含）時回 409，帶站上記的 commit 確認後才更新')]
    public function test_diverged_history_requires_confirmation(): void
    {
        [$documentation, $v2, $c2, , $c3] = $this->importedFromNewerCommit();
        $this->overrideGitHub(fn (Request $request) => str_contains($request->url(), '/compare/')
            ? Http::response(['status' => 'diverged', 'ahead_by' => 1, 'behind_by' => 1])
            : null);
        $this->captureLogs();

        $this->assertUnverified($this->import([$this->item('the-post', $v2, 'update')], $c2), [0 => $c3]);
        $this->assertSame("第三版內文。\n", $documentation->refresh()->body);
        $this->assertSame($c3, $documentation->source_commit_sha);
        $this->assertUnverifiedLogged([['documentation_id' => $documentation->id, 'relation' => 'diverged']]);
        $this->assertNoSecurityEvent('authz_admin');

        $this->import([$this->item('the-post', $v2, 'update', ['confirm_unverified_base' => $c3])], $c2)->assertOk();
        $this->assertSame("第二版內文。\n", $documentation->refresh()->body);
        $this->assertSame($c2, $documentation->source_commit_sha);
    }

    #[TestDox('站上記的 commit GitHub 已經查不到時回 409，帶那個 commit 確認後才更新')]
    public function test_imported_commit_unknown_to_github_requires_confirmation(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $gone = str_repeat('2', 40);
        $documentation->forceFill(['source_commit_sha' => $gone])->save();
        $this->actingAsOwner();
        $this->captureLogs();

        $this->assertUnverified($this->import([$this->item('the-post', $content, 'update')], $commit), [0 => $gone]);
        $this->assertSame("舊版內文。\n", $documentation->refresh()->body);
        $this->assertCount(1, $this->githubRequests('/compare/'.$gone.'...'.$commit));
        $this->assertUnverifiedLogged([['documentation_id' => $documentation->id, 'relation' => 'unknown']]);

        $this->import([$this->item('the-post', $content, 'update', ['confirm_unverified_base' => $gone])], $commit)->assertOk();
        $this->assertSame("新版內文。\n", $documentation->refresh()->body);
        $this->assertSame($commit, $documentation->source_commit_sha);
    }

    #[TestDox('站上記的 commit 不是 SHA 格式時不組進 GitHub 網址，回 409 且不能確認')]
    public function test_malformed_imported_commit_is_not_sent_to_github(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $documentation->forceFill(['source_commit_sha' => '../../main'])->save();
        $this->actingAsOwner();
        $this->captureLogs();

        $response = $this->import([$this->item('the-post', $content, 'update')], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict');
        $this->assertSame(['items.0.action'], array_keys($response->json('errors')));
        // 帶不回來的值不列給用戶端
        $response->assertJsonMissingPath('unverified_commits');
        $this->assertUnverifiedLogged([['documentation_id' => $documentation->id, 'relation' => 'unknown']]);
        // 任何 SHA 格式的確認都對不上
        $this->import([$this->item('the-post', $content, 'update', ['confirm_unverified_base' => str_repeat('2', 40)])], $commit)
            ->assertConflict()
            ->assertJsonStructure(['errors' => ['items.0.action']]);

        $this->assertSame("舊版內文。\n", $documentation->refresh()->body);
        // 欄位是 char(40)，PostgreSQL 讀回來會補空白
        $this->assertSame('../../main', rtrim($documentation->source_commit_sha));
        $this->assertSame([], $this->githubRequests('/compare/'));
    }

    #[TestDox('確認的 commit 跟站上記的不同時回 409，附上站上目前記的 commit')]
    public function test_confirmation_for_another_commit_is_rejected(): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $gone = str_repeat('2', 40);
        $documentation->forceFill(['source_commit_sha' => $gone])->save();
        $this->actingAsOwner();

        // 別的 commit、這次的 commit、只差最後一個字元的 commit
        foreach ([str_repeat('3', 40), $commit, substr($gone, 0, 39).'3'] as $wrong) {
            $response = $this->assertUnverified($this->import([$this->item('the-post', $content, 'update', ['confirm_unverified_base' => $wrong])], $commit), [0 => $gone]);
            $this->assertStringContainsString('不是確認時的那一個', $response->json('errors')['items.0.confirm_unverified_base'][0]);
        }

        $this->assertSame("舊版內文。\n", $documentation->refresh()->body);
        $this->assertSame($gone, $documentation->source_commit_sha);
    }

    #[TestDox('站上已經匯入較新的版本時，帶了確認也回 409')]
    public function test_confirmation_does_not_allow_an_older_commit(): void
    {
        [$documentation, $v2, $c2, , $c3] = $this->importedFromNewerCommit();
        $this->captureLogs();

        $response = $this->import([$this->item('the-post', $v2, 'update', ['confirm_unverified_base' => $c3])], $c2)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict');
        $this->assertSame(['items.0.action'], array_keys($response->json('errors')));
        $response->assertJsonMissingPath('unverified_commits');

        $this->assertSame("第三版內文。\n", $documentation->refresh()->body);
        $this->assertSame($c3, $documentation->source_commit_sha);
        // 判斷得出先後（behind），不算判斷不出來，不記那一行
        $this->assertSame([], $this->unverifiedLogs());
    }

    #[TestDox('確認之後、寫入之前那一篇被匯入了別的版本：回 409，不寫入')]
    public function test_import_landing_after_confirmation_is_a_conflict(): void
    {
        [$documentation, $v2, $c2, , $c3] = $this->importedFromNewerCommit();
        $v5 = self::article('the-post', '第五版', "第五版內文。\n");
        $c5 = $this->publishSourceFiles(['post.md' => $v5]);
        $downloads = 0;
        $this->overrideGitHub(function (Request $request) use ($documentation, $c2, $c5, $v5, &$downloads) {
            if (str_contains($request->url(), '/compare/')) {
                return Http::response(['status' => 'diverged', 'ahead_by' => 1, 'behind_by' => 1]);
            }
            // 比對（c3...c2）之後、下載要寫入的內容時，另一個請求先把 c5 寫進去
            if (str_contains($request->url(), '/contents/') && str_contains($request->url(), 'ref='.$c2) && ++$downloads === 2) {
                $fifth = ['title' => '第五版', 'body' => "第五版內文。\n"];
                Documentation::whereKey($documentation->id)->update($fifth + [
                    'source_blob_sha' => self::blobSha($v5), 'source_commit_sha' => $c5,
                    'source_body_hash' => ArticleSourceService::importedContentHash((clone $documentation)->forceFill($fifth)),
                ]);
            }

            return null;
        });

        $response = $this->import([$this->item('the-post', $v2, 'update', ['confirm_unverified_base' => $c3])], $c2)
            ->assertConflict()
            ->assertJsonStructure(['errors' => ['items.0.action']]);
        $response->assertJsonMissingPath('unverified_commits');

        $this->assertSame(2, $downloads);
        $this->assertSame("第五版內文。\n", $documentation->refresh()->body);
        $this->assertSame($c5, $documentation->source_commit_sha);
    }

    #[TestDox('同一批裡只有判斷不出先後的那一項要確認，unverified_commits 標出它的索引')]
    public function test_unverified_commits_point_at_the_right_items(): void
    {
        [$known, $knownContent] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $other = self::article('other-post', '另一篇新版', "另一篇新版內文。\n");
        $commit = $this->publishSourceFiles(['post.md' => $knownContent, 'other.md' => $other]);
        $gone = str_repeat('2', 40);
        $unknown = Documentation::factory()->create(['type' => $this->post->id, 'title' => '另一篇', 'body' => "另一篇舊版。\n"]);
        $unknown->forceFill([
            'source_repo' => $this->sourceRepo, 'source_key' => 'other-post', 'source_path' => 'other.md',
            'source_blob_sha' => str_repeat('1', 40), 'source_commit_sha' => $gone,
            'source_body_hash' => ArticleSourceService::importedContentHash($unknown),
        ])->save();
        $this->actingAsOwner();
        $items = [$this->item('the-post', $knownContent, 'update'), $this->item('other-post', $other, 'update')];

        $this->assertUnverified($this->import($items, $commit), [1 => $gone]);
        $this->assertSame("舊版內文。\n", $known->refresh()->body);
        $this->assertSame("另一篇舊版。\n", $unknown->refresh()->body);

        $items[1]['confirm_unverified_base'] = $gone;
        $this->import($items, $commit)->assertOk();
        $this->assertSame("新版內文。\n", $known->refresh()->body);
        $this->assertSame("另一篇新版內文。\n", $unknown->refresh()->body);
    }

    #[TestDox('判斷得出這次比較新時，帶不帶確認都照常更新')]
    public function test_confirmation_is_ignored_when_the_order_is_known(): void
    {
        [$documentation, , , , $c3] = $this->importedFromNewerCommit();
        $v4 = self::article('the-post', '第四版', "第四版內文。\n");
        $c4 = $this->publishSourceFiles(['post.md' => $v4]);

        $this->import([$this->item('the-post', $v4, 'update', ['confirm_unverified_base' => str_repeat('3', 40)])], $c4)->assertOk();

        $this->assertSame("第四版內文。\n", $documentation->refresh()->body);
        $this->assertCount(1, $this->githubRequests("/compare/{$c3}...{$c4}"));
    }

    /**
     * 用戶端可能每一項都送 `confirm_unverified_base`，沒確認時填 null 或空字串；這兩種都要當成沒帶。
     *
     * @return iterable<string, array{string|null}>
     */
    public static function emptyConfirmations(): iterable
    {
        yield 'null' => [null];
        yield '空字串' => [''];
    }

    #[DataProvider('emptyConfirmations')]
    #[TestDox('新增與連結帶空的 confirm_unverified_base 時照常匯入')]
    public function test_empty_confirmation_is_accepted_for_create_and_link(?string $empty): void
    {
        $created = self::article('first-post', '第一篇文章');
        $linked = self::article('legacy', '既有文章');
        $commit = $this->publishSourceFiles(['first.md' => $created, 'legacy.md' => $linked]);
        $legacy = Documentation::factory()->create(['type' => $this->post->id, 'title' => '既有文章']);
        $this->actingAsOwner();

        $this->import([
            $this->item('first-post', $created, 'create', ['confirm_unverified_base' => $empty]),
            $this->item('legacy', $linked, 'link', ['documentation_id' => $legacy->id, 'confirm_unverified_base' => $empty]),
        ], $commit)->assertOk();

        $this->assertSame(1, Documentation::where('source_key', 'first-post')->count());
        $this->assertSame('legacy', $legacy->refresh()->source_key);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function emptyConfirmationsForUnverifiedOrder(): iterable
    {
        foreach (['unknown', 'diverged'] as $relation) {
            foreach (self::emptyConfirmations() as $label => [$empty]) {
                yield "{$relation}、{$label}" => [$relation, $empty];
            }
        }
    }

    #[DataProvider('emptyConfirmationsForUnverifiedOrder')]
    #[TestDox('判斷不出先後時，空的 confirm_unverified_base 等於沒確認')]
    public function test_empty_confirmation_counts_as_not_confirmed(string $relation, ?string $empty): void
    {
        [$documentation, $content, $commit] = $this->changedArticle(Documentation::STATUS_PUBLISHED);
        $imported = $documentation->source_commit_sha;
        if ($relation === 'unknown') {
            $imported = str_repeat('2', 40);
            $documentation->forceFill(['source_commit_sha' => $imported])->save();
        } else {
            $this->overrideGitHub(fn (Request $request) => str_contains($request->url(), '/compare/')
                ? Http::response(['status' => 'diverged', 'ahead_by' => 1, 'behind_by' => 1])
                : null);
        }
        $this->actingAsOwner();

        $response = $this->assertUnverified($this->import([$this->item('the-post', $content, 'update', ['confirm_unverified_base' => $empty])], $commit), [0 => $imported]);

        // 是要求帶確認的那一句，不是「確認的 commit 不符」那一句
        $message = $response->json('errors')['items.0.confirm_unverified_base'][0];
        $this->assertStringContainsString('請帶上 confirm_unverified_base', $message);
        $this->assertStringNotContainsString('不是確認時的那一個', $message);
        $this->assertSame("舊版內文。\n", $documentation->refresh()->body);
    }

    /** @return iterable<string, array{Closure(): mixed, int, string}> */
    public static function compareFailures(): iterable
    {
        yield 'GitHub 回 500' => [fn () => Http::response(['message' => 'boom'], 500), 502, 'github_error'];
        yield '連線逾時' => [fn () => throw new ConnectionException('timed out'), 502, 'github_unreachable'];
        yield '回應沒有 status' => [fn () => Http::response(['ahead_by' => 1]), 502, 'github_bad_response'];
        yield 'status 不是已知的值' => [fn () => Http::response(['status' => 'behindish']), 502, 'github_bad_response'];
        yield '被限流' => [fn () => Http::response(['message' => 'rate limited'], 429, ['retry-after' => '30']), 503, 'github_rate_limited'];
    }

    /** @param  Closure(): mixed  $failure */
    #[DataProvider('compareFailures')]
    #[TestDox('比對版本時 GitHub 出錯：回錯誤，什麼都不寫')]
    public function test_compare_failure_writes_nothing(Closure $failure, int $status, string $reason): void
    {
        [$documentation, $v2, $c2] = $this->importedFromNewerCommit();
        $this->overrideGitHub(fn (Request $request) => str_contains($request->url(), '/compare/') ? $failure() : null);

        $this->import([$this->item('the-post', $v2, 'update')], $c2)
            ->assertStatus($status)
            ->assertJsonPath('reason', $reason);

        $this->assertSame("第三版內文。\n", $documentation->refresh()->body);
    }

    #[TestDox('比對完、寫入前，那一篇被另一個請求匯入了別的版本：回 409，不寫入')]
    public function test_import_landing_after_the_compare_is_a_conflict(): void
    {
        [$documentation, , , , $c3] = $this->importedFromNewerCommit();
        $v4 = self::article('the-post', '第四版', "第四版內文。\n");
        $c4 = $this->publishSourceFiles(['post.md' => $v4]);
        $v5 = self::article('the-post', '第五版', "第五版內文。\n");
        $c5 = $this->publishSourceFiles(['post.md' => $v5]);
        // 用 c4 的清單匯入；比對 c3...c4 之後、下載要寫入的內容時，另一個請求先把 c5 寫進去了。
        // c4 的檔案會下載兩次：第一次是列出檔案時的解析摘要（在比對之前），第二次才是比對之後下載要寫入的內容
        $downloads = 0;
        $this->overrideGitHub(function (Request $request) use ($documentation, $c4, $c5, $v5, &$downloads) {
            if (str_contains($request->url(), '/contents/') && str_contains($request->url(), 'ref='.$c4) && ++$downloads === 2) {
                $fifth = ['title' => '第五版', 'body' => "第五版內文。\n"];
                Documentation::whereKey($documentation->id)->update($fifth + [
                    'source_blob_sha' => self::blobSha($v5), 'source_commit_sha' => $c5,
                    'source_body_hash' => ArticleSourceService::importedContentHash((clone $documentation)->forceFill($fifth)),
                ]);
            }

            return null;
        });

        $this->import([$this->item('the-post', $v4, 'update')], $c4)
            ->assertConflict()
            ->assertJsonStructure(['errors' => ['items.0.action']]);

        $this->assertSame(2, $downloads);
        $this->assertCount(1, $this->githubRequests("/compare/{$c3}...{$c4}"));
        // 擋下來的是「比對過的版本已經不是站上的版本」，不是另外拿 c5 去比
        $this->assertCount(0, $this->githubRequests("/compare/{$c5}"));
        $this->assertSame("第五版內文。\n", $documentation->refresh()->body);
        $this->assertSame($c5, $documentation->source_commit_sha);
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
                'source_body_hash' => ArticleSourceService::importedContentHash($documentation),
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

    #[TestDox('寫到一半失敗（第二項找不到 post 分類）時，第一項已寫的更新會整批回滾，也不留稽核紀錄')]
    public function test_failure_on_a_later_write_rolls_back_the_earlier_write(): void
    {
        $newPost = self::article('new-post', '新文章');
        [$documentation, $changed] = $this->changedArticle(Documentation::STATUS_PUBLISHED, extraFiles: ['new.md' => $newPost]);
        $commit = $this->headCommit;
        // 軟刪除 post 分類：所有事前檢查都會過，要到第二項 create 查分類時才失敗，那時第一項 update 已經寫進資料庫
        $this->post->delete();
        $before = $documentation->refresh()->only(['title', 'body', 'status', 'source_key', 'source_path', 'source_blob_sha', 'source_commit_sha', 'source_imported_at', 'source_body_hash']);
        $this->actingAsOwner();
        $this->captureLogs();

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
        // 第一項 update 在 transaction 裡觸發的稽核紀錄，跟著回滾，不會寫出去
        $this->assertNoSecurityEvent('authz_admin');
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
        $deleted->forceFill(['source_repo' => $this->sourceRepo, 'source_key' => 'gone', 'source_path' => 'gone.md', 'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => ArticleSourceService::importedContentHash($deleted)])->save();
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
        $version = str_repeat('c', 64);
        foreach (['true' => true, '1' => 1, "'1'" => '1'] as $label => $overwrite) {
            yield "overwrite_site_edits={$label} 沒帶 expected_site_version" => [['commit_sha' => $sha, 'items' => [['overwrite_site_edits' => $overwrite] + $item]], 'items.0.expected_site_version'];
        }
        $badVersions = [
            'null' => null,
            '空字串' => '',
            '63 位' => substr($version, 1),
            '65 位' => $version.'c',
            '大寫' => strtoupper($version),
            '不是 hex' => str_repeat('g', 64),
            '40 位的 blob SHA' => $sha,
            '整數' => 1,
            '陣列' => [$version],
        ];
        foreach ($badVersions as $label => $bad) {
            yield "expected_site_version {$label}" => [['commit_sha' => $sha, 'items' => [['overwrite_site_edits' => true, 'expected_site_version' => $bad] + $item]], 'items.0.expected_site_version'];
        }
        yield 'create 帶了 confirm_unverified_base' => [['commit_sha' => $sha, 'items' => [['confirm_unverified_base' => $sha] + $item]], 'items.0.confirm_unverified_base'];
        yield 'link 帶了 confirm_unverified_base' => [['commit_sha' => $sha, 'items' => [['action' => 'link', 'documentation_id' => 1, 'confirm_unverified_base' => $sha] + $item]], 'items.0.confirm_unverified_base'];
        $update = ['action' => 'update'] + $item;
        $badBases = [
            '39 位' => substr($sha, 1),
            '41 位' => $sha.'a',
            '大寫' => strtoupper($sha),
            '不是 hex' => str_repeat('g', 40),
            '64 位的 site_version' => $version,
            '不是 SHA 的 ref' => '../../main',
            '整數' => 1,
            '布林' => true,
            '陣列' => [$sha],
        ];
        foreach ($badBases as $label => $bad) {
            yield "confirm_unverified_base {$label}" => [['commit_sha' => $sha, 'items' => [['confirm_unverified_base' => $bad] + $update]], 'items.0.confirm_unverified_base'];
        }
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
     * 已連結、來源有新版的文章。站上記的 commit 是來源歷史裡較早的一個，比對先後是 `ahead`。
     *
     * @param  array<string, string>  $extraFiles
     * @return array{0: Documentation, 1: string, 2: string}
     */
    private function changedArticle(int $status, array $extraFiles = []): array
    {
        $imported = $this->publishSourceFiles(['post.md' => self::article('the-post', '舊版標題', "舊版內文。\n")]);
        $content = self::article('the-post', '新版標題', "新版內文。\n");
        $commit = $this->publishSourceFiles(['post.md' => $content] + $extraFiles);
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'title' => '舊版標題', 'body' => "舊版內文。\n", 'status' => $status]);
        $documentation->forceFill([
            'source_repo' => $this->sourceRepo, 'source_key' => 'the-post', 'source_path' => 'post.md',
            'source_blob_sha' => str_repeat('1', 40), 'source_commit_sha' => $imported,
            'source_body_hash' => ArticleSourceService::importedContentHash($documentation),
        ])->save();

        return [$documentation, $content, $commit];
    }

    /**
     * 第一版用 c1 新增；之後依序推了第二版（c2）、第三版（c3），站上已經用 c3 更新。
     * 用 c2 的清單（看的時候第二版還是最新）送出更新，就是「舊畫面的匯入」。
     *
     * @return array{0: Documentation, 1: string, 2: string, 3: string, 4: string}
     */
    private function importedFromNewerCommit(): array
    {
        $v1 = self::article('the-post', '第一版', "第一版內文。\n");
        $c1 = $this->publishSourceFiles(['post.md' => $v1]);
        $this->actingAsOwner();
        $id = $this->import([$this->item('the-post', $v1, 'create')], $c1)->assertOk()->json('data.0.documentation_id');

        $v2 = self::article('the-post', '第二版', "第二版內文。\n");
        $c2 = $this->publishSourceFiles(['post.md' => $v2]);
        $v3 = self::article('the-post', '第三版', "第三版內文。\n");
        $c3 = $this->publishSourceFiles(['post.md' => $v3]);
        $this->import([$this->item('the-post', $v3, 'update')], $c3)->assertOk();

        $documentation = Documentation::findOrFail($id);
        $this->assertSame($c3, $documentation->source_commit_sha);

        return [$documentation, $v2, $c2, $v3, $c3];
    }

    /**
     * 409、`reason: conflict`，要確認的項目在 `errors` 的 key 是 `items.N.confirm_unverified_base`，
     * `unverified_commits` 剛好列出這些項目與站上記的 commit。
     *
     * @param  array<int, string>  $expected  請求項目的索引 => 站上記的 commit
     */
    private function assertUnverified(TestResponse $response, array $expected): TestResponse
    {
        $response->assertConflict()->assertJsonPath('reason', 'conflict');
        $this->assertSame(
            array_map(fn (int $i) => "items.{$i}.confirm_unverified_base", array_keys($expected)),
            array_keys($response->json('errors')),
        );
        $this->assertSame(
            array_map(fn (int $i, string $sha) => ['index' => $i, 'commit_sha' => $sha], array_keys($expected), $expected),
            $response->json('unverified_commits'),
        );
        // 是 JSON 陣列（不是以索引為 key 的物件），經過 ProblemDetails 重新編碼也一樣
        $this->assertStringContainsString('"unverified_commits":[{"index":', $response->getContent());

        return $response;
    }

    /** @return list<MessageLogged> 判斷不出 commit 先後時記的那一行 */
    private function unverifiedLogs(): array
    {
        return array_values(array_filter(
            $this->capturedLogs,
            fn (MessageLogged $message) => str_contains($message->message, '判斷不出來源 commit 的先後'),
        ));
    }

    /** @param  list<array{documentation_id: int, relation: string}>  $items */
    private function assertUnverifiedLogged(array $items): void
    {
        $logs = $this->unverifiedLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0]->level);
        $this->assertSame(['items' => $items], $logs[0]->context);
        // 不寫內文、標題與來源路徑
        $this->assertNotLogged('內文');
        $this->assertNotLogged('post.md');
    }

    /** 清單上這一項的 `site_version`（只有 conflict 的項目有）。 */
    private function listedSiteVersion(string $key): string
    {
        $item = collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->firstWhere('key', $key);
        $this->assertSame('conflict', $item['status']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $item['site_version']);

        return $item['site_version'];
    }

    /** @return array<string, string> 清單上每一項的 key => status */
    private function statuses(): array
    {
        return collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->pluck('status', 'key')->sortKeys()->all();
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
