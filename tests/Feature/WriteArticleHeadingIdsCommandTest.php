<?php

namespace Tests\Feature;

use App\Models\ArticleUnit;
use App\Models\Documentation;
use App\Services\ArticleUnitSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * `articles:write-heading-ids`：替文章原稿的 H2、H3 補上 `{#id}`（一次性補寫，之後凍結）。
 * 補寫規則本身（哪些行、怎麼不撞號、位元組不變）在 `ArticleHeadingIdWriterTest`；這裡驗指令的外殼：
 * 讀檔、寫檔、`--dry-run`、目錄、全有或全無、結束碼，以及查資料庫裡這篇文章用過的 ID。
 */
class WriteArticleHeadingIdsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FRONT_MATTER = "---\nslug: sample\ntitle: 範例\nimport: true\n---\n";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/heading-ids-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * 照匯入的樣子建一篇文章：`source_key` 是 slug，內文依序同步過每一版（段落單位由 `ArticleUnitSync` 寫入）。
     *
     * @param  list<string>  $bodies
     */
    private function importedArticle(string $slug, array $bodies): Documentation
    {
        $article = Documentation::factory()->create(['body' => $bodies[0]]);
        $article->forceFill(['source_key' => $slug])->save();
        foreach ($bodies as $body) {
            $article->forceFill(['body' => $body])->save();
            (new ArticleUnitSync)->sync($article->refresh());
        }

        return $article;
    }

    private function makeFile(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);

        return $path;
    }

    #[TestDox('指定單一檔案：補上 ID 並寫回，印出補了幾個')]
    public function test_writes_ids_into_a_single_file(): void
    {
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 前提\n\n### 細節\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('a.md：補 2 個 ID')
            ->expectsOutputToContain('共 1 個檔案、補 2 個 ID')
            ->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 前提 {#s1}\n\n### 細節 {#s2}\n", file_get_contents($path));
    }

    #[TestDox('重跑一次：檔案沒變、補 0 個')]
    public function test_second_run_changes_nothing(): void
    {
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 前提\n");
        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);
        $afterFirst = file_get_contents($path);
        $mtime = filemtime($path);
        clearstatcache();

        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('共 1 個檔案、補 0 個 ID')
            ->assertExitCode(0);

        $this->assertSame($afterFirst, file_get_contents($path));
        $this->assertSame($mtime, filemtime($path));
    }

    #[TestDox('--dry-run：不寫檔，只印會改哪些行')]
    public function test_dry_run_prints_changes_and_writes_nothing(): void
    {
        $raw = self::FRONT_MATTER."## 前提\n\n### 細節 {#detail}\n\n## 結論\n";
        $path = $this->makeFile('a.md', $raw);

        $this->artisan('articles:write-heading-ids', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('第 6 行 H2「前提」→ {#s1}')
            ->expectsOutputToContain('第 10 行 H2「結論」→ {#s2}')
            ->expectsOutputToContain('沒有寫入')
            ->assertExitCode(0);

        $this->assertSame($raw, file_get_contents($path));
    }

    #[TestDox('指定目錄：遞迴處理所有 .md，其他副檔名不碰，沒有 front matter 的略過')]
    public function test_processes_a_directory_recursively(): void
    {
        $a = $this->makeFile('a.md', self::FRONT_MATTER."## 甲\n");
        $b = $this->makeFile('sub/b.md', self::FRONT_MATTER."## 乙\n\n## 丙\n");
        $notes = $this->makeFile('notes.txt', self::FRONT_MATTER."## 不是 md\n");
        $readme = $this->makeFile('README.md', "# 說明\n\n## 沒有 front matter\n");

        $this->artisan('articles:write-heading-ids', ['path' => $this->dir])
            ->expectsOutputToContain('略過')
            ->expectsOutputToContain('共 3 個檔案、補 3 個 ID')
            ->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n", file_get_contents($a));
        $this->assertSame(self::FRONT_MATTER."## 乙 {#s1}\n\n## 丙 {#s2}\n", file_get_contents($b));
        $this->assertSame(self::FRONT_MATTER."## 不是 md\n", file_get_contents($notes));
        $this->assertSame("# 說明\n\n## 沒有 front matter\n", file_get_contents($readme));
    }

    #[TestDox('編號每篇各自從 s1 起算')]
    public function test_numbering_restarts_per_article(): void
    {
        $a = $this->makeFile('a.md', self::FRONT_MATTER."## 甲\n");
        $b = $this->makeFile('b.md', self::FRONT_MATTER."## 乙\n");

        $this->artisan('articles:write-heading-ids', ['path' => $this->dir])->assertExitCode(0);

        $this->assertStringContainsString('{#s1}', file_get_contents($a));
        $this->assertStringContainsString('{#s1}', file_get_contents($b));
    }

    #[TestDox('有一篇不能補（ID 重複），整批都不寫，結束碼是失敗')]
    public function test_one_bad_file_blocks_every_write(): void
    {
        $good = $this->makeFile('a-good.md', self::FRONT_MATTER."## 甲\n");
        $bad = $this->makeFile('b-bad.md', self::FRONT_MATTER."## 乙 {#x}\n\n## 丙 {#x}\n\n## 丁\n");
        $badRaw = file_get_contents($bad);

        $this->artisan('articles:write-heading-ids', ['path' => $this->dir])
            ->expectsOutputToContain('b-bad.md：標題的 {#id} 有重複（x）')
            ->expectsOutputToContain('一個檔案都沒有寫')
            ->assertExitCode(1);

        $this->assertSame(self::FRONT_MATTER."## 甲\n", file_get_contents($good));
        $this->assertSame($badRaw, file_get_contents($bad));
    }

    #[TestDox('--dry-run 遇到不能補的檔案同樣回失敗')]
    public function test_dry_run_also_fails_on_a_bad_file(): void
    {
        $this->makeFile('bad.md', self::FRONT_MATTER."## 乙 {#x}\n\n## 丙 {#x}\n");

        $this->artisan('articles:write-heading-ids', ['path' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('重複')
            ->assertExitCode(1);
    }

    #[TestDox('路徑不存在回失敗')]
    public function test_missing_path_fails(): void
    {
        $this->artisan('articles:write-heading-ids', ['path' => $this->dir.'/nope'])
            ->expectsOutputToContain('找不到')
            ->assertExitCode(1);
    }

    #[TestDox('目錄裡一個 .md 都沒有也回失敗，不假裝成功')]
    public function test_directory_without_markdown_files_fails(): void
    {
        $this->makeFile('notes.txt', 'x');

        $this->artisan('articles:write-heading-ids', ['path' => $this->dir])
            ->expectsOutputToContain('沒有 .md')
            ->assertExitCode(1);
    }

    #[TestDox('寫檔保留 BOM、CRLF 與沒有結尾換行的檔案')]
    public function test_preserves_bom_crlf_and_missing_trailing_newline_on_disk(): void
    {
        $fm = str_replace("\n", "\r\n", self::FRONT_MATTER);
        $path = $this->makeFile('a.md', "\u{FEFF}".$fm."## 甲\r\n\r\n內文\r\n## 乙");

        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);

        $this->assertSame("\u{FEFF}".$fm."## 甲 {#s1}\r\n\r\n內文\r\n## 乙 {#s2}", file_get_contents($path));
    }

    #[TestDox('程式碼區塊與縮排行裡的井號不補，區塊外的標題照補')]
    public function test_does_not_touch_code_blocks_on_disk(): void
    {
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 甲\n\n```md\n## 範例\n```\n\n    ## 縮排\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n\n```md\n## 範例\n```\n\n    ## 縮排\n", file_get_contents($path));
    }

    #[TestDox('--dry-run 對沒有要補的檔案也回成功')]
    public function test_dry_run_with_nothing_to_do_succeeds(): void
    {
        $this->makeFile('a.md', self::FRONT_MATTER."## 甲 {#a}\n");

        $this->artisan('articles:write-heading-ids', ['path' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('共 1 個檔案、補 0 個 ID')
            ->assertExitCode(0);
    }

    #[TestDox('刪掉的段落的 ID 不會再發給新標題：匯入後舊的列還是「已移除」，新標題是新的一列')]
    public function test_ids_of_removed_units_are_not_reused(): void
    {
        // 匯入過兩版：第二版刪掉了「乙」（s2），資料庫裡那一列標成移除、ID 留著
        $article = $this->importedArticle('sample', ["## 甲 {#s1}\n甲的內容\n\n## 乙 {#s2}\n乙的內容\n", "## 甲 {#s1}\n甲的內容\n"]);
        $removed = ArticleUnit::where('article_id', $article->id)->where('unit_id', 's2')->first();
        $this->assertNotNull($removed->removed_at);

        // 作者之後新增一個標題，再跑指令
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 甲 {#s1}\n甲的內容\n\n## 丙\n丙的內容\n");
        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('第 9 行 H2「丙」→ {#s3}')
            ->assertExitCode(0);
        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n甲的內容\n\n## 丙 {#s3}\n丙的內容\n", file_get_contents($path));

        // 再匯入：「乙」那一列沒有被拿去接「丙」
        $article->forceFill(['body' => substr((string) file_get_contents($path), strlen(self::FRONT_MATTER))])->save();
        (new ArticleUnitSync)->sync($article->refresh());
        $removed->refresh();
        $this->assertSame(['乙', true], [$removed->heading, $removed->removed_at !== null]);
        $this->assertSame('丙', ArticleUnit::where('article_id', $article->id)->where('unit_id', 's3')->value('heading'));
    }

    /** @return array<string, array{string, bool}> */
    public static function articlesStillHoldingIds(): array
    {
        return [
            '文章已軟刪除' => [self::FRONT_MATTER, true],
            '原稿改成不匯入（import: false）' => ["---\nslug: sample\ntitle: 範例\nimport: false\n---\n", false],
        ];
    }

    #[DataProvider('articlesStillHoldingIds')]
    #[TestDox('文章軟刪除了、原稿改成不匯入，用過的 ID 照樣保留')]
    public function test_ids_stay_reserved_for_trashed_or_unimported_articles(string $frontMatter, bool $trashed): void
    {
        $article = $this->importedArticle('sample', ["## 甲 {#s1}\n"]);
        if ($trashed) {
            $article->delete();
        }
        $path = $this->makeFile('a.md', $frontMatter."## 乙\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);

        $this->assertSame($frontMatter."## 乙 {#s2}\n", file_get_contents($path));
    }

    #[TestDox('只保留同一個 slug 的文章用過的 ID：slug 只差一點的、沒有來源的文章不算')]
    public function test_only_the_article_with_the_same_slug_reserves_ids(): void
    {
        $this->importedArticle('sample-old', ["## 甲 {#s1}\n"]);
        $this->importedArticle('Sample', ["## 甲 {#s1}\n"]);
        Documentation::factory()->create(['body' => "## 甲 {#s1}\n"]);
        (new ArticleUnitSync)->sync(Documentation::whereNull('source_key')->firstOrFail());
        $this->assertSame(3, ArticleUnit::where('unit_id', 's1')->count());
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 乙\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 乙 {#s1}\n", file_get_contents($path));
    }

    #[TestDox('查不了資料庫（例如還沒 migrate）時當成失敗，一個檔案都不寫，不當成沒有用過的 ID')]
    public function test_database_error_blocks_every_write(): void
    {
        $raw = self::FRONT_MATTER."## 甲\n";
        $path = $this->makeFile('a.md', $raw);
        Schema::drop('article_units');

        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('a.md：查不到資料庫裡這篇文章用過的 ID')
            ->expectsOutputToContain('一個檔案都沒有寫')
            ->assertExitCode(1);

        $this->assertSame($raw, file_get_contents($path));
    }

    #[TestDox('front matter 不是合法的 YAML：看不出是哪一篇，當成失敗，一個檔案都不寫')]
    public function test_unreadable_front_matter_blocks_every_write(): void
    {
        $good = $this->makeFile('a-good.md', self::FRONT_MATTER."## 甲\n");
        $bad = $this->makeFile('b-bad.md', "---\nslug: [sample\n---\n## 乙\n");

        $this->artisan('articles:write-heading-ids', ['path' => $this->dir])
            ->expectsOutputToContain('b-bad.md：front matter 不是合法的 YAML')
            ->expectsOutputToContain('一個檔案都沒有寫')
            ->assertExitCode(1);

        $this->assertSame(self::FRONT_MATTER."## 甲\n", file_get_contents($good));
        $this->assertSame("---\nslug: [sample\n---\n## 乙\n", file_get_contents($bad));
    }
}
