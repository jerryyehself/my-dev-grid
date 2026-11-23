<?php

namespace App\Console\Commands;

use App\Services\ArticleHeadingIdWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * 替文章原稿（單一檔案，或目錄底下所有 `.md`）的 H2、H3 補上行尾的 `{#id}`。規則見 `ArticleHeadingIdWriter`。
 *
 * - 全有或全無：任何一個檔案補不了（ID 重複、不是 UTF-8…）就一個檔案都不寫，結束碼是失敗；
 *   先處理完所有檔案、都沒問題才寫檔，免得文章 repo 留下補到一半的工作目錄。
 * - 沒有 front matter 的檔案（例如 README）略過，不算失敗。
 * - 可以重跑：已經補完的檔案 0 變更，也不會重寫檔案。
 * - 刪掉的段落的 ID 不會再發出去：這個檔案在 git 歷史裡任何一個版本的標題上出現過的 `{#id}`
 *   （改名前的路徑也算）都當成已占用。曾經發出去的 ID 都在原稿的歷史裡，跟連的是哪個資料庫無關，
 *   所以這支指令不碰資料庫。檔案不在 git repo 裡、或 git 跑失敗時當成失敗，不當成沒有用過的 ID。
 * - `--dry-run` 只印會補哪些行，不寫檔。
 */
class WriteArticleHeadingIds extends Command
{
    protected $signature = 'articles:write-heading-ids
        {path : 原稿檔案，或放原稿的目錄（遞迴處理 .md）}
        {--dry-run : 只印會補哪些行，不寫檔}';

    protected $description = '替文章原稿的 H2、H3 補上 {#id}（已有的不動，可重跑）';

    public function handle(ArticleHeadingIdWriter $writer): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        $files = $this->files($path);
        if ($files === null) {
            $this->error("找不到檔案或目錄：{$path}");

            return self::FAILURE;
        }
        if ($files === []) {
            $this->error("目錄裡沒有 .md 檔：{$path}");

            return self::FAILURE;
        }

        $results = [];
        $failed = false;
        foreach ($files as $file) {
            $label = is_dir($path) ? substr($file, strlen(rtrim($path, '/')) + 1) : $file;
            $raw = file_get_contents($file);
            if ($raw === false) {
                $this->error("{$label}：讀不到檔案");
                $failed = true;

                continue;
            }

            try {
                $results[$file] = ['label' => $label, 'raw' => $raw] + $writer->write($raw, $this->historicalIds($writer, $file));
            } catch (InvalidArgumentException $e) {
                $this->error("{$label}：{$e->getMessage()}");
                $failed = true;
            } catch (RuntimeException $e) {
                // 含 git 逾時（ProcessTimedOutException 也是 RuntimeException）
                $this->error("{$label}：查不到 git 歷史裡用過的 ID（{$e->getMessage()}）");
                $failed = true;
            }
        }

        if ($failed) {
            $this->error('有檔案補不了，一個檔案都沒有寫。');

            return self::FAILURE;
        }

        $total = 0;
        foreach ($results as $file => $result) {
            if ($result['skipped'] !== null) {
                $this->line("{$result['label']}：略過（{$result['skipped']}）");

                continue;
            }

            $count = count($result['added']);
            $total += $count;
            $this->line($count === 0 ? "{$result['label']}：沒有要補的 ID" : "{$result['label']}：補 {$count} 個 ID");

            foreach ($result['added'] as $added) {
                $this->line("  第 {$added['line']} 行 H{$added['level']}「{$added['heading']}」→ {#{$added['id']}}");
            }

            if ($count > 0 && ! $dryRun && file_put_contents($file, $result['content']) === false) {
                $this->error("{$result['label']}：寫檔失敗");

                return self::FAILURE;
            }
        }

        $this->info('共 '.count($files)." 個檔案、補 {$total} 個 ID".($dryRun ? '（--dry-run，沒有寫入）' : ''));

        return self::SUCCESS;
    }

    /**
     * 這個檔案在 git 歷史裡每個版本的標題上出現過的 `{#id}`（改名前的路徑也算，`git log --follow`），
     * 標題怎麼認由 `ArticleHeadingIdWriter::headingIds()` 決定。檔名照字面比對（`--literal-pathspecs`）：
     * `:` 開頭、含 `*` 的檔名不會被當成 pathspec 語法。還沒 commit 過的新檔案沒有歷史，是空清單。
     *
     * 已知讀不到的情況（這些情況下以前發出去的 ID 可能被重用，指令擋不了）：
     * - 只看目前分支（HEAD）的歷史：跑之前先 pull，在含有 main 最新歷史的分支上跑。
     * - 只看 commit 過的版本：還沒 commit 的 `git mv` 讀不到改名前的歷史。
     * - 改名要單獨先 commit：同一個 commit 裡改名又大幅改寫內容，git 認不出是改名，改名前的歷史就斷了。
     * - 合併 commit 自己的版本不讀：只在解合併衝突時寫進去、之後又刪掉的 ID 不保留。
     * - 非線性歷史（側枝與主線分別改名或改內文後合併）時 `--follow` 可能提早切回舊路徑，漏讀一些版本。
     * 目前沒有地方引用段落 ID，ID 重用還不會讓任何引用指錯；等引用功能出現再評估要不要讀得更完整。
     *
     * @return list<string>
     *
     * @throws RuntimeException 不在 git repo 裡、git 跑不起來或結束碼不是 0、讀不到某個版本
     */
    private function historicalIds(ArticleHeadingIdWriter $writer, string $file): array
    {
        $dir = dirname($file);
        $inside = Process::path($dir)->run(['git', 'rev-parse', '--is-inside-work-tree']);
        if (! $inside->successful() || trim($inside->output()) !== 'true') {
            throw new RuntimeException('不在 git repo 裡，查不到這個檔案以前發出去的 ID');
        }

        // -z：每個 commit 是「commit:<sha>」與它當時的路徑（相對於 repo 根目錄）兩段，以 NUL 分隔；--diff-filter=d 跳過刪掉檔案的那次
        $log = Process::path($dir)->run(['git', '--literal-pathspecs', 'log', '--follow', '--diff-filter=d', '--name-only', '-z', '--format=commit:%H', '--', basename($file)]);
        if (! $log->successful()) {
            throw new RuntimeException('git log 失敗：'.trim($log->errorOutput()));
        }

        $ids = [];
        $commit = null;
        foreach (explode("\0", $log->output()) as $token) {
            $token = ltrim($token, "\n");
            if (str_starts_with($token, 'commit:')) {
                $commit = substr($token, strlen('commit:'));

                continue;
            }
            if ($token === '' || $commit === null) {
                continue;
            }
            $version = $commit.':'.$token;
            $commit = null;

            $show = Process::path($dir)->run(['git', 'cat-file', 'blob', $version]);
            if (! $show->successful()) {
                throw new RuntimeException("讀不到 {$version}：".trim($show->errorOutput()));
            }
            array_push($ids, ...$writer->headingIds($show->output()));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>|null 路徑不存在是 null；目錄沒有 .md 是空清單。照檔名排序，輸出才穩定。
     */
    private function files(string $path): ?array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (! is_dir($path)) {
            return null;
        }

        $files = [];
        foreach ((new Finder)->files()->in($path)->name('*.md')->sortByName() as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
