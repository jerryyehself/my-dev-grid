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
            $this->assertSame(ArticleUnitSync::contentHash($unit['heading'], $unit['content'], array_column($unit['footnotes'], 'content')), $row->content_hash);
            $this->assertSame($unit['is_container'], $row->is_container);
            $this->assertNull($row->footnotes);
            $this->assertSame($unit['chunks'], $row->chunks);
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

    #[TestDox('「來源」節與底下的 H3 角色是 references；文末的腳註定義存到引用它的單位')]
    public function test_references_section_of_a_real_article(): void
    {
        $article = Documentation::factory()->create(['body' => self::withIds(self::withFootnotes(self::fixture('cloudflare-workers-spa-deployment-guide.md')))]);

        $this->sync($article);

        $rows = $this->activeUnits($article);
        $sources = $rows->firstWhere('heading', '來源');
        $official = $rows->firstWhere('heading', '官方文件');
        $this->assertSame(ArticleUnit::ROLE_REFERENCES, $sources->role);
        $this->assertSame(ArticleUnit::ROLE_REFERENCES, $official->role);
        $this->assertSame($sources->id, $official->parent_id);
        $this->assertSame(ArticleUnit::ROLE_BODY, $rows->firstWhere('heading', '一、為什麼選 Cloudflare（而不是 Vercel）')->role);
        // 定義從最後一個單位搬走，那一列不再有定義
        $this->assertStringNotContainsString('[^', $official->content);
        $this->assertNull($official->footnotes);
    }

    #[TestDox('真實文章的腳註：每個定義存在引用它的那一列，記下原本的行號、不記原本所在的單位；沒有腳註的列是空值')]
    public function test_footnotes_of_a_real_article_are_stored_on_the_citing_rows(): void
    {
        $body = self::withIds(self::withFootnotes(self::fixture('cloudflare-workers-spa-deployment-guide.md')));
        $article = Documentation::factory()->create(['body' => $body]);

        $this->sync($article);

        $rows = $this->activeUnits($article);
        $official = $rows->firstWhere('heading', '官方文件');
        $lines = explode("\n", $body);
        $citing = [];
        foreach ($rows as $row) {
            foreach ($row->footnotes ?? [] as $footnote) {
                $citing[$footnote['label']][] = $row->heading;
                $this->assertSame(['label', 'content', 'start_line', 'end_line'], array_keys($footnote));
                $this->assertSame($footnote['content'], implode("\n", array_slice($lines, $footnote['start_line'] - 1, $footnote['end_line'] - $footnote['start_line'] + 1)));
            }
        }
        ksort($citing);
        $this->assertSame([
            1 => ['一、為什麼選 Cloudflare（而不是 Vercel）'],
            2 => ['二、Cloudflare Pages 現在其實是 Workers + Static Assets', '三、第一次部署失敗：`_redirects` 觸發無限迴圈'],
            3 => ['三、第一次部署失敗：`_redirects` 觸發無限迴圈'],
        ], $citing);
        $this->assertSame(3, $rows->whereNotNull('footnotes')->count());
        $this->assertSame(0, ArticleUnit::where('content', 'like', '%]: 腳註%')->count());
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
        // 要跟下面的 $gone 一樣依 id 排：PostgreSQL 沒有 ORDER BY 時照實體位置回傳，列被更新過順序就會變
        $children = ArticleUnit::where('parent_id', $section->id)->orderBy('id')->pluck('unit_id')->all();
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
        $this->assertSame('B 改名', $restored->heading);
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

        // 列不存順序，各自看內容與父單位
        $this->assertSame(['乙改', 'B'], [ArticleUnit::find($bExample)->content, ArticleUnit::find($bExample)->parent->heading]);
        $this->assertSame(["甲改\n", 'A'], [ArticleUnit::find($aExample)->content, ArticleUnit::find($aExample)->parent->heading]);
        $this->assertSame(0, ArticleUnit::whereNotNull('removed_at')->count());
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

    #[TestDox('兩段相似的節同時換標題：依分數高低配對，原文不變的那節保留自己的列')]
    public function test_similarity_matches_by_highest_score_not_document_order(): void
    {
        // 41 個不同的字（40 組二元組）。B 換掉 A 的後 5 個字、改寫版換掉 A 的後 2 個字
        $chars = array_map(fn (int $i) => mb_chr(0x4E00 + $i), range(0, 40));
        $a = implode('', $chars);
        $b = implode('', array_slice($chars, 0, 36)).'甲乙丙丁戊';
        $rewrite = implode('', array_slice($chars, 0, 39)).'子丑';
        $this->assertSame(0.875, ArticleUnitSync::similarity($a, $b));
        $this->assertSame(0.95, ArticleUnitSync::similarity($rewrite, $a));
        $this->assertSame(0.875, ArticleUnitSync::similarity($rewrite, $b));

        $article = Documentation::factory()->create(['body' => "## 甲節\n{$a}\n## 乙節\n{$b}\n"]);
        $this->sync($article);
        $rows = $this->activeUnits($article);
        [$rowA, $rowB] = [$rows[1]->id, $rows[2]->id];

        // 兩節都換標題；排在前面的改寫版對 A 比對 B 像，照文件順序挑的話會搶走 A 的列
        $article->forceFill(['body' => "## 改寫\n{$rewrite}\n## 原文\n{$a}\n"])->save();
        $this->sync($article);

        $this->assertSame(['改寫', '原文'], [ArticleUnit::find($rowB)->heading, ArticleUnit::find($rowA)->heading]);
        $this->assertSame(0, ArticleUnit::whereNotNull('removed_at')->count());
    }

    #[TestDox('沒有 ID、同標題的舊列有一個已移除、一個還在：優先對上還在的那列')]
    public function test_live_rows_are_preferred_over_removed_ones(): void
    {
        $article = Documentation::factory()->create(['body' => "## A\n新內容\n"]);
        $unit = fn (?string $removedAt) => ArticleUnit::create([
            'article_id' => $article->id, 'unit_id' => null, 'role' => ArticleUnit::ROLE_BODY, 'level' => 2,
            'heading' => 'A', 'content' => "舊內容\n", 'content_hash' => ArticleUnitSync::contentHash('A', "舊內容\n"),
            'removed_at' => $removedAt,
        ]);
        // 已移除的先建（id 較小），只看建立先後的話會先對上它
        $removed = $unit('2026-11-01 00:00:00');
        $live = $unit(null);

        $this->sync($article);

        $this->assertSame([$live->id], ArticleUnit::where('heading', 'A')->whereNull('removed_at')->pluck('id')->all());
        $this->assertSame('2026-11-01 00:00:00', $removed->refresh()->removed_at->format('Y-m-d H:i:s'));
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
        $before = ArticleUnit::orderBy('id')->get()->map->only(['id', 'unit_id', 'content_hash', 'updated_at'])->all();

        $this->travel(1)->hours();
        $this->sync($article);

        $this->assertEquals($before, ArticleUnit::orderBy('id')->get()->map->only(['id', 'unit_id', 'content_hash', 'updated_at'])->all());
    }

    #[TestDox('加腳註歸屬之前寫入的列：原稿沒改時重新匯入，對上同一列、ID 不變；雜湊只有引用腳註的列與定義原本所在的列會變')]
    public function test_rows_written_before_footnote_handling_keep_ids(): void
    {
        $body = self::withIds(self::withFootnotes(self::fixture('cloudflare-workers-spa-deployment-guide.md')));
        $article = Documentation::factory()->create(['body' => $body]);
        // 照之前的寫法存：content 是原稿原文（定義還在最後一個單位），雜湊用它算，三個新欄位是預設值
        $parents = [];
        foreach ((new ArticleUnitSplitter)->split($body)['units'] as $index => $unit) {
            $parents[$index] = ArticleUnit::create([
                'article_id' => $article->id,
                'unit_id' => $unit['unit_id'],
                'parent_id' => $unit['parent_index'] === null ? null : $parents[$unit['parent_index']]->id,
                'role' => $unit['role'],
                'level' => $unit['level'],
                'heading' => $unit['heading'],
                'content' => $unit['source'],
                'content_hash' => ArticleUnitSync::contentHash($unit['heading'], $unit['source']),
            ]);
        }
        $before = ArticleUnit::orderBy('id')->get()->map->only(['id', 'unit_id', 'parent_id'])->all();
        $hashes = ArticleUnit::pluck('content_hash', 'heading');
        $this->assertStringContainsString('[^1]: 腳註一', ArticleUnit::where('heading', '官方文件')->value('content'));

        $this->sync($article);

        $this->assertSame($before, ArticleUnit::orderBy('id')->get()->map->only(['id', 'unit_id', 'parent_id'])->all());
        $changed = array_keys(array_diff_assoc(ArticleUnit::pluck('content_hash', 'heading')->all(), $hashes->all()));
        sort($changed);
        $expected = ['一、為什麼選 Cloudflare（而不是 Vercel）', '三、第一次部署失敗：`_redirects` 觸發無限迴圈', '二、Cloudflare Pages 現在其實是 Workers + Static Assets', '官方文件'];
        sort($expected);
        $this->assertSame($expected, $changed);
        $this->assertSame(0, ArticleUnit::whereNotNull('removed_at')->count());
        $this->assertStringNotContainsString('[^1]: 腳註一', ArticleUnit::where('heading', '官方文件')->value('content'));
        $this->assertSame(['1'], array_column(ArticleUnit::where('heading', '一、為什麼選 Cloudflare（而不是 Vercel）')->first()->footnotes, 'label'));
    }

    #[TestDox('有腳註、容器、子片段的文章，內容沒變時重新寫入不改任何列（JSON 欄位不會被當成有變）')]
    public function test_unchanged_body_with_footnotes_touches_nothing(): void
    {
        $long = str_repeat('長段落的文字。', 150)."[^1]\n\n".str_repeat('第二段的文字。', 150);
        $body = self::withIds(self::withFootnotes(self::fixture('cloudflare-workers-spa-deployment-guide.md')))
            ."\n## 容器 {#box}\n\n### 長節 {#long}\n{$long}\n";
        $article = Documentation::factory()->create(['body' => $body]);
        $this->sync($article);
        $this->assertTrue(ArticleUnit::where('unit_id', 'box')->value('is_container'));
        $this->assertSame(['long~1', 'long~2'], array_column(ArticleUnit::where('unit_id', 'long')->first()->chunks, 'id'));
        $columns = ['id', 'unit_id', 'content_hash', 'footnotes', 'chunks', 'is_container', 'updated_at'];
        $before = ArticleUnit::orderBy('id')->get()->map->only($columns)->all();

        $this->travel(1)->hours();
        $this->sync($article);

        $this->assertEquals($before, ArticleUnit::orderBy('id')->get()->map->only($columns)->all());
    }

    #[TestDox('改了腳註定義的文字：雜湊變的是引用它的列，定義原本所在的「來源」節不變')]
    public function test_editing_a_footnote_changes_the_citing_units_hash(): void
    {
        $body = self::withIds(self::withFootnotes(self::fixture('cloudflare-workers-spa-deployment-guide.md')));
        $article = Documentation::factory()->create(['body' => $body]);
        $this->sync($article);
        $before = ArticleUnit::pluck('content_hash', 'heading');

        $article->forceFill(['body' => str_replace('腳註一：Vercel 與 Cloudflare 的比較。', '腳註一：改寫過的比較。', $body)])->save();
        $this->sync($article);

        $after = ArticleUnit::pluck('content_hash', 'heading');
        $changed = array_keys(array_diff_assoc($after->all(), $before->all()));
        $this->assertSame(['一、為什麼選 Cloudflare（而不是 Vercel）'], $changed);
        $this->assertSame($before['來源'], $after['來源']);
        $this->assertSame($before['官方文件'], $after['官方文件']);
        $this->assertSame('[^1]: 腳註一：改寫過的比較。', ArticleUnit::where('heading', '一、為什麼選 Cloudflare（而不是 Vercel）')->first()->footnotes[0]['content']);

        // 被兩節引用的腳註改了，兩節的雜湊都變
        $article->forceFill(['body' => str_replace('腳註二：Workers 與 Pages 的關係。', '腳註二：改寫。', (string) $article->body)])->save();
        $this->sync($article);
        $changed = array_keys(array_diff_assoc(ArticleUnit::pluck('content_hash', 'heading')->all(), $after->all()));
        sort($changed);
        $expected = ['二、Cloudflare Pages 現在其實是 Workers + Static Assets', '三、第一次部署失敗：`_redirects` 觸發無限迴圈'];
        sort($expected);
        $this->assertSame($expected, $changed);
    }

    #[TestDox('雜湊：沒有腳註時等於只算標題與內容；腳註的內容、順序、跟內文的分界都會影響結果')]
    public function test_content_hash_with_footnotes(): void
    {
        $plain = ArticleUnitSync::contentHash('A', '內容');
        $this->assertSame($plain, ArticleUnitSync::contentHash('A', '內容', []));
        $this->assertNotSame($plain, ArticleUnitSync::contentHash('A', '內容', ['[^1]: x']));
        $this->assertNotSame(ArticleUnitSync::contentHash('A', '內容', ['[^1]: x', '[^2]: y']), ArticleUnitSync::contentHash('A', '內容', ['[^2]: y', '[^1]: x']));
        // 腳註併進內文不會跟分開算出同一個雜湊
        $this->assertNotSame(ArticleUnitSync::contentHash('A', "內容\n2:xy"), ArticleUnitSync::contentHash('A', '內容', ['xy']));
    }

    #[TestDox('Documentation::units()：這篇文章的所有單位，含已標成移除的，不含別篇的')]
    public function test_documentation_units_relation(): void
    {
        $article = Documentation::factory()->create(['body' => "## A {#a}\n甲\n## B {#b}\n乙\n"]);
        $other = Documentation::factory()->create(['body' => "## A {#a}\n丙\n"]);
        $this->sync($article);
        $this->sync($other);
        $article->forceFill(['body' => "## A {#a}\n甲\n"])->save();
        $this->sync($article);

        $units = $article->refresh()->units()->orderBy('id')->get();
        $this->assertSame(['lead', 'a', 'b'], $units->pluck('unit_id')->all());
        $this->assertSame([false, false, true], $units->map->isRemoved()->all());
        $this->assertSame([$article->id], $units->pluck('article_id')->unique()->values()->all());
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

    /**
     * 還在的單位，依建立的先後（列的 id）。列不存文章裡的順序：第一次寫入時建立的先後就是原稿的順序，
     * 之後改寫原稿的測試不要靠索引找列，改用 id 或 unit_id。
     *
     * @return Collection<int, ArticleUnit>
     */
    private function activeUnits(Documentation $article)
    {
        return ArticleUnit::with('parent')->where('article_id', $article->id)->whereNull('removed_at')->orderBy('id')->get();
    }

    private static function fixture(string $file): string
    {
        return (string) file_get_contents(database_path('seeders/fixtures/articles/'.$file));
    }

    /**
     * 照目前文章的寫法加上腳註：引用寫在前三節的句子裡，定義全放在文末「來源」節的最後；
     * 標籤 2 被兩節引用，標籤 2 的定義有縮排的續行。
     */
    private static function withFootnotes(string $body): string
    {
        $sections = [
            '一、為什麼選 Cloudflare（而不是 Vercel）' => "選型的比較來源見文末[^1]。\n",
            '二、Cloudflare Pages 現在其實是 Workers + Static Assets' => "官方的說法見[^2]。\n",
            '三、第一次部署失敗：`_redirects` 觸發無限迴圈' => "同樣的規則[^2]也適用，錯誤訊息見[^3]。\n",
        ];
        foreach ($sections as $heading => $sentence) {
            $body = preg_replace('/^(## '.preg_quote($heading, '/').'\n)/mu', "$1\n".$sentence, $body, 1, $count);
            if ($count !== 1) {
                throw new \RuntimeException("找不到 {$heading}");
            }
        }

        return $body."\n## 來源\n\n- Cloudflare Workers 文件\n\n### 官方文件\n\n- Static Assets\n\n"
            ."[^1]: 腳註一：Vercel 與 Cloudflare 的比較。\n[^2]: 腳註二：Workers 與 Pages 的關係。\n    續行：2025 年起合併。\n[^3]: 腳註三：錯誤訊息原文。\n";
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
