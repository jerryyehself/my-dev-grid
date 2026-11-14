<?php

namespace Database\Seeders;

use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Seeder;

/**
 * 預設的技術節點：不是從 GitHub 同步得到、但網站上要有的技術。
 *
 * 目前只有「Claude Code」（assistant 分類，AI 工具本身的身分節點）。外部參考裡有許多是給它用的
 * skill 和工具，要用 `targets` 連到它，所以節點要先在。
 *
 * 跟 ScopeSeeder／RelationSeeder 一樣，每次部署都會跑（ReferenceDataSeeder），所以：
 * - 以 title（版本留空那一筆）為鍵「沒有才建」，重跑不重複，也不蓋掉後台改過的內容。
 * - 含軟刪除的列（withTrashed）：後台刪掉的，重跑不會又建回來。
 * - 找不到 assistant 分類（被後台刪掉）就不建，不自己發明分類。
 *
 * 技術資料表沒有網址欄位（只有 type、title、version、note），所以不存官方網址。
 */
class TechniqueSeeder extends Seeder
{
    public function run(): void
    {
        $assistant = Scope::withTrashed()->where('name', 'assistant')->first();

        if ($assistant === null || $assistant->trashed()) {
            return;
        }

        Technique::withTrashed()->firstOrCreate(
            ['title' => 'Claude Code', 'version' => null],
            ['type' => $assistant->id, 'note' => 'Anthropic 的 AI 寫程式代理工具'],
        );
    }
}
