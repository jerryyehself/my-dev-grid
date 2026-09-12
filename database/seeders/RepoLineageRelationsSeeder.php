<?php

namespace Database\Seeders;

use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 2026-09-11 使用者確認的真實 Implementation×Implementation 關聯——這些不是
 * `KnowledgeGraphPanel.vue` 靠 bipartite projection 算出來的推導邊，是使用者
 * 自己脈絡裡知道、該被記錄下來的事實：
 *
 * - my-dev-grid descendantOf Laravel-LearningLibrary。
 * - my-dev-grid accompanies my-dev-grid-front（前後端拆分，共同組成同一個
 *   產品，不是誰包含誰）。
 * - marqee-maker → Laravel-LearningLibrary → laravel-demo → api-demo 這條
 *   precedes 鏈：啟發式為「同樣是 Laravel 練習專案 + 建立時間連續（中間沒有
 *   夾著非 Laravel 專案）且間隔在 1 年以內」。api-demo → bulletin-api 雖然中間
 *   沒有非 Laravel 專案，但間隔超過 2 年，使用者確認排除，不列入這條鏈。
 *
 * 只存單一方向（例如只存 descendantOf，不再多存一筆 ancestorOf 反向列）——
 * 跟現有 `requires`/`isRequiredBy` 的用法一致：反向 Relation 是給查詢/標籤
 * 用的，不代表每筆事實都要存兩筆方向相反的 entity_relations。
 *
 * 依賴 Implementation 資料已經存在（先跑過 `github:sync-repos` 或
 * `GitHubReposSnapshotSeeder`），所以不放進 DatabaseSeeder 自動鏈，手動用
 * `php artisan db:seed --class=RepoLineageRelationsSeeder` 執行。
 */
class RepoLineageRelationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->relate('my-dev-grid', 'descendantOf', 'Laravel-LearningLibrary');
        $this->relate('my-dev-grid', 'accompanies', 'my-dev-grid-front');

        $sequentialChain = ['marqee-maker', 'Laravel-LearningLibrary', 'laravel-demo', 'api-demo'];
        for ($i = 0; $i < count($sequentialChain) - 1; $i++) {
            $this->relate($sequentialChain[$i], 'precedes', $sequentialChain[$i + 1]);
        }
    }

    private function relate(string $subjectTitle, string $predicate, string $objectTitle): void
    {
        $subject = Implementation::where('title', $subjectTitle)->first();
        $object = Implementation::where('title', $objectTitle)->first();

        if (! $subject || ! $object) {
            throw new RuntimeException(
                "RepoLineageRelationsSeeder: 找不到 Implementation \"{$subjectTitle}\" 或 \"{$objectTitle}\"，先跑 github:sync-repos 或 db:seed --class=GitHubReposSnapshotSeeder 把 repo 資料填進去。"
            );
        }

        $relation = Relation::where('name', $predicate)->firstOrFail();

        EntityRelation::updateOrCreate([
            'entity_type' => 'implementation',
            'subject_id' => $subject->id,
            'object_id' => $object->id,
            'relation_id' => $relation->id,
        ]);
    }
}
