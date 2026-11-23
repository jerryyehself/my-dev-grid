<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * `articles:write-heading-ids`：替文章原稿的 H2、H3 補上 `{#id}`（一次性補寫，之後凍結）。
 * 補寫規則本身（哪些行、怎麼不撞號、位元組不變）在 `ArticleHeadingIdWriterTest`；這裡驗指令的外殼：
 * 讀檔、寫檔、`--dry-run`、目錄、全有或全無、結束碼。
 */
class WriteArticleHeadingIdsCommandTest extends TestCase
{
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
}
