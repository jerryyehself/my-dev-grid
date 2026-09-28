# 語言慣例

跟這個 repo 有關的所有輸出，預設用**繁體中文**：

- Git commit message（標題與內文都是）
- 程式碼註解
- 對使用者的回覆/說明文字

程式碼本身（變數名、函式名、檔名、資料表/欄位名）、技術術語、套件名稱、既有的英文專有名詞（例如 Laravel、Eloquent、Sanctum）維持原文，不用硬翻。

**這條只管「跟使用者對話」跟這個 repo 本身的內容，不影響跨 session 協作文件的語言政策**——`my-dev-grid-skills` 裡的 `engineering-principles.md`、`SKILL.md` 這類跨專案共用文件基於 token 成本考量用英文主版，那是另一個 repo 的獨立政策，不代表可以把這個習慣帶進來，跟**使用者本人**互動時（不管是主 session 還是任何角色 session）一律用繁體中文，不要因為讀多了英文文件就跟著用英文回覆使用者。

# 資料填充慣例（2026-09-10 使用者確認）

需要用程式碼把資料填進資料庫時，預設寫成 **Seeder**（`database/seeders/`），不要用 migration：

- **Migration** 是一次性、不可逆的結構/資料修正紀錄，正常情況下每支只會被執行一次（例如
  `..._reclassify_vue3_topic_from_packagetool_to_framework.php` 這種單次錯誤分類回填）。
- **Seeder** 用在**可能需要重複執行/重新套用**的資料填充——例如 relation 預設資料
  （`RelationSeeder`）、GitHub repo 快照（`GitHubReposSnapshotSeeder`）——可以隨時用
  `php artisan db:seed --class=X` 手動重跑，不會綁進 migration 歷史、也不會被
  `php artisan migrate`（例如部署流程）自動觸發。

之後任何「幫我把 XX 資料填進去」這類討論，預設照這個慣例寫成 seeder，不用每次重新問。

# Git commit/push 授權（2026-09-12 使用者確認）

一般的 commit/push（審查過的變更、正常工作流程的一部分）不用每次都先問過使用者才動作，做完覺得可以收尾了就直接 commit/push，不用停下來等確認。

這不影響既有的安全防護——force push、`git reset --hard`、刪分支、跳過 hook（`--no-verify`）這類本來就該格外小心的動作，不受這條影響，一樣預設先跟使用者確認過再做。

**2026-09-12 追加**：開 PR 也一併解除「每次都要先問」的限制——分支 push 上去之後，覺得可以開 PR 就直接開，不用等使用者點頭。

# 高風險改動與資料庫結構變更（2026-09-28 使用者同意採用）

參考保哥（Will Huang）的 [AI Coding Agent Guidelines](https://gist.github.com/doggy8088/dc9a6891c35356b29a79f1e4ccaef651)（「High-Risk Change Protocol」與「Data Migration and Schema Change Guidelines」兩節），改寫成符合這個 repo 部署方式的版本。

## 哪些算高風險

- 登入與授權：Sanctum、Socialite（Google/LINE）、Policy、`routes/web.php` 的 `/auth/*`
- 資料庫 migration，以及任何會刪除或大量改寫資料的程式
- 部署管線：`.github/workflows/deploy-cloud-run.yml`、`Dockerfile`、`docker/`、Secret Manager／環境變數

這些改動開 PR 前，PR 說明裡要寫：
1. **回滾方式**：具體指令，不是「必要時回滾」。程式碼退版用 `gcloud run services update-traffic <服務名> --region=asia-east1 --to-revisions=<上一個 revision>=100`；資料庫退版要看 migration 的 `down()` 能不能在不掉資料的情況下還原，不能的話要寫明補救方式。
2. **驗證範圍**：CI 的 PHPUnit（sqlite 與 pgsql 兩種都要過），加上針對這次改動的手動確認步驟。
3. **資料安全檢查**：會不會誤刪資料、影響範圍有沒有限制在該限制的地方。

合併前一律先問使用者（對應 `my-dev-grid-skills` 三層判斷的第 2 層），就算 CI 全過也一樣。部署後看一次 Cloud Logging，確認沒有新錯誤。

## 資料庫結構變更的順序

部署流程是「先用 Cloud Run Job 跑 `php artisan migrate --force`，再部署新版程式」，所以 **migration 跑完到新版上線之間，舊版程式還在用新的資料庫結構接流量**。因此：

- **只做向下相容的新增**：加欄位（可為 null 或有預設值）、加資料表、加索引。舊版程式看不到新欄位也能正常運作。
- **刪除或改名欄位，不要跟改程式放在同一次部署**。順序是：
  1. 加新結構
  2. 回填資料（寫成 migration 或 seeder，依上面「資料填充慣例」判斷）
  3. 程式改讀新結構（另一次部署）
  4. 確認沒問題後，再用另一次部署刪掉舊欄位
- **回填或刪除資料的 migration**：PR 裡記錄執行前後的筆數，說明怎麼確認影響範圍正確；大量改寫資料時，先用查詢確認筆數再動手。
- **正式環境第一次跑會刪資料的 migration 前**，先確認 Cloud SQL 有開自動備份——`docs/deployment-gcp.md` 建立 instance 的指令帶了 `--no-backup`。
