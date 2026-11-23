<?php

namespace App\Console\Commands;

use App\Models\ArticleUnit;
use App\Models\Documentation;
use App\Services\ArticleHeadingIdWriter;
use App\Services\ArticleSourceParser;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;

/**
 * 替文章原稿（單一檔案，或目錄底下所有 `.md`）的 H2、H3 補上行尾的 `{#id}`。規則見 `ArticleHeadingIdWriter`。
 *
 * - 全有或全無：任何一個檔案補不了（ID 重複、不是 UTF-8…）就一個檔案都不寫，結束碼是失敗；
 *   先處理完所有檔案、都沒問題才寫檔，免得文章 repo 留下補到一半的工作目錄。
 * - 沒有 front matter 的檔案（例如 README）略過，不算失敗。
 * - 可以重跑：已經補完的檔案 0 變更，也不會重寫檔案。
 * - 刪掉的段落的 ID 不會再發出去：用 front matter 的 `slug` 對上資料庫裡的文章（`documentations.source_key`，
 *   含已軟刪除的），那篇文章在 `article_units` 用過的 ID（含已標成移除的單位）都當成已占用。
 *   只看得到這次連的資料庫裡的紀錄；查不了資料庫（連不上、還沒 migrate）時當成失敗，不當成沒有用過的 ID。
 *   front matter 不是合法的 YAML 時看不出是哪一篇，也當成失敗。
 * - `--dry-run` 只印會補哪些行，不寫檔。
 */
class WriteArticleHeadingIds extends Command
{
    protected $signature = 'articles:write-heading-ids
        {path : 原稿檔案，或放原稿的目錄（遞迴處理 .md）}
        {--dry-run : 只印會補哪些行，不寫檔}';

    protected $description = '替文章原稿的 H2、H3 補上 {#id}（已有的不動，可重跑）';

    public function handle(ArticleHeadingIdWriter $writer, ArticleSourceParser $parser): int
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
                $results[$file] = ['label' => $label, 'raw' => $raw] + $writer->write($raw, $this->usedIds($parser, $raw));
            } catch (InvalidArgumentException $e) {
                $this->error("{$label}：{$e->getMessage()}");
                $failed = true;
            } catch (QueryException $e) {
                $this->error("{$label}：查不到資料庫裡這篇文章用過的 ID（{$e->getMessage()}）");
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
     * 資料庫裡這篇文章（slug 相同，含已軟刪除的）所有段落單位用過的 ID，含已標成移除的。
     * 不限匯入來源的 repo：多保留幾個 ID 只是跳號，少保留才會重用。
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException front matter 不是合法的 YAML
     * @throws QueryException 查不了資料庫
     */
    private function usedIds(ArticleSourceParser $parser, string $raw): array
    {
        $slug = $parser->slug($raw);
        if ($slug === null) {
            return [];
        }

        // 不管有沒有對上文章都查 article_units：查不了（例如還沒 migrate）時要丟錯，不能因為沒有文章就略過
        return ArticleUnit::whereIn('article_id', Documentation::withTrashed()->where('source_key', $slug)->select('id'))
            ->whereNotNull('unit_id')
            ->pluck('unit_id')
            ->all();
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
