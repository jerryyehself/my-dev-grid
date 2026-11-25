<?php

namespace Tests\Feature;

use App\Models\ArticleUnit;
use App\Models\Documentation;
use App\Models\Scope;
use App\Services\ArticleUnitSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * 文章匯入時把內文的段落單位存進 `article_units`；原稿標題的 `{#id}` 重複時整篇不能匯入。
 * 原稿用隨專案附帶的真實文章（`database/seeders/fixtures/articles/`）加上 front matter 與 `{#id}`。
 */
class ArticleSourceUnitsTest extends TestCase
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

    #[TestDox('匯入真實文章後，存下的單位與 ID 跟原稿切出來的一致')]
    public function test_import_stores_units_matching_the_source(): void
    {
        $body = self::withIds(self::fixture('frontend-build-tooling-qa.md'));
        $content = self::article('build-tooling', '前端建置工具與部署概念問答整理', $body);
        $commit = $this->publishSourceFiles(['build-tooling.md' => $content]);
        $this->actingAsOwner();

        $id = $this->import([$this->item('build-tooling', $content, 'create')], $commit)->assertOk()->json('data.0.documentation_id');

        $expected = (new ArticleUnitSplitter)->split(Documentation::findOrFail($id)->body)['units'];
        $rows = ArticleUnit::with('parent')->where('article_id', $id)->orderBy('id')->get();
        $this->assertSame(array_column($expected, 'unit_id'), $rows->pluck('unit_id')->all());
        $this->assertSame(array_column($expected, 'parent_id'), $rows->map(fn (ArticleUnit $row) => $row->parent?->unit_id)->all());
        $this->assertSame(array_column($expected, 'heading'), $rows->pluck('heading')->all());
        $this->assertContains('s2', $rows->pluck('unit_id')->all());
    }

    #[TestDox('重新匯入：改副標的單位 ID 不變、雜湊更新，刪掉的一節標成移除')]
    public function test_update_import_keeps_ids_and_marks_removed_units(): void
    {
        $body = self::withIds(self::fixture('cloudflare-workers-spa-deployment-guide.md'));
        $v1 = self::article('cloudflare', 'my-dev-grid-front 部署到 Cloudflare 的完整流程', $body);
        $c1 = $this->publishSourceFiles(['cloudflare.md' => $v1]);
        $this->actingAsOwner();
        $id = $this->import([$this->item('cloudflare', $v1, 'create')], $c1)->assertOk()->json('data.0.documentation_id');
        $before = ArticleUnit::where('article_id', $id)->get()->keyBy('unit_id');

        // 第二節改副標，刪掉第八節
        $edited = preg_replace('/^## 二、Cloudflare Pages 現在其實是 Workers \+ Static Assets( \{#s2\})$/mu', '## 二、Pages 其實就是 Workers$1', $body, 1, $renamed);
        $edited = preg_replace('/^## 八、部署完怎麼找到網址 \{#s8\}\n.*?(?=^## 九)/msu', '', $edited, 1, $dropped);
        $this->assertSame([1, 1], [$renamed, $dropped]);
        $v2 = self::article('cloudflare', 'my-dev-grid-front 部署到 Cloudflare 的完整流程', $edited);
        $c2 = $this->publishSourceFiles(['cloudflare.md' => $v2]);
        $this->import([$this->item('cloudflare', $v2, 'update')], $c2)->assertOk();

        $after = ArticleUnit::where('article_id', $id)->get()->keyBy('unit_id');
        $this->assertSame($before->keys()->sort()->values()->all(), $after->keys()->sort()->values()->all());
        $this->assertSame($before['s2']->id, $after['s2']->id);
        $this->assertSame('二、Pages 其實就是 Workers', $after['s2']->heading);
        $this->assertNotSame($before['s2']->content_hash, $after['s2']->content_hash);
        $this->assertSame($before['s3']->content_hash, $after['s3']->content_hash);
        $this->assertNotNull($after['s8']->removed_at);
        $this->assertSame(['s8'], $after->whereNotNull('removed_at')->keys()->all());
    }

    #[TestDox('原稿的 {#id} 重複：清單列成 invalid 並寫出重複的 ID，匯入回 409 寫明原因，什麼都不寫')]
    public function test_duplicate_unit_ids_block_the_import(): void
    {
        $body = self::withIds(self::fixture('token-saving-mechanisms-inventory.md'));
        $body = str_replace('{#s3}', '{#s1}', $body);
        $content = self::article('inventory', '節省 Token／降變異度機制盤點', $body);
        $commit = $this->publishSourceFiles(['inventory.md' => $content]);
        $this->actingAsOwner();

        $item = $this->getJson('/api/article-sources')->assertOk()->json('items.0');
        $this->assertSame(['invalid', 'duplicate_unit_id'], [$item['status'], $item['reason']]);
        $this->assertStringContainsString('重複的 ID：s1', $item['reason_message']);

        $response = $this->import([$this->item('inventory', $content, 'create')], $commit)
            ->assertConflict()
            ->assertJsonPath('reason', 'stale');
        $message = $response->json('errors')['items.0.expected_blob_sha'][0];
        $this->assertStringStartsWith('這篇文章不能匯入：標題行尾的 {#id} 有重複', $message);
        $this->assertStringContainsString('s1', $message);
        $this->assertSame(0, Documentation::count());
        $this->assertSame(0, ArticleUnit::count());
    }

    #[TestDox('已匯入的文章新版 {#id} 重複時不能更新：內文與原本的單位都不動')]
    public function test_duplicate_unit_ids_block_an_update(): void
    {
        $body = "## A {#a}\n甲\n## B {#b}\n乙\n";
        $v1 = self::article('post', '文章', $body);
        $c1 = $this->publishSourceFiles(['post.md' => $v1]);
        $this->actingAsOwner();
        $id = $this->import([$this->item('post', $v1, 'create')], $c1)->assertOk()->json('data.0.documentation_id');
        $before = ArticleUnit::orderBy('id')->get()->toArray();

        $v2 = self::article('post', '文章', "## A {#a}\n甲\n## B {#a}\n乙\n");
        $c2 = $this->publishSourceFiles(['post.md' => $v2]);
        $this->assertSame('invalid', $this->getJson('/api/article-sources')->assertOk()->json('items.0.status'));
        $this->import([$this->item('post', $v2, 'update')], $c2)->assertConflict()->assertJsonPath('reason', 'stale');

        $this->assertSame($body, Documentation::findOrFail($id)->body);
        $this->assertSame($before, ArticleUnit::orderBy('id')->get()->toArray());
    }

    #[TestDox('{#id} 超過 255 字元的原稿列成 invalid，剛好 255 字元可以匯入')]
    public function test_overlong_unit_id_is_invalid(): void
    {
        $tooLong = self::article('too-long', '太長', '## A {#'.str_repeat('a', 256)."}\n");
        $atLimit = self::article('at-limit', '剛好', '## A {#'.str_repeat('a', 255)."}\n");
        $commit = $this->publishSourceFiles(['too-long.md' => $tooLong, 'at-limit.md' => $atLimit]);
        $this->actingAsOwner();

        $items = collect($this->getJson('/api/article-sources')->assertOk()->json('items'))->keyBy('key');
        $this->assertSame('unit_id_too_long', $items['too-long']['reason']);
        $this->assertSame('new', $items['at-limit']['status']);

        $id = $this->import([$this->item('at-limit', $atLimit, 'create')], $commit)->assertOk()->json('data.0.documentation_id');
        $this->assertSame(str_repeat('a', 255), ArticleUnit::where('article_id', $id)->where('level', 2)->value('unit_id'));
    }

    #[TestDox('slug 重複又有重複 ID 的檔案，原因是 duplicate_slug，說明不帶 ID')]
    public function test_duplicate_slug_reason_does_not_carry_unit_id_detail(): void
    {
        $this->publishSourceFiles([
            'a.md' => self::article('same', '第一篇', "## A {#x}\n## B {#x}\n"),
            'b.md' => self::article('same', '第二篇'),
        ]);
        $this->actingAsOwner();

        foreach ($this->getJson('/api/article-sources')->assertOk()->json('items') as $item) {
            $this->assertSame('duplicate_slug', $item['reason']);
            $this->assertStringNotContainsString('重複的 ID', $item['reason_message']);
        }
    }

    #[TestDox('連結（link）只寫來源欄位，不存段落單位')]
    public function test_link_does_not_store_units(): void
    {
        $content = self::article('linked', '既有文章', "## A {#a}\n甲\n");
        $commit = $this->publishSourceFiles(['linked.md' => $content]);
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'title' => '既有文章']);
        $this->actingAsOwner();

        $this->import([$this->item('linked', $content, 'link', ['documentation_id' => $documentation->id])], $commit)->assertOk();

        $this->assertSame(0, ArticleUnit::count());
    }

    #[TestDox('匯入時寫單位失敗，文章也不會寫入')]
    public function test_unit_write_failure_rolls_back_the_article(): void
    {
        $content = self::article('post', '文章', "## A {#a}\n甲\n");
        $commit = $this->publishSourceFiles(['post.md' => $content]);
        $this->actingAsOwner();
        ArticleUnit::creating(fn () => throw new RuntimeException('寫入失敗'));

        $this->withoutExceptionHandling();
        try {
            $this->import([$this->item('post', $content, 'create')], $commit);
            $this->fail('應該丟出例外');
        } catch (RuntimeException $e) {
            $this->assertSame('寫入失敗', $e->getMessage());
        }

        $this->assertSame(0, Documentation::count());
        $this->assertSame(0, ArticleUnit::count());
    }

    #[TestDox('寫單位時撞到唯一鍵：不回「同一篇文章剛被匯入過」的 409，當成程式錯誤，文章也不寫入')]
    public function test_unit_unique_violation_is_not_reported_as_a_concurrent_import(): void
    {
        $content = self::article('post', '文章', "## A {#a}\n甲\n");
        $commit = $this->publishSourceFiles(['post.md' => $content]);
        $this->actingAsOwner();
        // 模擬比對出錯：要寫入的那一列之前，同一篇文章已經有同一個 unit_id
        ArticleUnit::creating(function (ArticleUnit $unit) {
            DB::table('article_units')->insert(['article_id' => $unit->article_id, 'unit_id' => $unit->unit_id, 'role' => 'body', 'content' => '', 'content_hash' => str_repeat('0', 64)]);
        });

        $response = $this->import([$this->item('post', $content, 'create')], $commit);

        $response->assertStatus(500);
        $this->assertNotSame('conflict', $response->json('reason'));
        $this->assertStringNotContainsString('同一篇文章剛被匯入過', $response->getContent());
        $this->assertSame(0, Documentation::count());
        $this->assertSame(0, ArticleUnit::count());

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->import([$this->item('post', $content, 'create')], $commit);
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
