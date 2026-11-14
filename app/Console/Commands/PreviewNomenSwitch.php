<?php

namespace App\Console\Commands;

use App\Services\NomenSwitchPreview;
use Illuminate\Console\Command;

/**
 * 印出影子報表：同步與文章匯入如果改成照 `nomens` 的解析結果寫邊，跟現在會差在哪裡。唯讀。
 *
 * 規則與限制見 `NomenSwitchPreview`。資料要先有：跑過一次 `github:sync-repos`、匯入過文章。
 */
class PreviewNomenSwitch extends Command
{
    protected $signature = 'nomens:preview-switch';

    protected $description = '列出改用 nomens 的解析結果寫邊後，專案與文章的邊會怎麼變（唯讀）';

    public function handle(NomenSwitchPreview $preview): int
    {
        $report = $preview->build();

        if ($report['rows'] === 0) {
            $this->info('nomens 還沒有任何紀錄：先跑一次 github:sync-repos 或匯入文章。');

            return self::SUCCESS;
        }

        $this->section('專案的 usedBy 邊');
        $this->table(['專案', '保留', '不再更新', '新增'], array_map(fn (array $p) => [
            $p['title'], implode('、', $p['keep']), implode('、', $p['stop']), implode('、', $p['add']),
        ], $report['projects']));

        $this->section('文章的 about 連結');
        $this->table(['文章', '已連結', '新增'], array_map(fn (array $a) => [
            $a['title'], implode('、', $a['linked']), implode('、', $a['add']),
        ], $report['articles']));

        $this->section('對不上或證據不夠的名稱');
        $this->table(['來源', '名稱', '結果', '作品數'], array_map(fn (array $p) => [
            $p['source'], $p['string'], $p['resolution'], $p['contexts'],
        ], $report['pending']));

        $this->section('會自動長出來的概念節點（出現在 2 篇以上文章）');
        $this->table(['名稱', '文章數'], array_map(fn (array $c) => [$c['string'], $c['contexts']], $report['concepts']));

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("== {$title} ==");
    }
}
