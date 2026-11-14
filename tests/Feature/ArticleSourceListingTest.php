<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * `GET /api/article-sources`：從文章來源 repo 列出可匯入的文章與它們的狀態。
 *
 * GitHub 用 `FakesArticleSourceRepo` 模擬，不打真的 API。
 */
class ArticleSourceListingTest extends TestCase
{
    use FakesArticleSourceRepo;
    use RefreshDatabase;

    private Scope $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Scope::factory()->create(['name' => 'post']);
        $this->configureArticleSource();
        $this->fakeGitHub();
    }

    #[TestDox('沒登入時列清單回 401，也不會去打 GitHub')]
    public function test_guest_gets_401_and_github_is_not_called(): void
    {
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);

        $this->getJson('/api/article-sources')->assertUnauthorized();

        Http::assertNothingSent();
    }

    /**
     * 每一種狀態各準備一篇，一次列出來，逐項比對。
     */
    #[TestDox('清單把每個檔案標成正確的狀態，八種狀態都會出現')]
    public function test_listing_reports_every_status(): void
    {
        $unchangedContent = self::article('unchanged-post', '沒變的文章');
        $deletedContent = self::article('deleted-post', '刪掉的文章');
        $this->publishSourceFiles([
            'posts/new.md' => self::article('new-post', '新文章'),
            'posts/changed.md' => self::article('changed-post', '有更新的文章', "新版內文。\n"),
            'posts/unchanged.md' => $unchangedContent,
            'posts/deleted.md' => $deletedContent,
            'posts/conflict.md' => self::article('conflict-post', '兩邊都改過', "來源的新版。\n"),
            'posts/match.md' => self::article('match-post', '既有的同名文章'),
            'posts/no-slug.md' => self::article(null, '沒有 slug'),
        ]);

        $changed = $this->linked('changed-post', 'posts/changed.md', str_repeat('1', 40), "舊版內文。\n", ['status' => Documentation::STATUS_PUBLISHED]);
        $unchanged = $this->linked('unchanged-post', 'posts/unchanged.md', self::blobSha($unchangedContent), "內文第一段。\n");
        $deleted = $this->linked('deleted-post', 'posts/deleted.md', self::blobSha($deletedContent), "內文第一段。\n");
        $deleted->delete();
        $conflict = $this->linked('conflict-post', 'posts/conflict.md', str_repeat('2', 40), "匯入時的內文。\n");
        $conflict->forceFill(['body' => "站上改過的內文。\n"])->save();
        $removed = $this->linked('removed-post', 'posts/removed.md', str_repeat('3', 40), "已經不在來源的文章。\n");
        $match = Documentation::factory()->create(['type' => $this->post->id, 'title' => '既有的同名文章', 'status' => 0]);

        $this->actingAsOwner();
        $response = $this->getJson('/api/article-sources')->assertOk();

        $response->assertJsonPath('repo', $this->sourceRepo)
            ->assertJsonPath('commit_sha', $this->headCommit);
        $items = collect($response->json('items'))->keyBy('path');

        $this->assertSame(
            [
                'posts/changed.md' => ['changed', $changed->id],
                'posts/conflict.md' => ['conflict', $conflict->id],
                'posts/deleted.md' => ['site_deleted', $deleted->id],
                'posts/match.md' => ['possible_match', $match->id],
                'posts/new.md' => ['new', null],
                'posts/no-slug.md' => ['invalid', null],
                'posts/removed.md' => ['removed', $removed->id],
                'posts/unchanged.md' => ['unchanged', $unchanged->id],
            ],
            $items->map(fn (array $item) => [$item['status'], $item['documentation_id']])->sortKeys()->all(),
        );

        $this->assertSame('new-post', $items['posts/new.md']['key']);
        $this->assertSame('新文章', $items['posts/new.md']['title']);
        $this->assertSame(self::blobSha(self::article('new-post', '新文章')), $items['posts/new.md']['blob_sha']);
        $this->assertTrue($items['posts/changed.md']['published']);
        $this->assertFalse($items['posts/match.md']['published']);
        $this->assertNull($items['posts/deleted.md']['published']);
        $this->assertSame('slug_missing', $items['posts/no-slug.md']['reason']);
        $this->assertNotEmpty($items['posts/no-slug.md']['reason_message']);
        $this->assertNull($items['posts/removed.md']['blob_sha']);
        $this->assertNull($items['posts/new.md']['reason']);
    }

    #[TestDox('路徑與 blob 都沒變的已連結檔案不會下載內容')]
    public function test_unchanged_and_site_deleted_files_are_not_downloaded(): void
    {
        $unchangedContent = self::article('unchanged-post', '沒變的文章');
        $deletedContent = self::article('deleted-post', '刪掉的文章');
        $this->publishSourceFiles([
            'unchanged.md' => $unchangedContent,
            'deleted.md' => $deletedContent,
            'new.md' => self::article('new-post', '新文章'),
        ]);
        $this->linked('unchanged-post', 'unchanged.md', self::blobSha($unchangedContent), "內文第一段。\n");
        $this->linked('deleted-post', 'deleted.md', self::blobSha($deletedContent), "內文第一段。\n")->delete();

        $this->actingAsOwner();
        $this->getJson('/api/article-sources')->assertOk();

        $this->assertCount(1, $this->githubRequests('/contents/'));
        $this->assertCount(1, $this->githubRequests('/contents/new.md'));
    }

    #[TestDox('沒有 front matter 或沒有 import: true 的檔案不會出現在清單上')]
    public function test_files_that_did_not_opt_in_are_not_listed(): void
    {
        $this->publishSourceFiles([
            'README.md' => "# 說明\n\n沒有 front matter。\n",
            'notes/draft.md' => self::article('draft-note', '還不要公開', import: false),
            'notes/string-true.md' => "---\nslug: string-true\nimport: \"true\"\n---\n# 字串的 true\n",
            'notes/not-markdown.txt' => self::article('txt-file', '不是 md'),
            'opted-in.md' => self::article('opted-in', '要匯入的'),
        ]);

        $this->actingAsOwner();
        $paths = array_column($this->getJson('/api/article-sources')->assertOk()->json('items'), 'path');

        $this->assertSame(['opted-in.md'], $paths);
    }

    #[TestDox('超過 1 MB 的檔案列成 invalid，而且不下載內容')]
    public function test_file_over_one_megabyte_is_invalid_and_not_downloaded(): void
    {
        $this->publishSourceFiles([
            'big.md' => ['content' => self::article('big', '很大的文章'), 'size' => 1024 * 1024 + 1],
            'edge.md' => ['content' => self::article('edge', '剛好 1 MB'), 'size' => 1024 * 1024],
        ]);

        $this->actingAsOwner();
        $items = collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->keyBy('path');

        $this->assertSame('invalid', $items['big.md']['status']);
        $this->assertSame('too_large', $items['big.md']['reason']);
        $this->assertNull($items['big.md']['key']);
        $this->assertSame('new', $items['edge.md']['status']);
        $this->assertCount(0, $this->githubRequests('/contents/big.md'));
    }

    #[TestDox('兩個檔案用同一個 slug 時兩個都列成 invalid')]
    public function test_duplicate_slugs_mark_every_copy_invalid(): void
    {
        $this->publishSourceFiles([
            'a.md' => self::article('same-slug', '第一篇'),
            'b.md' => self::article('same-slug', '第二篇'),
            'c.md' => self::article('other', '另一篇'),
        ]);

        $this->actingAsOwner();
        $items = collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->keyBy('path');

        $this->assertSame(['invalid', 'duplicate_slug'], [$items['a.md']['status'], $items['a.md']['reason']]);
        $this->assertSame(['invalid', 'duplicate_slug'], [$items['b.md']['status'], $items['b.md']['reason']]);
        $this->assertSame('new', $items['c.md']['status']);
    }

    #[TestDox('已連結的文章在來源裡變成 slug 重複時列 invalid，不會被當成已移除')]
    public function test_linked_article_whose_file_becomes_a_duplicate_is_not_reported_removed(): void
    {
        $this->publishSourceFiles([
            'a.md' => self::article('shared', '第一篇'),
            'b.md' => self::article('shared', '第二篇'),
        ]);
        $this->linked('shared', 'a.md', str_repeat('4', 40), "內文第一段。\n");

        $this->actingAsOwner();
        $statuses = array_column($this->getJson('/api/article-sources')->assertOk()->json('items'), 'status');

        $this->assertSame(['invalid', 'invalid'], $statuses);
    }

    #[TestDox('front matter 的 YAML 寫錯時列成 invalid')]
    public function test_broken_yaml_is_listed_as_invalid(): void
    {
        $this->publishSourceFiles(['broken.md' => "---\nslug: [unclosed\nimport: true\n---\n# 標題\n"]);

        $this->actingAsOwner();
        $this->getJson('/api/article-sources')->assertOk()
            ->assertJsonPath('items.0.status', 'invalid')
            ->assertJsonPath('items.0.reason', 'yaml_error');
    }

    #[TestDox('symlink 不列入清單，也不下載')]
    public function test_symlinks_are_ignored(): void
    {
        $this->publishSourceFiles([
            'link.md' => ['content' => 'target.md', 'mode' => '120000'],
            'target.md' => self::article('target', '目標'),
        ]);

        $this->actingAsOwner();
        $paths = array_column($this->getJson('/api/article-sources')->assertOk()->json('items'), 'path');

        $this->assertSame(['target.md'], $paths);
        $this->assertCount(0, $this->githubRequests('/contents/link.md'));
    }

    #[TestDox('疑似憑證的內容列成警告，回應裡不出現那段文字')]
    public function test_suspicious_content_becomes_a_warning_without_echoing_it(): void
    {
        $token = 'ghp_'.str_repeat('A1b2', 9);
        $this->publishSourceFiles(['leaky.md' => self::article('leaky', '有 token 的文章', "第一行\n\ntoken 是 {$token}\n")]);

        $this->actingAsOwner();
        $response = $this->getJson('/api/article-sources')->assertOk();

        // front matter 4 行 + H1 + 空行之後，內文從第 7 行開始，token 在第 9 行
        $response->assertJsonPath('items.0.warnings', [['code' => 'github_token', 'message' => '疑似 GitHub token', 'line' => 9]]);
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    #[TestDox('文章來源沒設定時回 503，不發出任何請求')]
    public function test_missing_configuration_returns_503_without_calling_github(): void
    {
        $this->configureArticleSource(token: null);
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'not_configured');

        config(['services.github_articles.token' => 'test-token', 'services.github_articles.repo' => null]);
        $this->getJson('/api/article-sources')->assertStatus(503)->assertJsonPath('reason', 'not_configured');

        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function malformedSettings(): iterable
    {
        yield 'repo 少了 owner' => ['just-a-name', 'main'];
        yield 'repo 多一層路徑' => ['owner/name/contents', 'main'];
        yield 'repo 帶 ..' => ['owner/..', 'main'];
        yield 'repo 帶查詢字串' => ['owner/name?x=1', 'main'];
        yield 'ref 帶 ..' => [null, '../main'];
        yield 'ref 帶查詢字串' => [null, 'main?recursive=1'];
        yield 'ref 開頭是斜線' => [null, '/main'];
    }

    #[DataProvider('malformedSettings')]
    #[TestDox('repo 或 ref 的格式不對時回 503，不發出任何請求')]
    public function test_malformed_settings_return_503_without_calling_github(?string $repo, string $ref): void
    {
        $this->configureArticleSource(repo: $repo, ref: $ref);
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'misconfigured');

        Http::assertNothingSent();
    }

    /** @return iterable<string, array{int, array<string, string>, int, string}> */
    public static function gitHubFailures(): iterable
    {
        yield '401 token 無效' => [401, [], 502, 'github_unauthorized'];
        yield '403 沒有權限' => [403, ['x-ratelimit-remaining' => '4999'], 502, 'github_forbidden'];
        yield '404 找不到 repo' => [404, [], 502, 'github_not_found'];
        yield '500 GitHub 出錯' => [500, [], 502, 'github_error'];
        yield '403 主要限流' => [403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) (time() + 120)], 503, 'github_rate_limited'];
        yield '403 次要限流' => [403, ['retry-after' => '30'], 503, 'github_rate_limited'];
        yield '429 限流' => [429, ['retry-after' => '45'], 503, 'github_rate_limited'];
    }

    /**
     * GitHub 的 401／403 不能原樣轉給前端：前端把 401 當成自己的登入過期。
     *
     * @param  array<string, string>  $headers
     */
    #[DataProvider('gitHubFailures')]
    #[TestDox('GitHub 回錯誤時回明確的 reason，不回空清單也不回 401／403')]
    public function test_github_errors_become_explicit_errors(int $upstream, array $headers, int $status, string $reason): void
    {
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);
        $this->overrideGitHub(fn () => Http::response(['message' => 'nope'], $upstream, $headers));
        $this->actingAsOwner();

        $response = $this->getJson('/api/article-sources')
            ->assertStatus($status)
            ->assertJsonPath('reason', $reason)
            ->assertJsonMissingPath('items');

        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        if ($reason === 'github_rate_limited') {
            $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        }
    }

    #[TestDox('GitHub 出錯時記一行 warning，只有代號與狀態碼，不含 token')]
    public function test_github_error_is_logged_as_a_warning_without_the_token(): void
    {
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);
        $this->overrideGitHub(fn () => Http::response(['message' => 'Bad credentials'], 401));
        $this->actingAsOwner();
        $logs = [];
        Log::listen(function (MessageLogged $message) use (&$logs) {
            $logs[] = $message;
        });

        $this->getJson('/api/article-sources')->assertStatus(502);

        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0]->level);
        $this->assertSame(['reason' => 'github_unauthorized', 'upstream_status' => 401], $logs[0]->context);
        $this->assertStringNotContainsString('test-token', json_encode([$logs[0]->message, $logs[0]->context]));
    }

    #[TestDox('只有讀檔案內容失敗時，整份清單也回錯誤')]
    public function test_failure_while_downloading_a_file_fails_the_listing(): void
    {
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);
        $this->overrideGitHub(fn (Request $request) => str_contains($request->url(), '/contents/') ? Http::response([], 403) : null);
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')->assertStatus(502)->assertJsonPath('reason', 'github_forbidden');
    }

    #[TestDox('連不上 GitHub 時回 502')]
    public function test_connection_failure_returns_502(): void
    {
        $this->overrideGitHub(fn () => throw new ConnectionException('timed out'));
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')->assertStatus(502)->assertJsonPath('reason', 'github_unreachable');
    }

    #[TestDox('tree 被截斷時回 502，不拿不完整的清單判斷')]
    public function test_truncated_tree_is_an_error(): void
    {
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);
        $this->overrideGitHub(fn (Request $request) => str_contains($request->url(), '/git/trees/')
            ? Http::response(['sha' => str_repeat('a', 40), 'tree' => [], 'truncated' => true])
            : null);
        $this->linked('a', 'a.md', str_repeat('5', 40), 'x');
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')->assertStatus(502)->assertJsonPath('reason', 'github_tree_truncated');
    }

    /** @return iterable<string, array{string}> */
    public static function corruptResponses(): iterable
    {
        yield 'commit 不是 JSON' => ['commits'];
        yield 'contents 的 blob SHA 對不上' => ['contents-sha'];
        yield 'contents 的內容被改過' => ['contents-body'];
        yield 'contents 是目錄' => ['contents-dir'];
    }

    #[DataProvider('corruptResponses')]
    #[TestDox('GitHub 的回應格式不對或內容對不上 SHA 時回 502')]
    public function test_corrupt_github_responses_are_rejected(string $case): void
    {
        $content = self::article('a', 'A');
        $this->publishSourceFiles(['a.md' => $content]);
        $sha = self::blobSha($content);
        $this->overrideGitHub(fn (Request $request) => match (true) {
            $case === 'commits' && str_contains($request->url(), '/commits/') => Http::response('<html>', 200),
            $case === 'contents-sha' && str_contains($request->url(), '/contents/') => Http::response(['type' => 'file', 'sha' => str_repeat('0', 40), 'encoding' => 'base64', 'content' => base64_encode($content)]),
            $case === 'contents-body' && str_contains($request->url(), '/contents/') => Http::response(['type' => 'file', 'sha' => $sha, 'encoding' => 'base64', 'content' => base64_encode($content.'tampered')]),
            $case === 'contents-dir' && str_contains($request->url(), '/contents/') => Http::response([['type' => 'file', 'sha' => $sha]]),
            default => null,
        });
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')->assertStatus(502)->assertJsonPath('reason', 'github_bad_response');
    }

    #[TestDox('60 秒內重列清單不重打 GitHub，之後帶 ETag，304 時沿用快取')]
    public function test_commit_lookup_is_cached_for_60_seconds_then_revalidated_with_etag(): void
    {
        // 凍結時間：不然第一個請求跨過整秒時，「59 秒後」實際上已經超過 60 秒
        $this->freezeSecond();
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);
        $this->actingAsOwner();

        $first = $this->getJson('/api/article-sources')->assertOk();
        $this->assertCount(1, $this->githubRequests('/commits/main'));

        $this->travel(59)->seconds();
        $this->getJson('/api/article-sources')->assertOk();
        $this->assertCount(1, $this->githubRequests('/commits/main'), '60 秒內不該再打 GitHub');

        $this->travel(2)->seconds();
        $this->getJson('/api/article-sources')->assertOk()->assertJsonPath('items', $first->json('items'));
        $commitRequests = $this->githubRequests('/commits/main');
        $this->assertCount(2, $commitRequests);
        $this->assertNotSame([], $commitRequests[1]->header('If-None-Match'), '超過 60 秒要帶 ETag 發 conditional request');
        $statuses = Http::recorded(fn (Request $request) => str_contains($request->url(), '/commits/main'))
            ->map(fn (array $pair) => $pair[1]->status())->values()->all();
        $this->assertSame([200, 304], $statuses, '第二次 GitHub 回 304，清單沿用快取的內容');
        // 檔案內容的解析結果依 blob SHA 快取，沒變就不用再下載
        $this->assertCount(1, $this->githubRequests('/contents/'));
    }

    #[TestDox('來源有新 commit 時，快取過期後清單會換成新版本')]
    public function test_new_commit_is_picked_up_after_the_cache_expires(): void
    {
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);
        $this->actingAsOwner();
        $this->getJson('/api/article-sources')->assertOk();

        $next = $this->publishSourceFiles(['a.md' => self::article('a', 'A'), 'b.md' => self::article('b', 'B')]);
        $this->travel(61)->seconds();

        $this->getJson('/api/article-sources')->assertOk()
            ->assertJsonPath('commit_sha', $next)
            ->assertJsonCount(2, 'items');
    }

    #[TestDox('請求 GitHub 時帶設定的 token，不用其他 token')]
    public function test_requests_use_the_article_token(): void
    {
        config(['services.github.token' => 'the-other-token']);
        $this->publishSourceFiles(['a.md' => self::article('a', 'A')]);
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')->assertOk();

        foreach ($this->githubRequests() as $request) {
            $this->assertSame(['Bearer test-token'], $request->header('Authorization'));
        }
    }

    /**
     * 已經從這個 repo 匯入（或連結）過的文章。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function linked(string $key, string $path, string $blobSha, string $body, array $attributes = []): Documentation
    {
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'title' => "{$key} 的站上標題", 'body' => $body] + $attributes);
        $documentation->forceFill([
            'source_repo' => $this->sourceRepo,
            'source_key' => $key,
            'source_path' => $path,
            'source_blob_sha' => $blobSha,
            'source_commit_sha' => str_repeat('9', 40),
            'source_imported_at' => '2026-01-01 00:00:00',
            'source_body_hash' => hash('sha256', $body),
        ])->save();

        return $documentation;
    }
}
