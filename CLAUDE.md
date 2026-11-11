# 語言慣例

跟這個 repo 有關的所有輸出，預設用**繁體中文**：

- Git commit message（標題與內文都是）
- 程式碼註解
- 對使用者的回覆/說明文字

程式碼本身（變數名、函式名、檔名、資料表/欄位名）、技術術語、套件名稱、既有的英文專有名詞（例如 Laravel、Eloquent、Sanctum）維持原文，不用硬翻。

**controller 的 PHPDoc、`#[Endpoint]` 文字，以及 Resource／FormRequest 陣列 key 正上方的註解，都會原樣公開在 API 文件（CI 匯出的 OpenAPI JSON；本機開發環境另有 `/docs/api`，正式環境不提供）**，寫法見 [`docs/conventions/api.md`](docs/conventions/api.md)「API 文件（Scramble）」。

**這條只管「跟使用者對話」跟這個 repo 本身的內容。**跟使用者本人互動時一律用繁體中文，不要因為讀多了英文文件就跟著用英文回覆使用者。

# 程式慣例

檔案結構、命名、API、資料（migration／seeder、結構變更的順序）、測試、安全、開 issue 的規則寫在 [`docs/conventions/`](docs/conventions/README.md)，Claude、Codex、人都照同一份。新增或搬動檔案前先讀 `structure.md` 與 `naming.md`；要把資料填進資料庫、或改資料庫結構前先讀 `data.md`；寫註解或 PHPDoc 前先讀 `comments.md`。

# Git commit/push 授權（2026-09-12 使用者確認，開 PR 同日一併確認，2026-11-12 修訂）

例行步驟不用先問使用者：commit、push 到工作分支、開 PR（草稿或正式都可以）、唯讀檢查、可安全重試的動作。做完覺得可以收尾了就直接做，不用停下來等確認。

合併 PR 不在這條範圍內，照下面「分支策略」與「高風險改動與資料庫結構變更」兩節處理。

force push、`git reset --hard`、跳過 hook（`--no-verify`）這類本來就該格外小心的動作，不受這條影響，一律先跟使用者確認過再做。

遠端分支不由 Claude 刪除：repo 開了「合併後自動刪除分支」，合併過的遠端分支會自動刪掉，Claude 也沒有刪遠端分支的權限。PR 合併後只做本地端的清理（切回基底分支、刪掉已合併的本地分支與 worktree），不用先問。

# 分支策略（2026-10-27 使用者確認）

`main` 接正式環境部署（push 到 `main` 會觸發 `.github/workflows/deploy-cloud-run.yml`），`develop` 是開發用的整合分支：合進 `develop` 不會部署到任何地方，只跑 CI（`tests.yml`）。做法跟前端（`my-dev-grid-front`）一致。

- **平常的 PR**：功能分支從 `origin/develop` 開，PR 對象是 `develop`，不是 `main`。只改文件的 PR 也一樣，所以 `main` 上的文件會等到下次上線才更新。clone 裡沒有 `origin/develop` 的話，先 `git fetch origin develop:refs/remotes/origin/develop`。
- **上線**：每週一由 `develop` 開 PR 合併進 `main`，合併即部署。要不要上線一律先問使用者，就算 CI 全過也一樣。前端同一天上線時，後端先上、部署成功後再上前端，前端用到的新 API 才會已經在正式環境。
  - 上線 PR 裡把這一批寫進 `CHANGELOG.md` 的版本標題（`## [X.Y.Z] - YYYY-MM-DD`）。合併後 `release.yml` 在 `main` 上建 tag 與 Release，tag 會打在上線的那個 commit。
  - 上線 PR 說明要列出這一批所有的 migration（`git diff --name-only origin/main...origin/develop -- database/migrations`，沒有就寫「無」）。這一批只要有任何一項下面「高風險改動」列的東西，上線 PR 就照那一節寫回滾方式、驗證範圍與資料安全檢查；有 migration 的話，逐支照 `docs/zero-downtime-migrations.md` §7 再檢查一次。
  - 上線 PR 用 **squash 合併**（`main` 上一次上線就是一個 commit）。合併完一定要把 `main` 合回 `develop`：`git merge --no-ff origin/main`，一般 push，不是 force push。`develop` 的保護規則如果擋下直接 push（例如設了必要檢查），改開 `main` → `develop` 的 PR，用 merge commit 合併，不要 squash。不合回去，下次上線 PR 的歷史會對不上。
  - 退版：程式碼先照下面「高風險改動」的回滾指令把流量切回上一個 revision，再用 `git revert <squash commit>` 開 PR 到 `main`（走下面「嚴重安全問題的修補」的流程）。
- **嚴重安全問題的修補**（D-174）：從 `main` 開分支、PR 對 `main`，合併前先問使用者，合併即部署；合完同樣把 `main` 合回 `develop`。要發版本的話，在這個 PR 的 `CHANGELOG.md` 加修訂號版本。退版用的 revert PR 也走這條。
  - 「嚴重」指：自己程式碼的漏洞會外洩私人資料，或能繞過登入、授權；或相依套件公告為 high／critical，而且正式環境真的會跑到有漏洞的程式路徑。只有開發用的套件、或沒呼叫到的路徑，不算嚴重。判斷不出來就先問使用者，附上公告和判斷理由。
  - 其他修補，包括一般 bug、不嚴重的安全性或相依套件更新，都從 `develop` 開，跟每週上線一起上。
- **Dependabot**：安全性更新只會開在 repo 的預設分支，所以 Dependabot 的 PR 一定對 `main`；之後如果加 `.github/dependabot.yml`，`target-branch` 也不要改成 `develop`。嚴重的照上一條處理；不嚴重的先留著，上線日等上線 PR 合併後再合，接著照樣把 `main` 合回 `develop`。
- **帶 migration 的 PR 合進 `develop`**：合進 `develop` 還不會碰到正式資料庫，真正跑 migration 的是上線那一次。這次的做法有明文規則或已記錄的決定涵蓋（`docs/conventions/data.md`、`docs/zero-downtime-migrations.md` 的規則，或 issue／PR 裡已經定案的設計），而且 CI 的 sqlite 與 pgsql 都過，就可以直接合進 `develop`，不用先問；找不到明文依據就先問。其他高風險改動（登入與授權、部署管線）合進 `develop` 前照舊先問。
- **分次上線**：資料庫結構的 expand／contract 每一步要分開上線，不能跟前一步在同一個上線 PR 裡。後一步的 PR 要等前一步已經上線（部署成功、流量切到新版）之後才合進 `develop`；等不到下週一的話，先問使用者要不要另外加開一次上線。
- **只有 `main` 會部署**：`deploy-cloud-run.yml` 的 push、每週排程、手動執行都只在 `main` 上部署，手動執行選了其他分支會跳過；`release.yml` 也只在 `main` 上建 Release。
- **預設分支維持 `main`**：`main` 的保護規則是用「預設分支」指定的，預設分支改成 `develop`，保護就會跟著移過去；Dependabot 的安全性更新也是開在預設分支。
- **`develop` 有保護規則擋刪除與 force push**。repo 開了「合併後自動刪除分支」，上線 PR 的來源分支就是 `develop`，靠這條規則才不會在合併後被自動刪掉；上線 PR 合併後確認一下 `develop` 還在。

# 高風險改動與資料庫結構變更（2026-09-28 使用者同意採用）

參考保哥（Will Huang）的 [AI Coding Agent Guidelines](https://gist.github.com/doggy8088/dc9a6891c35356b29a79f1e4ccaef651)（「High-Risk Change Protocol」與「Data Migration and Schema Change Guidelines」兩節），改寫成符合這個 repo 部署方式的版本。

## 哪些算高風險

- 登入與授權：Sanctum、Socialite（Google/LINE）、Policy、`routes/web.php` 的 `/auth/*`
- 資料庫 migration，以及任何會刪除或大量改寫資料的程式
- 部署管線：`.github/workflows/deploy-cloud-run.yml`、`Dockerfile`、`docker/`、Secret Manager／環境變數

這些改動開 PR 前，PR 說明裡要寫：
1. **回滾方式**：具體指令，不是「必要時回滾」。程式碼退版用 `gcloud run services update-traffic <服務名> --region=asia-east1 --to-revisions=<上一個 revision>=100`。退版會維持到下一次部署成功：workflow 檢查通過後會自己把流量切到新 revision，修好的版本合進 `main`、部署完就恢復，不用手動切回 `--to-latest`；修好之前要擋住其他上線或修補的部署，先 `gh workflow disable deploy-cloud-run.yml`。**部署失敗（新 revision 沒通過檢查）時流量沒動，不用退版，也不要手動跑 `--to-latest`**——沒過檢查的 revision 還是 LATEST，會把流量切給它（見 `docs/zero-downtime-migrations.md` §6.1）；資料庫退版要看 migration 的 `down()` 能不能在不掉資料的情況下還原，不能的話要寫明補救方式。
2. **驗證範圍**：CI 的 PHPUnit（sqlite 與 pgsql 兩種都要過），加上針對這次改動的手動確認步驟。動到部署管線（`Dockerfile`、`docker/`、workflow、環境變數）、日誌／middleware／`bootstrap/app.php`／`config/`，或 `composer.lock` 相依套件的 PR，請求合併前要先跑 `scripts/smoke-test.sh`（用 repo 的 Dockerfile 建映像檔，接暫存 PostgreSQL，以 production 設定跑起來再探測），把它印出的摘要表貼到 PR（貼成 PR 留言也可以）。跑不起來就在 PR 裡寫明沒跑和原因，不要默默略過。這個 repo 是公開的，貼出去的只寫結果，不要帶內部討論或使用者的原話。
3. **資料安全檢查**：會不會誤刪資料、影響範圍有沒有限制在該限制的地方。

合併前一律先問使用者，就算 CI 全過也一樣（唯一的例外是合進 `develop`、做法有明文依據的 migration PR，見「分支策略」）。部署（合進 `main`）後看一次 Cloud Logging，確認沒有新錯誤。

## 資料庫結構變更的順序

migration 跑完到新版上線之間，舊版程式還在用新的資料庫結構接流量，所以只做向下相容的新增，刪除或改名欄位要分次上線（一次上線＝一次部署，見「分支策略」）。規則見 [`docs/conventions/data.md`](docs/conventions/data.md)，分次部署的範例、PostgreSQL 的鎖、回滾做法與合併前檢查清單見 [`docs/zero-downtime-migrations.md`](docs/zero-downtime-migrations.md)。
