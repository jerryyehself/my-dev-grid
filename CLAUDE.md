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
