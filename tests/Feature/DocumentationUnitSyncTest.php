<?php

namespace Tests\Feature;

use App\Models\ArticleUnit;
use App\Models\Documentation;
use App\Models\Scope;
use App\Services\ArticleUnitSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * 後台新增、修改文章（`POST`／`PUT /api/documentations`）時，內文會跟匯入一樣同步成段落單位（`article_units`）。
 * 原稿用隨專案附帶的真實文章（`database/seeders/fixtures/articles/`）加上 `{#id}`。
 */
class DocumentationUnitSyncTest extends TestCase
{
    use FakesArticleSourceRepo;
    use RefreshDatabase;

    private Scope $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = Scope::factory()->create(['name' => 'post']);
        $this->actingAsOwner();
    }

    #[TestDox('新增文章：內文切成段落單位，跟匯入同一篇的結果一致')]
    public function test_create_article_stores_units_like_an_import(): void
    {
        $body = self::withIds(self::fixture('homepage-graph-visualization-design.md'));

        $id = $this->postJson('/api/documentations', ['type' => $this->post->id, 'title' => '首頁', 'body' => $body])
            ->assertCreated()->json('data.id');

        $expected = (new ArticleUnitSplitter)->split($body)['units'];
        $rows = ArticleUnit::with('parent')->where('article_id', $id)->orderBy('position')->get();
        $this->assertGreaterThan(10, $rows->count());
        $this->assertSame(array_column($expected, 'unit_id'), $rows->pluck('unit_id')->all());
        $this->assertSame(array_column($expected, 'parent_id'), $rows->map(fn (ArticleUnit $row) => $row->parent?->unit_id)->all());
        $this->assertContains('lead', $rows->pluck('unit_id')->all());

        // 同一份原稿走匯入，單位的 ID、父單位、內容雜湊都一樣
        $this->configureArticleSource();
        $this->fakeGitHub();
        $content = self::article('graph', '首頁', $body);
        $commit = $this->publishSourceFiles(['graph.md' => $content]);
        $imported = $this->postJson('/api/article-sources/import', [
            'commit_sha' => $commit,
            'items' => [['key' => 'graph', 'expected_blob_sha' => self::blobSha($content), 'action' => 'create']],
        ])->assertOk()->json('data.0.documentation_id');
        $this->assertNotSame($id, $imported);
        $this->assertSame(self::snapshot($id), self::snapshot($imported));
    }

    #[TestDox('修改內文：單位跟著更新，ID 不變，刪掉的一節標成移除')]
    public function test_update_body_syncs_units(): void
    {
        $body = self::withIds(self::fixture('cloudflare-workers-spa-deployment-guide.md'));
        $id = $this->postJson('/api/documentations', ['type' => $this->post->id, 'title' => 'CF', 'body' => $body])->assertCreated()->json('data.id');
        $before = ArticleUnit::where('article_id', $id)->get()->keyBy('unit_id');

        $edited = preg_replace('/^## 二、Cloudflare Pages 現在其實是 Workers \+ Static Assets( \{#s2\})$/mu', '## 二、Pages 其實就是 Workers$1', $body, 1, $renamed);
        $edited = preg_replace('/^## 八、部署完怎麼找到網址 \{#s8\}\n.*?(?=^## 九)/msu', '', $edited, 1, $dropped);
        $this->assertSame([1, 1], [$renamed, $dropped]);

        $this->putJson("/api/documentations/{$id}", ['type' => $this->post->id, 'title' => 'CF', 'body' => $edited])->assertOk();

        $after = ArticleUnit::where('article_id', $id)->get()->keyBy('unit_id');
        $this->assertSame($before->keys()->sort()->values()->all(), $after->keys()->sort()->values()->all());
        $this->assertSame($before['s2']->id, $after['s2']->id);
        $this->assertSame('二、Pages 其實就是 Workers', $after['s2']->heading);
        $this->assertNotSame($before['s2']->content_hash, $after['s2']->content_hash);
        $this->assertSame($before['s3']->content_hash, $after['s3']->content_hash);
        $this->assertSame(['s8'], $after->whereNotNull('removed_at')->keys()->all());
    }

    #[TestDox('只改標題、內文沒變：單位不動（不重切）')]
    public function test_update_without_body_change_leaves_units_alone(): void
    {
        $body = "## A {#a}\n甲\n";
        $id = $this->postJson('/api/documentations', ['type' => $this->post->id, 'title' => '舊標題', 'body' => $body])->assertCreated()->json('data.id');
        // 在單位上做記號：真的重切的話，內容會被寫回成原稿的內容
        ArticleUnit::where('article_id', $id)->update(['content' => '記號']);

        // 前端編輯頁會把整份表單（含沒改的內文）一起送
        $this->putJson("/api/documentations/{$id}", ['type' => $this->post->id, 'title' => '新標題', 'body' => $body])->assertOk();

        $this->assertSame('新標題', Documentation::find($id)->title);
        $this->assertSame(['記號'], ArticleUnit::where('article_id', $id)->where('unit_id', 'a')->pluck('content')->all());
    }

    #[TestDox('原稿的 {#id} 重複：新增回 422，文章與單位都沒寫入')]
    public function test_create_with_duplicate_unit_ids_returns_422(): void
    {
        $this->postJson('/api/documentations', ['type' => $this->post->id, 'title' => '文章', 'body' => "## A {#x}\n甲\n\n## B {#x}\n乙\n"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body')
            ->assertJsonPath('errors.body.0', fn (string $message) => str_starts_with($message, '標題行尾的 {#id} 有重複') && str_contains($message, '重複的 ID：x'));

        $this->assertSame(0, Documentation::count());
        $this->assertSame(0, ArticleUnit::count());
    }

    #[TestDox('原稿的 {#id} 重複（含跟導言的 lead 重複）：修改回 422，內文與原本的單位都不動')]
    public function test_update_with_duplicate_unit_ids_returns_422_and_rolls_back(): void
    {
        $id = $this->postJson('/api/documentations', ['type' => $this->post->id, 'title' => '文章', 'body' => "## A {#a}\n甲\n"])->assertCreated()->json('data.id');
        $unitsBefore = self::snapshot($id);

        foreach (["## A {#a}\n甲\n\n## B {#a}\n乙\n", "## A {#lead}\n甲\n"] as $bad) {
            $this->putJson("/api/documentations/{$id}", ['type' => $this->post->id, 'title' => '改過的標題', 'body' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('body');
        }

        $document = Documentation::find($id);
        $this->assertSame('文章', $document->title);
        $this->assertSame("## A {#a}\n甲\n", $document->body);
        $this->assertSame($unitsBefore, self::snapshot($id));
    }

    #[TestDox('{#id} 超過 255 字元：回 422')]
    public function test_create_with_too_long_unit_id_returns_422(): void
    {
        $this->postJson('/api/documentations', ['type' => $this->post->id, 'title' => '文章', 'body' => '## A {#'.str_repeat('a', 256)."}\n甲\n"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, Documentation::count());
    }

    #[TestDox('不是文章（分類不是 post）：新增、修改都不產生單位，原稿的 {#id} 重複也不擋')]
    public function test_non_article_documents_get_no_units(): void
    {
        $other = Scope::factory()->create(['name' => 'document']);
        $body = "## A {#x}\n甲\n\n## B {#x}\n乙\n";

        $id = $this->postJson('/api/documentations', ['type' => $other->id, 'title' => '規格書', 'body' => $body])->assertCreated()->json('data.id');
        $this->putJson("/api/documentations/{$id}", ['type' => $other->id, 'title' => '規格書', 'body' => $body."\n## C\n丙\n"])->assertOk();

        $this->assertSame(0, ArticleUnit::count());
    }

    #[TestDox('分類改成文章：內文沒變也切一次；內文清空：標題單位標成移除，導言 lead 固定留著（跟匯入一樣）')]
    public function test_type_change_and_body_removal(): void
    {
        $other = Scope::factory()->create(['name' => 'document']);
        $body = "導言\n\n## A {#a}\n甲\n";
        $id = $this->postJson('/api/documentations', ['type' => $other->id, 'title' => '文件', 'body' => $body])->assertCreated()->json('data.id');
        $this->assertSame(0, ArticleUnit::count());

        $this->putJson("/api/documentations/{$id}", ['type' => $this->post->id, 'title' => '文件', 'body' => $body])->assertOk();
        $this->assertSame(['lead', 'a'], ArticleUnit::where('article_id', $id)->orderBy('position')->pluck('unit_id')->all());

        $this->putJson("/api/documentations/{$id}", ['type' => $this->post->id, 'title' => '文件', 'body' => null])->assertOk();
        $this->assertSame(['lead'], ArticleUnit::where('article_id', $id)->whereNull('removed_at')->pluck('unit_id')->all());
        $this->assertSame(['a'], ArticleUnit::where('article_id', $id)->whereNotNull('removed_at')->pluck('unit_id')->all());
    }

    #[TestDox('文章沒有內文也能新增（body 選填）：只有固定的導言單位 lead')]
    public function test_create_article_without_body(): void
    {
        $id = $this->postJson('/api/documentations', ['type' => $this->post->id, 'title' => '空'])->assertCreated()->json('data.id');

        $this->assertSame(['lead'], ArticleUnit::where('article_id', $id)->pluck('unit_id')->all());
    }

    /**
     * 單位的 ID、父單位、角色、內容雜湊（拿來比兩篇文章的單位是不是一樣）。
     *
     * @return list<array<string, mixed>>
     */
    private static function snapshot(int $articleId): array
    {
        return ArticleUnit::with('parent')->where('article_id', $articleId)->orderBy('position')->get()
            ->map(fn (ArticleUnit $row) => [
                'unit_id' => $row->unit_id,
                'parent' => $row->parent?->unit_id,
                'role' => $row->role,
                'content_hash' => $row->content_hash,
                'removed' => $row->removed_at !== null,
            ])->all();
    }

    private static function fixture(string $file): string
    {
        return (string) file_get_contents(database_path('seeders/fixtures/articles/'.$file));
    }

    /** 每個 H2、H3 標題行尾補上 `{#s1}`、`{#s2}`…（程式碼區塊裡的不動）。 */
    private static function withIds(string $body): string
    {
        $lines = explode("\n", $body);
        foreach (array_slice((new ArticleUnitSplitter)->split($body)['units'], 1) as $n => $unit) {
            $lines[$unit['start_line'] - 2] .= ' {#s'.($n + 1).'}';
        }

        return implode("\n", $lines);
    }
}
