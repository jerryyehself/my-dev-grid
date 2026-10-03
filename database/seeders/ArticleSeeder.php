<?php

namespace Database\Seeders;

use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 把 daily-claude-summary 的 4 份報告填成已發布的文章（Documentation，scope = post）。
 *
 * 不在 DatabaseSeeder 的預設串裡：這是「內容」不是「基礎資料」，`migrate:fresh --seed`
 * 不該順便把站長的文章灌進每一個環境（本機、CI、測試）。需要時手動跑：
 *
 *     php artisan db:seed --class=ArticleSeeder
 *
 * 前提：scopes 已經有 `post`（ScopeSeeder 建的），沒有就直接丟錯，不替它補建。
 *
 * 冪等：用 title 當 key 做 updateOrCreate，重跑只會更新同一筆，不會重複新增
 * （ScopeSeeder／RelationSeeder 原本用 create()，正式環境跑兩次就整份重複過；2026-10-02 起改成
 * 以 name 為鍵「沒有才建」，跟這支不同的是不會蓋掉既有內容，因為分類／述詞可以在後台編輯）。
 * 代價是重跑會把 body／status 等欄位蓋回 fixture 的內容——要改文章請改 fixture 再重跑，
 * 或者直接在編輯器改、之後就別重跑。被站長軟刪除的文章不會被復活（見 run()）。
 *
 * body 是 Markdown 原文（D-46），標題另存 title 欄位，所以 fixture 檔已去掉開頭的 H1；
 * summary／intro 沒有獨立欄位，靠 body 的格式帶（D-57），不用另外填。
 *
 * fixture 是從 daily-claude-summary 的 reports/ 複製進來的（不在執行時抓），因為這些內容
 * 會公開，複製時已做過一輪隱私檢查：帳號層級識別字（Cloudflare 子網域、Zero Trust team name）
 * 換成示意值，求職相關的敘述移除。之後要更新內容，重新複製後也要重做同一輪檢查。
 */
class ArticleSeeder extends Seeder
{
    /**
     * @var list<array{title: string, file: string, creation_date: string}>
     */
    private const ARTICLES = [
        [
            'title' => '前端建置工具與部署概念問答整理',
            'file' => 'frontend-build-tooling-qa.md',
            'creation_date' => '2026-09-22',
        ],
        [
            'title' => '首頁知識視覺化設計決策記錄',
            'file' => 'homepage-graph-visualization-design.md',
            'creation_date' => '2026-09-02',
        ],
        [
            'title' => '節省 Token／降變異度機制盤點',
            'file' => 'token-saving-mechanisms-inventory.md',
            'creation_date' => '2026-09-04',
        ],
        [
            'title' => 'my-dev-grid-front 部署到 Cloudflare 的完整流程',
            'file' => 'cloudflare-workers-spa-deployment-guide.md',
            'creation_date' => '2026-09-25',
        ],
    ];

    public function run(): void
    {
        // 用名稱找 scope，不寫死 id——id 在不同環境（SQLite／Postgres sequence、重建過幾次）都不一樣。
        // orderBy id：萬一某個環境的 scopes 被重複跑過 seeder 而有兩個 post，固定挑最早那個，
        // 重跑才不會在兩個 post 之間來回換。
        $post = Scope::where('name', 'post')->orderBy('id')->first();

        if ($post === null) {
            throw new RuntimeException('找不到 name = post 的 scope。請先跑 `php artisan db:seed --class=ScopeSeeder`（或 migrate:fresh --seed）再跑 ArticleSeeder。');
        }

        foreach (self::ARTICLES as $article) {
            // 連軟刪除的一起找：站長刪掉的文章，重跑 seeder 不該又默默長回來。
            $existing = Documentation::withTrashed()->where('title', $article['title'])->first();

            if ($existing?->trashed()) {
                continue;
            }

            Documentation::updateOrCreate(
                ['title' => $article['title']],
                [
                    'type' => $post->id,
                    'body' => $this->readBody($article['file']),
                    'status' => Documentation::STATUS_PUBLISHED,
                    'creation_date' => $article['creation_date'],
                ],
            );
        }
    }

    protected function fixturesPath(): string
    {
        return database_path('seeders/fixtures/articles');
    }

    private function readBody(string $file): string
    {
        $path = $this->fixturesPath().'/'.$file;

        if (! is_file($path)) {
            throw new RuntimeException("文章 fixture 不存在：{$path}");
        }

        return file_get_contents($path);
    }
}
