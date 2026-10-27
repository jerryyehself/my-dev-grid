<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 預設資料（分類、述詞）：每次部署在 `php artisan migrate --force` 之後自動執行
 * （`.github/workflows/deploy-cloud-run.yml` 的「Seed reference data」），新增的預設資料
 * 跟著部署進正式環境，不用另外寫 migration。本機與測試的 `DatabaseSeeder` 也是跑這一支。
 *
 *     php artisan db:seed --class=ReferenceDataSeeder --force
 *
 * 只有同時符合下面每一條的 seeder 才能加進 SEEDERS（每次部署都會跑，正式環境的資料都在）：
 *
 * - 可以重跑：跑兩次不多出資料（`SeederIdempotencyTest`、`ReferenceDataSeederTest`）。
 * - 不蓋掉後台改過的內容，也不把後台刪掉的建回來（`withTrashed()->firstOrCreate()`）。
 * - 不打外部服務、不讀密鑰、不建立使用者帳號。
 * - 只依賴資料庫結構和排在它前面的 seeder，空資料庫上也能跑完。
 *
 * 沒有加進來的：
 *
 * - `ArticleSeeder`：文章是內容不是預設資料，而且用 `updateOrCreate`，重跑會把後台改過的內文蓋回 fixture。
 * - `GitHubReposSnapshotSeeder`：GitHub repo 的快照，正式環境由每天的同步 Job 負責。
 * - `RepoLineageRelationsSeeder`：要先有同步進來的 repo，找不到就丟錯。
 *
 * 整串包在一個 transaction 裡：中途失敗就整個退回，不會留下一半的資料；部署流程也會停在這一步，
 * 新版不會上線。
 */
class ReferenceDataSeeder extends Seeder
{
    /** 順序有意義：RelationSeeder 要用 ScopeSeeder 建的頂層分類。 */
    public const SEEDERS = [
        ScopeSeeder::class,
        RelationSeeder::class,
    ];

    public function run(): void
    {
        DB::transaction(fn () => $this->call(self::SEEDERS));
    }
}
