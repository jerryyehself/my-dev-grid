<?php

namespace Tests\Feature;

use App\Models\ArticleUnit;
use App\Models\Documentation;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\ArticleSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * 同一批匯入原樣再送一次（例如第一次已經寫入，但回應沒送到瀏覽器，使用者又按了一次）。
 *
 * 重送的防線是鎖內重新判斷的狀態：第一次寫入之後每一項都變成 `unchanged`，新增、更新、連結都不合狀態，
 * 整批回 409，不另外記請求是否送過。這個測試把這件事固定下來，改 `ArticleSourceService::write()` 時會被抓到。
 * 用 `$this->seed()` 種出分類與述詞，about 連結才建得起來。
 */
class ArticleSourceRepeatedImportTest extends TestCase
{
    use CapturesSecurityEvents;
    use FakesArticleSourceRepo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->configureArticleSource();
        $this->fakeGitHub();
    }

    #[TestDox('新增、覆蓋更新、連結的同一批原樣再送一次：回 409，什麼都不寫')]
    public function test_resubmitting_the_same_batch_writes_nothing(): void
    {
        $post = Scope::where('name', 'post')->firstOrFail();
        Technique::factory()->create(['type' => Scope::where('name', 'framework')->value('id'), 'title' => 'Alpha']);
        $about = ['about_techniques' => '[alpha]'];

        $oldUpdated = self::article('updated', '更新的文章', "## 舊的一節 {#s1}\n\n舊版。\n", $about);
        $imported = $this->publishSourceFiles(['updated.md' => $oldUpdated]);
        $created = self::article('created', '新增的文章', "## 第一節 {#s1}\n\n新增的內文。\n", $about);
        $updated = self::article('updated', '更新的文章', "## 新的一節 {#s1}\n\n新版。\n", $about);
        $linked = self::article('linked', '既有文章', "來源的版本。\n");
        $commit = $this->publishSourceFiles(['created.md' => $created, 'updated.md' => $updated, 'linked.md' => $linked]);

        // 已連結、站上改過內文（conflict），要覆蓋
        $target = Documentation::factory()->create(['type' => $post->id, 'title' => '更新的文章', 'body' => "## 舊的一節 {#s1}\n\n舊版。\n"]);
        $target->forceFill([
            'source_repo' => $this->sourceRepo, 'source_key' => 'updated', 'source_path' => 'updated.md',
            'source_blob_sha' => self::blobSha($oldUpdated), 'source_commit_sha' => $imported,
            'source_body_hash' => ArticleSourceService::importedContentHash($target),
        ])->save();
        $target->forceFill(['body' => "## 舊的一節 {#s1}\n\n站上改過。\n"])->save();
        // 還沒連結、標題相同（possible_match），要連結
        $legacy = Documentation::factory()->create(['type' => $post->id, 'title' => '既有文章', 'body' => "站上的版本。\n"]);
        $this->actingAsOwner();

        $listing = collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->keyBy('key');
        $this->assertSame(['created' => 'new', 'linked' => 'possible_match', 'updated' => 'conflict'], $listing->pluck('status', 'key')->sortKeys()->all());
        $batch = [
            ['key' => 'created', 'expected_blob_sha' => self::blobSha($created), 'action' => 'create'],
            ['key' => 'updated', 'expected_blob_sha' => self::blobSha($updated), 'action' => 'update', 'overwrite_site_edits' => true, 'expected_site_version' => $listing['updated']['site_version']],
            ['key' => 'linked', 'expected_blob_sha' => self::blobSha($linked), 'action' => 'link', 'documentation_id' => $legacy->id],
        ];

        $this->captureLogs();
        $first = $this->import($batch, $commit)->assertOk();
        // 匯入兩篇、連結一篇各一筆，加上建 about 連結時的稽核紀錄
        $actions = array_map(fn ($event) => $event->context['action'], $this->securityEvents('authz_admin'));
        $this->assertSame(['import' => 2, 'link' => 1], array_intersect_key(array_count_values($actions), ['import' => 0, 'link' => 0]));
        $createdId = $first->json('data.0.documentation_id');
        $ids = [$createdId, $target->id, $legacy->id];
        $before = $this->snapshot($ids);
        // 前提：第一次真的寫了段落單位與 about 連結，後面「沒變」才有意義
        $this->assertGreaterThan(0, $before['article_units']);
        $this->assertSame(2, $before['about_links']);

        // 時間往後推：再寫一次的話 updated_at、source_imported_at 一定會變
        $this->travel(5)->seconds();
        $this->captureLogs();
        $second = $this->import($batch, $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'conflict');

        $this->assertSame(['items.0.action', 'items.1.action', 'items.2.action'], array_keys($second->json('errors')));
        $second->assertJsonMissingPath('unverified_commits');
        $this->assertSame($before, $this->snapshot($ids));
        $this->assertNoSecurityEvent('authz_admin');
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function snapshot(array $ids): array
    {
        return [
            'documentations' => Documentation::withTrashed()->count(),
            'rows' => Documentation::withTrashed()->whereIn('id', $ids)->orderBy('id')->get()
                ->map(fn (Documentation $row) => ['updated_at' => $row->updated_at?->format('Y-m-d H:i:s')] + $row->only(['id', 'title', 'body', 'source_key', 'source_blob_sha', 'source_commit_sha', 'source_imported_at', 'source_body_hash']))
                ->all(),
            'article_units' => ArticleUnit::count(),
            'units' => ArticleUnit::orderBy('id')->get()->toArray(),
            'about_links' => DB::table('documentation_technique')->count() + DB::table('documentation_implementation')->count(),
        ];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function import(array $items, string $commit): TestResponse
    {
        return $this->postJson('/api/article-sources/import', ['commit_sha' => $commit, 'items' => $items]);
    }
}
