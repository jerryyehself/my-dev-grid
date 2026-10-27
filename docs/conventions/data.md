# 資料：migration、seeder、結構變更

## migration 還是 seeder

需要用程式碼把資料填進資料庫時，預設寫成 seeder（`database/seeders/`），不要寫成 migration。

- **migration**：一次性、不會重跑的結構變更或資料修正，正常情況下每支只執行一次。例子：`2026_09_09_000000_reclassify_vue3_topic_from_packagetool_to_framework.php`（修正一次錯誤分類）。
- **seeder**：可能需要重複執行的資料填充，可以隨時用 `php artisan db:seed --class=X` 重跑，不會綁進 migration 歷史，也不會在部署時被 `php artisan migrate` 自動執行。例子：`RelationSeeder`、`GitHubReposSnapshotSeeder`。
- 同一份資料，舊資料庫要補、新資料庫也要有時，兩邊都放：migration 補舊資料庫（新資料庫是空的，migration 什麼都不做），seeder 建新資料庫，再用一個測試檢查兩份定義一致。例子：`2026_09_30_190100_add_technique_version_relations.php`、`RelationSeeder`、`RelationSourceVocabularyTest`。

## 寫 migration

- 用匿名類別（`return new class extends Migration`），在類別的 PHPDoc 寫為什麼要改。
- （提議）新欄位加 `->comment('…')`，寫這一欄存什麼：`$table->text('body')->comment('內文（Markdown 原文）')`。目前 29 支 migration 裡有 12 支這樣做。
- 修正資料的 migration：
  - 用 `DB::table()`，不要用 `App\Models`。model 之後會改，migration 要能在任何時間點跑出一樣的結果。現有的資料修正 migration 都是這樣寫。
  - 需要的對照表寫成 migration 自己的常數，當成當時的快照，不要引用程式裡的常數：`2026_09_30_190200_merge_duplicate_techniques.php` 的 `AUTHORITY`。
  - 全新的資料庫要能直接跑過：資料不存在時就跳過（`if (! DB::table('techniques')->exists()) return;`）。
  - 有 `down()`。會刪資料或改寫資料的，先把原值備份到暫存表，`down()` 照備份還原（`merge_duplicate_techniques` 的 `technique_merge_backups`）。
  - 補一個測試，跑 `up()`、`down()` 驗證資料：`FixUsesRelationDirectionMigrationTest`、`MergeDuplicateTechniquesMigrationTest`。

## 寫 seeder

- 要能重跑：跑兩次不能多出資料、不能蓋掉後台改過的內容、不能把後台刪掉的建回來。可以在後台編輯的資料，用 `withTrashed()->firstOrCreate(['name' => …], …)`（`ScopeSeeder`、`RelationSeeder`）；內容完全由 fixture 決定的，用 `updateOrCreate`（`ArticleSeeder`）。由 `SeederIdempotencyTest` 守住。
- 不要寫死資料的 id。SQLite 測試每次 rollback 會重設流水號，PostgreSQL 的 sequence 不會，寫死的 id 只在 SQLite 上剛好會過。用名稱查 id：`Scope::where('name', 'project')->value('id')`。

## 軟刪除

- `Scope`、`Relation`、`Documentation`、`Technique`、`Implementation` 都用 `SoftDeletes`。
- 用 query builder（`DB::table()`、`DB::query()`）查這些表時，全域 scope 不會生效，每個查詢都要自己加 `whereNull('deleted_at')`（`RelationEdgeQuery`）。

## 結構變更的順序

部署流程是：先用 Cloud Run Job 跑 `php artisan migrate --force`，再部署新版程式。所以 migration 跑完到新版上線之間，**舊版程式還在用新的資料庫結構接流量**。

- 只做向下相容的新增：加欄位（可以是 null，或有預設值）、加資料表、加索引。
- 刪除或改名欄位，不要跟改程式放在同一次部署。順序：加新結構 → 回填資料 → 程式改讀新結構（另一次部署）→ 確認沒問題後，再用另一次部署刪掉舊欄位。
- 回填或刪除資料的 migration：PR 裡記錄執行前後的筆數，說明怎麼確認影響範圍正確；大量改寫資料時，先用查詢確認筆數再動手。
- 會刪資料的 migration 第一次在正式環境跑之前，先確認資料庫有開自動備份：`docs/deployment-gcp.md` 建立 instance 的指令帶了 `--no-backup`。

每種變更的分次步驟、PostgreSQL 的鎖與 `lock_timeout`、用 `->online()` 建索引、回滾、合併前的檢查清單，都在 [`docs/zero-downtime-migrations.md`](../zero-downtime-migrations.md)，不要在這裡重寫一份。PR 說明要寫的回滾方式與資料安全檢查，見 `CLAUDE.md`「高風險改動與資料庫結構變更」。
