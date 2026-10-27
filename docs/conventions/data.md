# 資料：migration、seeder、結構變更

## migration 還是 seeder

需要用程式碼把資料填進資料庫時，寫成 seeder（`database/seeders/`），不要寫成 migration。

- **預設資料**（分類、述詞這類程式要有才能運作的資料）**只放在 seeder**，加進 `ReferenceDataSeeder` 呼叫的 seeder 裡。部署流程在 `php artisan migrate --force` 之後自動跑 `php artisan db:seed --class=ReferenceDataSeeder --force`（`.github/workflows/deploy-cloud-run.yml`「Seed reference data」），新加的預設資料跟著部署進正式環境，舊資料庫不用另外寫 migration 補。本機的 `migrate:fresh --seed`（`DatabaseSeeder`）跑的也是同一支。
- **migration**：結構變更，以及修正**既有**資料（改名、改分類、合併重複），正常情況下每支只執行一次。例子：`2026_09_09_000000_reclassify_vue3_topic_from_packagetool_to_framework.php`（修正一次錯誤分類）。seeder 只會「沒有才建」，改不到已經存在的資料，所以這類修正還是寫 migration。
- **其他 seeder**：不在部署時跑，需要時手動 `php artisan db:seed --class=X`。例如 `ArticleSeeder`（文章內容，會蓋回 fixture）、`GitHubReposSnapshotSeeder`（沒有 GitHub API 時的替代資料）、`RepoLineageRelationsSeeder`（要先有同步進來的 repo）。
- **舊做法，不要再用**：在部署會自動跑 seeder 之前，新增的預設資料要 migration、seeder 各放一份（migration 補舊資料庫、seeder 建新資料庫，再用測試檢查兩份一致），例如 `2026_09_30_190100_add_technique_version_relations.php`＋`RelationSeeder`＋`RelationSourceVocabularyTest`。已經跑過的 migration 留著不刪，`RelationSeeder` 也還在讀它的常數；之後新增預設資料只改 seeder。

加進 `ReferenceDataSeeder` 的 seeder，每次部署都會在已經有資料的正式環境跑，要符合 `ReferenceDataSeeder` 的 PHPDoc 列的條件：可以重跑、不蓋掉後台改過的內容、不把後台刪掉的建回來、不打外部服務、不讀密鑰、不建立使用者。另外，seeder 跑完到新版上線之間，舊版程式已經讀得到新的預設資料，所以預設資料也只能新增，而且要讓上一版程式讀得懂（跟下面「結構變更的順序」同一個道理）。

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

- 要能重跑：跑兩次不能多出資料、不能蓋掉後台改過的內容、不能把後台刪掉的建回來。可以在後台編輯的資料，用 `withTrashed()->firstOrCreate(['name' => …], …)`（`ScopeSeeder`、`RelationSeeder`）；內容完全由 fixture 決定的，用 `updateOrCreate`（`ArticleSeeder`，所以不能在部署時跑）。由 `SeederIdempotencyTest`、`ReferenceDataSeederTest` 守住。
- 資料之間的連結（例如述詞的反向關係）也算後台可以改的內容：只在這次新建的那一筆上設，兩筆都早就存在就不動（`RelationSeeder::pairReverse()`）。
- 以 name 為鍵的限制：後台把預設資料改名之後，seeder 找不到原本的名字，下次部署會用原名再建一筆。預設資料要改名，改 seeder 裡的名字，既有的那一筆另寫 migration 改。
- 不要寫死資料的 id。SQLite 測試每次 rollback 會重設流水號，PostgreSQL 的 sequence 不會，寫死的 id 只在 SQLite 上剛好會過。用名稱查 id：`Scope::where('name', 'project')->value('id')`。

## 軟刪除

- `Scope`、`Relation`、`Documentation`、`Technique`、`Implementation` 都用 `SoftDeletes`。
- 用 query builder（`DB::table()`、`DB::query()`）查這些表時，全域 scope 不會生效，每個查詢都要自己加 `whereNull('deleted_at')`（`RelationEdgeQuery`）。

## 結構變更的順序

部署流程是：先用 Cloud Run Job 跑 `php artisan migrate --force`、再跑預設資料 seeder（`ReferenceDataSeeder`），然後才部署新版程式。所以 migration 跑完到新版上線之間，**舊版程式還在用新的資料庫結構接流量**。

- 只做向下相容的新增：加欄位（可以是 null，或有預設值）、加資料表、加索引。
- 刪除或改名欄位，不要跟改程式放在同一次部署。順序：加新結構 → 回填資料 → 程式改讀新結構（另一次部署）→ 確認沒問題後，再用另一次部署刪掉舊欄位。
- 回填或刪除資料的 migration：PR 裡記錄執行前後的筆數，說明怎麼確認影響範圍正確；大量改寫資料時，先用查詢確認筆數再動手。
- 會刪資料的 migration 第一次在正式環境跑之前，先確認資料庫有開自動備份：`docs/deployment-gcp.md` 建立 instance 的指令帶了 `--no-backup`。

每種變更的分次步驟、PostgreSQL 的鎖與 `lock_timeout`、用 `->online()` 建索引、回滾、合併前的檢查清單，都在 [`docs/zero-downtime-migrations.md`](../zero-downtime-migrations.md)，不要在這裡重寫一份。PR 說明要寫的回滾方式與資料安全檢查，見 `CLAUDE.md`「高風險改動與資料庫結構變更」。
