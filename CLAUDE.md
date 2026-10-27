# 語言慣例

跟這個 repo 有關的所有輸出，預設用**繁體中文**：

- Git commit message（標題與內文都是）
- 程式碼註解
- 對使用者的回覆/說明文字

程式碼本身（變數名、函式名、檔名、資料表/欄位名）、技術術語、套件名稱、既有的英文專有名詞（例如 Laravel、Eloquent、Sanctum）維持原文，不用硬翻。

**controller 的 PHPDoc、`#[Endpoint]` 文字，以及 Resource／FormRequest 陣列 key 正上方的註解，都會原樣公開在 API 文件（`/docs/api`）**，寫法見 [`docs/conventions/api.md`](docs/conventions/api.md)「API 文件（Scramble）」。

**這條只管「跟使用者對話」跟這個 repo 本身的內容。**跟使用者本人互動時一律用繁體中文，不要因為讀多了英文文件就跟著用英文回覆使用者。

# 程式慣例

檔案結構、命名、API、資料（migration／seeder、結構變更的順序）、測試、安全、開 issue 的規則寫在 [`docs/conventions/`](docs/conventions/README.md)，Claude、Codex、人都照同一份。新增或搬動檔案前先讀 `structure.md` 與 `naming.md`；要把資料填進資料庫、或改資料庫結構前先讀 `data.md`。

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
1. **回滾方式**：具體指令，不是「必要時回滾」。程式碼退版用 `gcloud run services update-traffic <服務名> --region=asia-east1 --to-revisions=<上一個 revision>=100`。退版會維持到下一次部署成功：workflow 檢查通過後會自己把流量切到新 revision，修好的 PR 部署完就恢復，不用手動切回 `--to-latest`；修好之前要擋住其他 PR 的部署，先 `gh workflow disable deploy-cloud-run.yml`。**部署失敗（新 revision 沒通過檢查）時流量沒動，不用退版，也不要手動跑 `--to-latest`**——沒過檢查的 revision 還是 LATEST，會把流量切給它（見 `docs/zero-downtime-migrations.md` §6.1）；資料庫退版要看 migration 的 `down()` 能不能在不掉資料的情況下還原，不能的話要寫明補救方式。
2. **驗證範圍**：CI 的 PHPUnit（sqlite 與 pgsql 兩種都要過），加上針對這次改動的手動確認步驟。動到部署管線（`Dockerfile`、`docker/`、workflow、環境變數）、日誌／middleware／`bootstrap/app.php`／`config/`，或 `composer.lock` 相依套件的 PR，請求合併前要先跑 `scripts/smoke-test.sh`（用 repo 的 Dockerfile 建映像檔，接暫存 PostgreSQL，以 production 設定跑起來再探測），把它印出的摘要表貼到 PR（貼成 PR 留言也可以）。跑不起來就在 PR 裡寫明沒跑和原因，不要默默略過。這個 repo 是公開的，貼出去的只寫結果，不要帶內部討論或使用者的原話。
3. **資料安全檢查**：會不會誤刪資料、影響範圍有沒有限制在該限制的地方。

合併前一律先問使用者，就算 CI 全過也一樣。部署後看一次 Cloud Logging，確認沒有新錯誤。

## 資料庫結構變更的順序

migration 跑完到新版上線之間，舊版程式還在用新的資料庫結構接流量，所以只做向下相容的新增，刪除或改名欄位要分次部署。規則見 [`docs/conventions/data.md`](docs/conventions/data.md)，分次部署的範例、PostgreSQL 的鎖、回滾做法與合併前檢查清單見 [`docs/zero-downtime-migrations.md`](docs/zero-downtime-migrations.md)。
