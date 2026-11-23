<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * `articles:write-heading-ids`：替文章原稿的 H2、H3 補上 `{#id}`（一次性補寫，之後凍結）。
 * 補寫規則本身（哪些行、怎麼不撞號、位元組不變）在 `ArticleHeadingIdWriterTest`；這裡驗指令的外殼：
 * 讀檔、寫檔、`--dry-run`、目錄、全有或全無、結束碼，以及從 git 歷史收集以前發出去的 ID。
 * 原稿放在每個測試自己建的臨時 git repo 裡（指令要求檔案在 git repo 裡）。
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
        $this->git('init', '-q');
        $this->git('commit', '-q', '--allow-empty', '-m', 'init');
    }

    /** 在臨時 repo 裡跑 git，失敗就讓測試失敗。 */
    private function git(string ...$args): string
    {
        $result = Process::path($this->dir)->run(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', ...$args]);
        $this->assertTrue($result->successful(), 'git '.implode(' ', $args).'：'.$result->errorOutput());

        return $result->output();
    }

    private function commitAll(string $message): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
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

    #[TestDox('刪掉的段落的 ID 不會再發給新標題：git 歷史裡出現過的 ID 都跳過')]
    public function test_ids_of_removed_sections_are_not_reused(): void
    {
        // 加兩節、補 ID、commit
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 甲\n甲的內容\n\n## 乙\n乙的內容\n");
        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);
        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n甲的內容\n\n## 乙 {#s2}\n乙的內容\n", file_get_contents($path));
        $this->commitAll('補 ID');

        // 刪掉「乙」、commit；之後加一節新的再跑
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\n甲的內容\n");
        $this->commitAll('刪掉乙');
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\n甲的內容\n\n## 丙\n丙的內容\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('第 9 行 H2「丙」→ {#s3}')
            ->assertExitCode(0);
        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n甲的內容\n\n## 丙 {#s3}\n丙的內容\n", file_get_contents($path));
    }

    #[TestDox('檔案改名、換目錄之後，改名前的歷史裡用過的 ID 也跳過')]
    public function test_ids_before_a_rename_stay_reserved(): void
    {
        $path = $this->makeFile('old-name.md', self::FRONT_MATTER."## 甲 {#s1}\n內容\n\n## 乙 {#s2}\n內容\n");
        $this->commitAll('原稿');
        mkdir($this->dir.'/articles');
        $this->git('mv', 'old-name.md', 'articles/new-name.md');
        $this->commitAll('改名');
        $renamed = $this->dir.'/articles/new-name.md';
        file_put_contents($renamed, self::FRONT_MATTER."## 甲 {#s1}\n內容\n");
        $this->commitAll('刪掉乙');
        file_put_contents($renamed, self::FRONT_MATTER."## 甲 {#s1}\n內容\n\n## 丙\n");

        $this->artisan('articles:write-heading-ids', ['path' => $renamed])->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n內容\n\n## 丙 {#s3}\n", file_get_contents($renamed));
    }

    #[TestDox('只寫在合併 commit 裡的 ID（解衝突時新加的節）也跳過')]
    public function test_ids_only_in_a_merge_commit_stay_reserved(): void
    {
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 甲 {#s1}\n原本的內容\n");
        $this->commitAll('原稿');
        $main = trim($this->git('rev-parse', '--abbrev-ref', 'HEAD'));
        $this->git('checkout', '-q', '-b', 'side');
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\nside 改的內容\n");
        $this->commitAll('side 改');
        $this->git('checkout', '-q', $main);
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\nmain 改的內容\n");
        $this->commitAll('main 改');

        // 合併衝突，解衝突時新加一節 s2，只存在於合併 commit 的樹裡
        $merge = Process::path($this->dir)->run(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.com', 'merge', '-q', 'side']);
        $this->assertFalse($merge->successful(), '應該要有衝突');
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\n合併後的內容\n\n## 乙 {#s2}\n解衝突時加的\n");
        $this->git('add', '-A');
        $this->git('commit', '-q', '--no-edit');

        // 下一個 commit 刪掉乙，之後加一節新的
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\n合併後的內容\n");
        $this->commitAll('刪掉乙');
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\n合併後的內容\n\n## 丙\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n合併後的內容\n\n## 丙 {#s3}\n", file_get_contents($path));
    }

    /** @return array<string, array{string}> */
    public static function pathspecLookingNames(): array
    {
        return [
            '冒號開頭（pathspec 的 magic）' => [':colon.md'],
            '含 * （glob）' => ['star*.md'],
        ];
    }

    #[DataProvider('pathspecLookingNames')]
    #[TestDox('檔名照字面比對，不當成 pathspec：冒號開頭、含 * 的檔名也查得到自己的歷史，不會讀到別的檔案')]
    public function test_file_names_are_literal_pathspecs(string $name): void
    {
        // 同一個 commit 裡另有一個排在前面、* 也比對得到的檔案，只有它的歷史沒有 s2
        $path = $this->makeFile($name, self::FRONT_MATTER."## 甲 {#s1}\n\n## 乙 {#s2}\n");
        $other = $this->makeFile('star!other.md', self::FRONT_MATTER."## 別篇 {#s1}\n");
        $this->commitAll('原稿');
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\n");
        file_put_contents($other, self::FRONT_MATTER."## 別篇 {#s1}\n改過\n");
        $this->commitAll('刪掉乙');
        file_put_contents($path, self::FRONT_MATTER."## 甲 {#s1}\n\n## 丙\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n\n## 丙 {#s3}\n", file_get_contents($path));
    }

    #[TestDox('別的檔案的歷史不算：同一個 repo 裡另一篇用過的 ID 不影響這篇')]
    public function test_history_of_other_files_does_not_count(): void
    {
        $this->makeFile('other.md', self::FRONT_MATTER."## 別篇 {#s1}\n");
        $this->commitAll('別篇');
        $path = $this->makeFile('a.md', self::FRONT_MATTER."## 甲\n");

        $this->artisan('articles:write-heading-ids', ['path' => $path])->assertExitCode(0);

        $this->assertSame(self::FRONT_MATTER."## 甲 {#s1}\n", file_get_contents($path));
    }

    #[TestDox('檔案不在 git repo 裡：當成失敗，一個檔案都不寫，不當成沒有用過的 ID')]
    public function test_file_outside_a_git_repo_blocks_every_write(): void
    {
        $outside = sys_get_temp_dir().'/heading-ids-nogit-'.bin2hex(random_bytes(6));
        mkdir($outside);
        $raw = self::FRONT_MATTER."## 甲\n";
        file_put_contents($outside.'/a.md', $raw);

        try {
            $this->artisan('articles:write-heading-ids', ['path' => $outside])
                ->expectsOutputToContain('a.md：查不到 git 歷史裡用過的 ID（不在 git repo 裡')
                ->expectsOutputToContain('一個檔案都沒有寫')
                ->assertExitCode(1);

            $this->assertSame($raw, file_get_contents($outside.'/a.md'));
        } finally {
            File::deleteDirectory($outside);
        }
    }

    #[TestDox('git log 失敗（結束碼不是 0）：整批失敗、不寫')]
    public function test_git_log_failure_blocks_every_write(): void
    {
        $raw = self::FRONT_MATTER."## 甲\n";
        $path = $this->makeFile('a.md', $raw);
        Process::fake([
            '*rev-parse*' => Process::result("true\n"),
            '*log*' => Process::result('', 'fatal: 模擬的錯誤', 128),
        ]);

        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('git log 失敗：fatal: 模擬的錯誤')
            ->assertExitCode(1);

        $this->assertSame($raw, file_get_contents($path));
    }

    #[TestDox('讀不到某個版本的內容：整批失敗、不寫')]
    public function test_unreadable_revision_blocks_every_write(): void
    {
        $raw = self::FRONT_MATTER."## 甲\n";
        $path = $this->makeFile('a.md', $raw);
        Process::fake([
            '*rev-parse*' => Process::result("true\n"),
            '*log*' => Process::result('commit:'.str_repeat('a', 40)."\0\na.md\0"),
            '*cat-file*' => Process::result('', 'fatal: bad object', 128),
        ]);

        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('讀不到 '.str_repeat('a', 40).':a.md')
            ->assertExitCode(1);

        $this->assertSame($raw, file_get_contents($path));
    }

    #[TestDox('git 跑不起來或逾時（丟例外）：整批失敗、不寫')]
    public function test_git_exception_blocks_every_write(): void
    {
        $raw = self::FRONT_MATTER."## 甲\n";
        $path = $this->makeFile('a.md', $raw);
        Process::fake(['*' => fn () => throw new RuntimeException('模擬 git 逾時')]);

        $this->artisan('articles:write-heading-ids', ['path' => $path])
            ->expectsOutputToContain('查不到 git 歷史裡用過的 ID（模擬 git 逾時）')
            ->assertExitCode(1);

        $this->assertSame($raw, file_get_contents($path));
    }
}
