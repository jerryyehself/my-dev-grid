<?php

namespace Tests\Feature;

use App\Models\ArticleUnit;
use App\Models\Documentation;
use App\Services\ArticleUnitSplitter;
use App\Services\ArticleUnitSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 段落單位寫進 `article_units`：第一次寫入、重新寫入時依 ID → 父單位＋標題 → 內容相似度對上同一個單位、
 * 沒出現的標成移除。輸入用隨專案附帶的真實文章原稿（`database/seeders/fixtures/articles/`）。
 */
class ArticleUnitSyncTest extends TestCase
{
    use RefreshDatabase;

    /** 有 H3 的真實文章，以及只有 H2 的一篇。 */
    private const FIXTURES = [
        'frontend-build-tooling-qa.md',
        'homepage-graph-visualization-design.md',
        'cloudflare-workers-spa-deployment-guide.md',
        'token-saving-mechanisms-inventory.md',
    ];

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (self::FIXTURES as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('fixtures')]
    #[TestDox('真實文章寫入後，每個單位的 ID、父單位、角色、標題與內容都跟原稿一致')]
    public function test_units_of_a_real_article_match_the_source(string $file): void
    {
        $body = self::withIds(self::fixture($file));
        $article = Documentation::factory()->create(['body' => $body]);

        $this->sync($article);

        $expected = (new ArticleUnitSplitter)->split($body)['units'];
        $rows = $this->activeUnits($article);
        $this->assertCount(count($expected), $rows);
        $this->assertGreaterThan(3, count($rows));
        foreach ($expected as $index => $unit) {
            $row = $rows[$index];
            $this->assertSame($unit['unit_id'], $row->unit_id);
            $this->assertSame($unit['parent_id'], $row->parent?->unit_id);
            $this->assertSame($unit['role'], $row->role);
            $this->assertSame($unit['level'], $row->level);
            $this->assertSame($unit['heading'], $row->heading);
            $this->assertSame($unit['content'], $row->content);
            $this->assertSame(ArticleUnitSync::contentHash($unit['heading'], $unit['content']), $row->content_hash);
            $this->assertSame($index, $row->position);
            $this->assertNull($row->removed_at);
        }
        // 標題裡的 {#id} 不會留在 heading
        $this->assertSame(0, ArticleUnit::where('heading', 'like', '%{#%')->count());
    }

    #[TestDox('有 H3 的文章：H3 指向所屬的 H2，導言是 lead／intro')]
    public function test_h3_points_to_its_h2_in_a_real_article(): void
    {
        $article = Documentation::factory()->create(['body' => self::withIds(self::fixture('homepage-graph-visualization-design.md'))]);

        $this->sync($article);

        $rows = $this->activeUnits($article);
        $this->assertSame(['lead', ArticleUnit::ROLE_INTRO, null], [$rows[0]->unit_id, $rows[0]->role, $rows[0]->parent_id]);
        $h3 = $rows->firstWhere('heading', '4.2 地圖／群島隱喻整條被推翻');
        $this->assertSame(3, $h3->level);
        $this->assertSame('4. 討論過程的關鍵轉折（依時間順序）', $h3->parent->heading);
        $this->assertSame(2, $h3->parent->level);
    }

    #[TestDox('「來源」節與底下的 H3 角色是 references，腳註定義目前留在所在的單位')]
    public function test_references_section_of_a_real_article(): void
    {
        $body = self::fixture('cloudflare-workers-spa-deployment-guide.md');
        // 在真實文章的一節加一個腳註引用，文末接上「來源」節與腳註定義
        $body = preg_replace('/^(## 一、為什麼選 Cloudflare（而不是 Vercel）\n)/mu', "$1\n選型的比較來源見文末[^1]。\n", $body, 1);
        $body .= "\n## 來源\n\n- Cloudflare Workers 文件\n\n### 官方文件\n\n- Static Assets\n\n[^1]: 見「來源」節。\n";
        $article = Documentation::factory()->create(['body' => self::withIds($body)]);

        $this->sync($article);

        $rows = $this->activeUnits($article);
        $sources = $rows->firstWhere('heading', '來源');
        $official = $rows->firstWhere('heading', '官方文件');
        $this->assertSame(ArticleUnit::ROLE_REFERENCES, $sources->role);
        $this->assertSame(ArticleUnit::ROLE_REFERENCES, $official->role);
        $this->assertSame($sources->id, $official->parent_id);
        $this->assertSame(ArticleUnit::ROLE_BODY, $rows->firstWhere('heading', '一、為什麼選 Cloudflare（而不是 Vercel）')->role);
        // 腳註歸給引用它的單位還沒做：定義跟著原稿位置，落在最後一個單位
        $this->assertStringContainsString('[^1]: 見「來源」節。', $official->content);
    }

    #[TestDox('改一個單位的副標或內容再寫入：ID 與列不變，只有那一個單位的雜湊更新')]
    public function test_changing_a_heading_or_content_keeps_the_id_and_updates_the_hash(): void
    {
        $body = self::withIds(self::fixture('frontend-build-tooling-qa.md'));
        $article = Documentation::factory()->create(['body' => $body]);
        $this->sync($article);
        $before = $this->activeUnits($article)->keyBy('unit_id');

        // 副標改寫（ID 不動），另一個單位改內容
        $edited = preg_replace('/^### 2\. 網域限制——這是這個專案卡住的真正原因( \{#[a-z0-9]+\})$/mu', '### 2. 網域限制：真正卡住的原因$1', $body, 1, $headingEdits);
        $edited = preg_replace('/^(## 二、前端能不能掛 GitHub Pages？ \{#[a-z0-9]+\}\n)/mu', "$1\n補一段新的說明。\n", $edited, 1, $contentEdits);
        $this->assertSame([1, 1], [$headingEdits, $contentEdits]);
        $article->forceFill(['body' => $edited])->save();
        $this->sync($article);

        $after = $this->activeUnits($article)->keyBy('unit_id');
        $this->assertSame($before->keys()->all(), $after->keys()->all());
        $changed = [];
        foreach ($before as $id => $row) {
            $this->assertSame($row->id, $after[$id]->id);
            if ($row->content_hash !== $after[$id]->content_hash) {
                $changed[] = $after[$id]->heading;
            }
        }
        $this->assertSame(['2. 網域限制：真正卡住的原因', '二、前端能不能掛 GitHub Pages？'], $changed);
        $this->assertSame(0, ArticleUnit::whereNotNull('removed_at')->count());
    }

    #[TestDox('刪掉一節（含底下的 H3）再寫入：那幾個單位標成移除，列與 ID 都還在')]
    public function test_removed_section_is_marked_not_deleted(): void
    {
        $body = self::withIds(self::fixture('homepage-graph-visualization-design.md'));
        $article = Documentation::factory()->create(['body' => $body]);
        $this->sync($article);
        $count = ArticleUnit::count();
        $section = $this->activeUnits($article)->firstWhere('heading', '4. 討論過程的關鍵轉折（依時間順序）');
        $children = ArticleUnit::where('parent_id', $section->id)->pluck('unit_id')->all();
        $this->assertCount(10, $children);

        // 拿掉整個第 4 節（到第 5 節之前）
        $edited = preg_replace('/^## 4\. 討論過程.*?(?=^## 5\. )/msu', '', $body, 1, $removed);
        $this->assertSame(1, $removed);
        $this->travel(5)->minutes();
        $article->forceFill(['body' => $edited])->save();
        $this->sync($article);

        $this->assertSame($count, ArticleUnit::count());
        $gone = ArticleUnit::whereNotNull('removed_at')->orderBy('id')->pluck('unit_id')->all();
        $this->assertSame([$section->unit_id, ...$children], $gone);
        $this->assertSame(now()->format('Y-m-d H:i:s'), ArticleUnit::where('unit_id', $section->unit_id)->value('removed_at')?->format('Y-m-d H:i:s'));
        // 剩下的單位照新原稿重新排序
        $active = $this->activeUnits($article);
        $this->assertSame(range(0, $active->count() - 1), $active->pluck('position')->all());
        $this->assertSame('5. 相關文獻', $active->firstWhere('position', 4)->heading);

        // 再寫入一次，移除時間不會被改
        $this->travel(5)->minutes();
        $this->sync($article);
        $this->assertSame(now()->subMinutes(5)->format('Y-m-d H:i:s'), ArticleUnit::where('unit_id', $section->unit_id)->value('removed_at')?->format('Y-m-d H:i:s'));
    }

    #[TestDox('移除的單位 ID 回到原稿時復原成同一列')]
    public function test_removed_unit_is_restored_when_its_id_returns(): void
    {
        $article = Documentation::factory()->create(['body' => "## A {#a}\n甲\n## B {#b}\n乙\n"]);
        $this->sync($article);
        $b = ArticleUnit::where('unit_id', 'b')->value('id');

        $article->forceFill(['body' => "## A {#a}\n甲\n"])->save();
        $this->sync($article);
        $this->assertNotNull(ArticleUnit::find($b)->removed_at);

        $article->forceFill(['body' => "## B 改名 {#b}\n乙\n## A {#a}\n甲\n"])->save();
        $this->sync($article);

        $restored = ArticleUnit::find($b);
        $this->assertNull($restored->removed_at);
        $this->assertSame([1, 'B 改名'], [$restored->position, $restored->heading]);
        $this->assertSame(3, ArticleUnit::count());
    }

    #[TestDox('沒有 ID 的單位：父單位與標題相同就是同一列，標題空白與大小寫不同也算')]
    public function test_units_without_ids_match_by_parent_and_heading(): void
    {
        $body = self::fixture('frontend-build-tooling-qa.md');
        $article = Documentation::factory()->create(['body' => $body]);
        $this->sync($article);
        $before = $this->activeUnits($article);
        $this->assertSame(['lead'], $before->pluck('unit_id')->filter()->values()->all());

        // 每一節的內容整段換掉（相似度幫不上忙），標題照舊，其中一個只改空白與大小寫
        $units = (new ArticleUnitSplitter)->split($body)['units'];
        $edited = $units[0]['content'];
        foreach (array_slice($units, 1) as $n => $unit) {
            $heading = $unit['heading'] === '四、Next.js／Nuxt.js 的定位' ? '  四、NEXT.JS／NUXT.JS   的定位' : $unit['heading'];
            $edited .= "\n".str_repeat('#', $unit['level']).' '.$heading."\n第 {$n} 節改寫後的內容。\n";
        }
        $this->assertStringContainsString('NUXT.JS', $edited);
        $article->forceFill(['body' => $edited])->save();
        $this->sync($article);

        $after = $this->activeUnits($article);
        $this->assertSame($before->pluck('id')->all(), $after->pluck('id')->all());
        $this->assertSame($before->pluck('parent_id')->all(), $after->pluck('parent_id')->all());
        $this->assertSame(0, ArticleUnit::whereNotNull('removed_at')->count());
    }

    #[TestDox('同名的 H3 在不同 H2 底下，各自對上自己父單位底下的那一列')]
    public function test_same_heading_under_different_parents_matches_by_parent(): void
    {
        $article = Documentation::factory()->create(['body' => "## A\n### 範例\n甲\n## B\n### 範例\n乙\n"]);
        $this->sync($article);
        $rows = $this->activeUnits($article);
        [$aExample, $bExample] = [$rows[2]->id, $rows[4]->id];

        // B 移到 A 前面
        $article->forceFill(['body' => "## B\n### 範例\n乙改\n## A\n### 範例\n甲改\n"])->save();
        $this->sync($article);

        $rows = $this->activeUnits($article);
        $this->assertSame(['B', '範例', 'A', '範例'], $rows->slice(1)->pluck('heading')->values()->all());
        $this->assertSame([$bExample, $aExample], [$rows[2]->id, $rows[4]->id]);
    }

    #[TestDox('原稿第一次補寫 {#id}：沿用原本的列，補上 ID')]
    public function test_first_backfill_of_ids_keeps_the_rows(): void
    {
        $body = self::fixture('homepage-graph-visualization-design.md');
        $article = Documentation::factory()->create(['body' => $body]);
        $this->sync($article);
        $ids = $this->activeUnits($article)->pluck('id')->all();

        $withIds = self::withIds($body);
        $article->forceFill(['body' => $withIds])->save();
        $this->sync($article);

        $after = $this->activeUnits($article);
        $this->assertSame($ids, $after->pluck('id')->all());
        $this->assertSame(array_column((new ArticleUnitSplitter)->split($withIds)['units'], 'unit_id'), $after->pluck('unit_id')->all());
        $this->assertSame(0, ArticleUnit::whereNotNull('removed_at')->count());
    }

    #[TestDox('沒有 ID、標題也改了：內容夠像就是同一列，不像就移除舊的、另建新的')]
    public function test_units_without_ids_fall_back_to_content_similarity(): void
    {
        $paragraph = '這一段說明部署到正式環境之前，要先確認資料庫的備份已經開啟，而且最近一次備份成功。';
        $other = '另外一段完全不相干的內容，講的是前端的字型與排版，跟部署沒有關係，也沒有提到資料庫。';
        $article = Documentation::factory()->create(['body' => "## 部署前檢查\n{$paragraph}\n## 雜項\n{$other}\n"]);
        $this->sync($article);
        $rows = $this->activeUnits($article);
        [$check, $misc] = [$rows[1]->id, $rows[2]->id];

        // 第一節換標題、內容只改一個字；第二節換標題也整段改寫
        $article->forceFill(['body' => "## 上線前要做的事\n".str_replace('開啟', '打開', $paragraph)."\n## 排版\n全新的一段文字，談的是圖表的顏色與可讀性，和原本那節沒有共同的句子。\n"])->save();
        $this->sync($article);

        $rows = $this->activeUnits($article);
        $this->assertSame($check, $rows[1]->id);
        $this->assertSame('上線前要做的事', $rows[1]->heading);
        $this->assertNotSame($misc, $rows[2]->id);
        $this->assertNotNull(ArticleUnit::find($misc)->removed_at);
    }

    #[TestDox('H2 換了標題、靠內容相似度對上之後，底下標題沒變的 H3 也對上原本的列')]
    public function test_children_match_after_their_parent_matched_by_similarity(): void
    {
        $paragraph = '這一節整理部署流程裡每一步要確認的事，包括資料庫備份、環境變數與上線後的日誌檢查。';
        $article = Documentation::factory()->create(['body' => "## 部署\n{$paragraph}\n### 備註\n短\n"]);
        $this->sync($article);
        $rows = $this->activeUnits($article);
        [$section, $note] = [$rows[1]->id, $rows[2]->id];

        $article->forceFill(['body' => "## 上線流程\n{$paragraph}\n### 備註\n短\n"])->save();
        $this->sync($article);

        $rows = $this->activeUnits($article);
        $this->assertSame([$section, $note], [$rows[1]->id, $rows[2]->id]);
        $this->assertSame($section, $rows[2]->parent_id);
        $this->assertSame(0, ArticleUnit::whereNotNull('removed_at')->count());
    }

    /** @return iterable<string, array{string, string}> */
    public static function shortContents(): iterable
    {
        // 21 個字跟它的前 19 個字：Dice = 36 / 38，沒有長度門檻的話會被當成同一個
        $long = implode('', array_map(fn (int $i) => mb_chr(0x4E00 + $i), range(0, 20)));
        $short = mb_substr($long, 0, 19);

        yield '新的內容太短' => [$long, $short];
        yield '舊的內容太短' => [$short, $long];
    }

    #[DataProvider('shortContents')]
    #[TestDox('任一邊內容少於 20 字時不靠相似度對上：換了標題的短節是新的一列')]
    public function test_short_units_are_not_matched_by_similarity(string $before, string $after): void
    {
        $this->assertGreaterThan(ArticleUnitSync::SIMILARITY_THRESHOLD, ArticleUnitSync::similarity($before, $after));
        $article = Documentation::factory()->create(['body' => "## 其他取捨\n{$before}\n"]);
        $this->sync($article);
        $old = $this->activeUnits($article)[1]->id;

        $article->forceFill(['body' => "## 其餘的取捨\n{$after}\n"])->save();
        $this->sync($article);

        $this->assertNotSame($old, $this->activeUnits($article)[1]->id);
        $this->assertNotNull(ArticleUnit::find($old)->removed_at);
    }

    #[TestDox('相似度剛好等於門檻算同一個；兩個候選一樣像時取排在前面的')]
    public function test_similarity_threshold_is_inclusive_and_ties_go_to_the_earlier_unit(): void
    {
        // 21 個不同的字（20 組二元組）；新內容保留前 17 個字、換掉後 4 個：共有 16 組，Dice = 32 / 40 = 0.8
        $chars = array_map(fn (int $i) => mb_chr(0x4E00 + $i), range(0, 20));
        $old = implode('', $chars);
        $new = implode('', array_slice($chars, 0, 17)).'甲乙丙丁';
        $this->assertSame(ArticleUnitSync::SIMILARITY_THRESHOLD, ArticleUnitSync::similarity($old, $new));

        $article = Documentation::factory()->create(['body' => "## 第一份\n{$old}\n## 第二份\n{$old}\n"]);
        $this->sync($article);
        $rows = $this->activeUnits($article);
        [$first, $second] = [$rows[1]->id, $rows[2]->id];

        $article->forceFill(['body' => "## 新標題\n{$new}\n"])->save();
        $this->sync($article);

        $this->assertSame($first, $this->activeUnits($article)[1]->id);
        $this->assertNotNull(ArticleUnit::find($second)->removed_at);
    }

    #[TestDox('有 ID 的單位拿掉 {#id} 時不會被當成同一列：舊的標成移除，ID 不會被清掉')]
    public function test_dropping_an_id_does_not_rematch_by_heading(): void
    {
        $article = Documentation::factory()->create(['body' => "## A {#a}\n甲甲甲\n"]);
        $this->sync($article);
        $a = ArticleUnit::where('unit_id', 'a')->value('id');

        $article->forceFill(['body' => "## A\n甲甲甲\n"])->save();
        $this->sync($article);

        $old = ArticleUnit::find($a);
        $this->assertSame('a', $old->unit_id);
        $this->assertNotNull($old->removed_at);
        $this->assertNull($this->activeUnits($article)[1]->unit_id);
    }

    #[TestDox('內容沒變時重新寫入不改任何列')]
    public function test_unchanged_body_touches_nothing(): void
    {
        $article = Documentation::factory()->create(['body' => self::withIds(self::fixture('token-saving-mechanisms-inventory.md'))]);
        $this->sync($article);
        $before = ArticleUnit::orderBy('id')->get()->map->only(['id', 'unit_id', 'content_hash', 'position', 'updated_at'])->all();

        $this->travel(1)->hours();
        $this->sync($article);

        $this->assertEquals($before, ArticleUnit::orderBy('id')->get()->map->only(['id', 'unit_id', 'content_hash', 'position', 'updated_at'])->all());
    }

    #[TestDox('不同文章的單位互不影響，同一個 ID 可以各用一次')]
    public function test_articles_do_not_share_units(): void
    {
        $first = Documentation::factory()->create(['body' => "## A {#a}\n甲\n"]);
        $second = Documentation::factory()->create(['body' => "## A {#a}\n乙\n"]);
        $this->sync($first);
        $this->sync($second);

        $second->forceFill(['body' => "沒有標題了\n"])->save();
        $this->sync($second);

        $this->assertNull(ArticleUnit::where('article_id', $first->id)->where('unit_id', 'a')->value('removed_at'));
        $this->assertNotNull(ArticleUnit::where('article_id', $second->id)->where('unit_id', 'a')->value('removed_at'));
    }

    /** @return iterable<string, array{string}> */
    public static function unstorableBodies(): iterable
    {
        yield '兩個標題同一個 ID' => ["## A {#a}\n## B {#a}\n"];
        yield '標題用了導言的 lead' => ["前言\n## A {#lead}\n"];
        yield 'ID 超過 255 字元' => ['## A {#'.str_repeat('a', 256)."}\n"];
    }

    #[DataProvider('unstorableBodies')]
    #[TestDox('ID 重複或太長時丟例外，什麼都不寫，也不替作者改名')]
    public function test_unstorable_ids_are_rejected(string $body): void
    {
        $article = Documentation::factory()->create(['body' => $body]);

        try {
            $this->sync($article);
            $this->fail('應該丟出例外');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, ArticleUnit::count());
    }

    #[TestDox('相似度：相同是 1、完全不同是 0，中文逐字比、空白不算')]
    public function test_similarity(): void
    {
        $this->assertSame(1.0, ArticleUnitSync::similarity('部署 前 檢查', "部署前\n檢查"));
        $this->assertSame(0.0, ArticleUnitSync::similarity('甲乙丙', '丁戊己'));
        $this->assertSame(0.0, ArticleUnitSync::similarity('', ''));
        // 甲乙、乙丙 對 甲乙、乙丁：共有 1 組，2*1/(2+2)
        $this->assertSame(0.5, ArticleUnitSync::similarity('甲乙丙', '甲乙丁'));
    }

    private function sync(Documentation $article): void
    {
        (new ArticleUnitSync)->sync($article->refresh());
    }

    /** @return Collection<int, ArticleUnit> 依位置排好、還在的單位 */
    private function activeUnits(Documentation $article)
    {
        return ArticleUnit::with('parent')->where('article_id', $article->id)->whereNull('removed_at')->orderBy('position')->get();
    }

    private static function fixture(string $file): string
    {
        return (string) file_get_contents(database_path('seeders/fixtures/articles/'.$file));
    }

    /** 每個 H2、H3 標題行尾補上 `{#s1}`、`{#s2}`…（照補寫腳本會做的樣子；程式碼區塊裡的不動）。 */
    private static function withIds(string $body): string
    {
        $lines = explode("\n", $body);
        foreach (array_slice((new ArticleUnitSplitter)->split($body)['units'], 1) as $n => $unit) {
            $lines[$unit['start_line'] - 2] .= ' {#s'.($n + 1).'}';
        }

        return implode("\n", $lines);
    }
}
