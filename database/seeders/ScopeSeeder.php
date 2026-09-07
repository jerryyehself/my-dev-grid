<?php

namespace Database\Seeders;

use App\Models\Scope;
use Illuminate\Database\Seeder;

class ScopeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seeds = [
            [
                'class_number' => '00',
                'name' => 'Documentation',
                'comment' => '本體三大分類之一：`documentation` 資料表的頂層分類，代表可被引用的既有文件／知識來源本身（官方文件、外部文章、AI 對話紀錄等），透過 `DocumentationTechniqueLink`／`DocumentationImplementationLink` 兩個中介表分別連向 Technique、Implementation，並在 `/api/graph`（`GraphController`）輸出為 `documentation` 類型節點。',
            ],
            [
                'class_number' => '00',
                'call_number' => '10',
                'parent_class' => '1',
                'name' => 'sourcesite',
                'comment' => '`Documentation` 底下的子分類，對應官方文件站台，由 `SaveReposDataService::link_official_docs()` 依 `OFFICIAL_DOCS` 對照表自動建立（例如 Vue、Laravel 官網），並以 `specs` 關係連回對應的 `Technique`。',
            ],
            [
                'class_number' => '00',
                'call_number' => '20',
                'parent_class' => '1',
                'name' => 'document',
                'comment' => '`Documentation` 底下的子分類，泛指靜態文件檔案類的知識來源（規格書、白皮書等），跟 `sourcesite`（線上文件站台）、`post`（單篇文章）並列 Documentation 的三種型態。',
            ],
            [
                'class_number' => '00',
                'call_number' => '30',
                'parent_class' => '1',
                'name' => 'post',
                'comment' => '`Documentation` 底下的子分類，對應單篇文章／部落格文章形式的知識來源，跟 `sourcesite`、`document` 並列 Documentation 的三種型態。',
            ],
            [
                'class_number' => '10',
                'name' => 'Technique',
                'comment' => '本體三大分類之一：`technique` 資料表的頂層分類，代表被使用的技術本身（語言、框架、套件工具、執行環境），由 `SaveReposDataService` 依 GitHub repo 的 languages／topics 自動 find-or-create，並透過 `technique_implementation` 中介表以 `uses` 關係連回使用它的 `Implementation`。',
            ],
            [
                'class_number' => '10',
                'call_number' => '10',
                'parent_class' => '5',
                'name' => 'language',
                // 外部分類依據：Wikidata Q9143「programming language」
                // ("language for communicating instructions to a machine")
                // https://www.wikidata.org/wiki/Q9143
                'comment' => '`Technique` 底下的子分類，對應程式語言本身（例如 PHP、JavaScript），由 `SaveReposDataService::find_or_create_technique()` 依 GitHub repo 的 `languages` API 自動建立。外部分類對應 Wikidata Q9143「programming language」：https://www.wikidata.org/wiki/Q9143 。',
            ],
            [
                'class_number' => '10',
                'call_number' => '20',
                'parent_class' => '5',
                'name' => 'environment',
                // 外部分類依據：Schema.org 的 RuntimePlatform 型別／
                // runtimePlatform 屬性（SoftwareApplication 的執行環境）
                // https://schema.org/RuntimePlatform
                'comment' => '`Technique` 底下的子分類，對應執行期環境（例如 Node.js、Docker、作業系統平台）；目前尚無自動化資料管線寫入，供 Triple 後台手動維護。外部分類對應 Schema.org `RuntimePlatform` 型別：https://schema.org/RuntimePlatform 。',
            ],
            [
                'class_number' => '10',
                'call_number' => '30',
                'parent_class' => '5',
                'name' => 'packagetool',
                // 外部分類依據：Package URL (purl) 規格的 type 元件，
                // 依套件生態系／管理工具區分識別碼前綴（如 npm、composer、pypi、maven）
                // https://github.com/package-url/purl-spec/blob/master/docs/types/types-overview.md
                'comment' => '`Technique` 底下的子分類，對應套件管理工具／建置工具（例如 npm、Composer），由 `SaveReposDataService` 依 GitHub repo 的 `topics` 自動建立技術節點。外部分類對應 Package URL (purl) 規格的 `type` 元件：https://github.com/package-url/purl-spec/blob/master/docs/types/types-overview.md 。',
            ],
            [
                'class_number' => '10',
                'call_number' => '40',
                'parent_class' => '5',
                'name' => 'framework',
                // 外部分類依據：Wikidata Q271680「software framework」
                // ("software that supports solution development via inversion of control")
                // https://www.wikidata.org/wiki/Q271680
                'comment' => '`Technique` 底下的子分類，對應軟體框架（例如 Vue、Laravel）；目前 `SaveReposDataService` 統一將 repo topics 歸類到 `packagetool`，`framework` 尚無自動化資料管線寫入，供 Triple 後台手動維護。外部分類對應 Wikidata Q271680「software framework」：https://www.wikidata.org/wiki/Q271680 。',
            ],
            [
                'class_number' => '20',
                'name' => 'Implementation',
                'comment' => '本體三大分類之一：`implementation` 資料表的頂層分類，代表實際產出或執行的成品，例如 `SaveReposDataService::save_repos_content()` 從 GitHub API 同步進來、以 `type = project` 儲存的每個公開 repo。',
            ],
            [
                'class_number' => '20',
                'call_number' => '10',
                'parent_class' => '10',
                'name' => 'work',
                'comment' => '`Implementation` 底下的子分類，泛指非 GitHub repo 形式的產出物（例如作品、成果展示），跟 `project`（GitHub repo 專案）、`problem`（待解問題／議題）、`picture`（圖像類成品）並列 Implementation 的四種型態。',
            ],
            [
                'class_number' => '20',
                'call_number' => '20',
                'parent_class' => '10',
                'name' => 'project',
                'comment' => '`Implementation` 底下的子分類，對應 `SaveReposDataService::save_repos_content()` 透過 GitHub API 自動同步、`updateOrCreate` 進資料庫的每一個公開 repo，是目前 Implementation 底下唯一有自動化資料管線的子分類。',
            ],
            [
                'class_number' => '20',
                'call_number' => '30',
                'parent_class' => '10',
                'name' => 'problem',
                'comment' => '`Implementation` 底下的子分類，對應待解決或已記錄的問題／議題節點，跟 `work`、`project`、`picture` 並列 Implementation 的四種型態。',
            ],
            [
                'class_number' => '20',
                'call_number' => '40',
                'parent_class' => '10',
                'name' => 'picture',
                'comment' => '`Implementation` 底下的子分類，對應圖像／視覺類產出物，跟 `work`、`project`、`problem` 並列 Implementation 的四種型態。',
            ],
            [
                'class_number' => '10',
                'call_number' => '50',
                'parent_class' => '5',
                'name' => 'assistant',
                'comment' => 'AI 工具本身的身分節點,例如 Claude Code、ChatGPT、GitHub Copilot',
            ],
            [
                'class_number' => '00',
                'call_number' => '40',
                'parent_class' => '1',
                'name' => 'conversation',
                'comment' => '值得引用的具體 AI 對話紀錄',
            ],
        ];

        collect($seeds)->each(fn ($seed) => Scope::create($seed));

        // Scope::factory()->count(10)->create();
    }
}
