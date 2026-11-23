# 上線、合回 `develop` 與退版

這份是上線流程唯一的規則來源：`CLAUDE.md`、workflow 註解與其他文件只寫一句話加連結到這裡。切 release 分支、開上線 PR、合回 `develop`、修補與退版前讀一次。

§1–§8 是兩個 repo 共通的流程，前端（`my-dev-grid-front`）的 `docs/release.md` 用同樣的章節與寫法；§9 起是這個 repo 自己的部署目標、migration 與備份。

## 1. 分支與上線時間

- `main` 接正式環境部署（§9），`develop` 是開發用的整合分支：合進 `develop` 不會部署到任何地方，只跑 CI。
- 平常的 PR 從 `origin/develop` 開、對 `develop`，只改文件的也一樣，所以 `main` 上的文件會等到下次上線才更新。clone 裡沒有 `origin/develop` 的話，先 `git fetch origin develop:refs/remotes/origin/develop`。
- 每週一上線。要不要上線一律先問使用者，就算 CI 全過也一樣。
- 前後端同一天上線時，後端先上、部署成功後再上前端，前端用到的新 API 才會已經在正式環境。
- CI 在開往 `main` 與 `develop` 的 PR 都會跑，所以從 `release/*` 開往 `main` 的上線 PR 也會跑。
- 預設分支維持 `main`：`main` 的保護規則與 Dependabot 的安全性更新都跟著預設分支。`develop` 有保護規則擋刪除與 force push。

## 2. 切 release 分支

每週一上線前，從 `develop` 切出這次要上線的分支，上線只看這條分支，`develop` 照常往前：

```bash
git fetch origin develop
git switch -c release/vX.Y.Z origin/develop
git push -u origin release/vX.Y.Z
```

- 切完之後才合進 `develop` 的 PR，搭下一次上線。
- `CHANGELOG.md` 的版本段（`## [X.Y.Z] - YYYY-MM-DD`）寫在 release 分支上，不寫在 `develop`。
- release 分支上只放版本段與上線期間的修正（§4），不在上面開發。

## 3. 上線 PR

- 從 `release/vX.Y.Z` 開 PR 到 `main`，說明照 §9（部署前後要確認的事）與 §10（migration 清單）寫。
- 合併前一律先問使用者，就算 CI 全過也一樣。問的時候附上上線 PR 目前的 head SHA：使用者同意的是那個 SHA。合併前再看一次 head（`gh pr view <PR> --json headRefOid`），跟同意時不同就要重新確認。
- 用 squash 合併：`main` 上每次上線就是一個 commit，`release.yml` 依 `CHANGELOG.md` 在它上面打 tag、建 Release，退版也只要 revert 這一個 commit。

## 4. 上線期間的修正

- 上線 PR 開著的期間發現這次上線要修的問題：從 `origin/release/vX.Y.Z` 開分支、PR 對 `release/vX.Y.Z`。合併後上線 PR 的 head 會變，照 §3 重新確認。
- 修正不用另外開 PR 到 `develop`：上線後照 §5 把 `main` 合回 `develop`，修正會跟著回去。
- 不是這次上線要修的，照常開到 `develop`，搭下一次上線。
- `tests.yml` 只監聽開往 `main`、`develop` 的 PR，開往 release 分支的修正 PR 本身不會跑 CI；修正合進 release 分支後，上線 PR 會在新的 head 上重跑。
- 上線 PR 合併前，開往這條 release 分支的修正 PR 要先合併或關掉（理由見 §5 第 4 步）。

## 5. 合併後：把 `main` 合回 `develop`

不合回去，下次上線時 `main` 跟 `develop` 的歷史會對不上：

1. 從 `origin/develop` 開分支，`git merge --no-ff origin/main`，一般 push，不是 force push。release 分支上的修正會在這一步回到 `develop`；跟 `develop` 之後的改動衝突的話，在這個 merge commit 裡解，解法不確定就停下來問使用者。
2. `develop` 設了必要檢查，沒跑過 CI 的 merge commit 直推會被擋：先把這個 merge commit 推到 `claude/merge-main-vX.Y.Z` 分支、開 PR 到 `develop` 只為了跑 CI。
3. CI 全綠後用 `git push origin <同一個 SHA>:refs/heads/develop` 快轉推送。不要在網頁上合併那個 PR（squash 或 rebase 會改寫歷史）；快轉推不上去就停下來問使用者。
4. repo 開了「合併後自動刪除分支」，`release/vX.Y.Z` 在上線 PR 合併後會被自動刪掉，這是預期的；還開著、對象是它的 PR 也會跟著關掉。`develop` 有保護規則，不會被刪。

## 6. 退版

用 `git revert <上線的 squash commit>` 開 PR 到 `main`，走 §7 的流程（從 `main` 開、合併前先問使用者、合完照 §5 合回 `develop`）。在部署平台上立即切回上一版的做法，見 §9。

## 7. 嚴重安全問題的修補（D-174）

- 從 `main` 開分支、PR 對 `main`，合併前先問使用者，合併即部署；合完照 §5 把 `main` 合回 `develop`。要發版本的話，在這個 PR 的 `CHANGELOG.md` 加修訂號版本。退版用的 revert PR 也走這條。
- 「嚴重」指：自己程式碼的漏洞會外洩私人資料，或能繞過登入、授權；或相依套件公告為 high／critical，而且正式環境真的會跑到有漏洞的程式路徑。只有開發用的套件、或沒呼叫到的路徑，不算嚴重。判斷不出來就先問使用者，附上公告和判斷理由。
- 其他修補，包括一般 bug、不嚴重的安全性或相依套件更新，都從 `develop` 開，跟每週上線一起上。

## 8. Dependabot

- Dependabot 的安全性更新只開在預設分支，PR 一定對 `main`。嚴重的照 §7；不嚴重的等上線 PR 合併後再合，接著照 §5 把 `main` 合回 `develop`。
- 這個 repo 的 `.github/dependabot.yml` 不設 `target-branch`，版本更新的 PR 也對 `main`，處理方式同上。之後也不要把 `target-branch` 改成 `develop`。

## 9. 部署目標：Cloud Run

### 9.1 哪些情況會部署

- `deploy-cloud-run.yml` 的 push、每週排程、手動執行都只在 `main` 上部署，手動執行選了其他分支會跳過。`develop` 與 `release/*` 都不部署。
- `release.yml` 也只在 `main` 上建 Release。

### 9.2 合進 `main` 前後的確認

上線 PR 與 §7 的修補 PR 都適用：

- 這一批只要有任何一項 `CLAUDE.md`「高風險改動」列的東西，就照那一節把回滾方式、驗證範圍與資料安全檢查（含上線前、上線後的確認）寫進內部上線紀錄，上線 PR 只寫「已依內部清單完成」。
- 上線 PR 合併前做好 §11 的備份。
- 部署（合進 `main`）後由使用者看一次 Cloud Logging，確認沒有新錯誤：Claude 的雲端沙盒沒有 GCP 權限，部署後提醒使用者去看，不自己宣稱看過。

### 9.3 立即退版

先照內部上線紀錄的回滾指令把 Cloud Run 流量切回上一個 revision（指令、退版維持多久、怎麼擋住之後的部署見 [`zero-downtime-migrations.md`](zero-downtime-migrations.md) §6.1），再照 §6 開 revert PR。

部署失敗（新 revision 沒通過檢查）時流量沒動，不用退版，也不要手動跑 `--to-latest`。

## 10. 資料庫 migration

- 上線 PR 列出這一批所有的 migration（`git diff --name-only origin/main...origin/release/vX.Y.Z -- database/migrations`，沒有就寫「無」）。有 migration 的話，逐支照 [`zero-downtime-migrations.md`](zero-downtime-migrations.md) §7 再檢查一次。
- **分次上線**：資料庫結構的 expand／contract 每一步要分開上線，不能跟前一步在同一個上線 PR 裡。後一步的 PR 要等前一步已經上線（部署成功、流量切到新版）之後才合進 `develop`；等不到下週一的話，先問使用者要不要另外加開一次上線。細節見 [`zero-downtime-migrations.md`](zero-downtime-migrations.md) §3.0。
- **帶 migration 的 PR 合進 `develop`**：合進 `develop` 還不會碰到正式資料庫，真正跑 migration 的是上線那一次。這次的做法有明文規則或已記錄的決定涵蓋（[`conventions/data.md`](conventions/data.md)、[`zero-downtime-migrations.md`](zero-downtime-migrations.md) 的規則，或 issue／PR 裡已經定案的設計），而且 CI 的 sqlite 與 pgsql 都過，就可以直接合進 `develop`，不用先問；找不到明文依據就先問。其他高風險改動（登入與授權、部署管線）合進 `develop` 前照舊先問。

## 11. 上線前備份

### 11.1 必做：Cloud SQL on-demand 備份

上線 PR 合併前，由使用者在 Cloud SQL 做一次 on-demand 備份（指令見 [`zero-downtime-migrations.md`](zero-downtime-migrations.md) §6.3），確認備份完成後才合併。Claude 的雲端沙盒沒有 GCP 權限，不自己做、也不宣稱做過。退版需要還原資料時，先用這份。

### 11.2 選做：本機異地備份

想在本機另外留一份時，從下面三條路挑一條，越前面要裝的東西越少。`<PROJECT_ID>`、`<REGION>`、`<INSTANCE>`、`<DATABASE>`、`<BUCKET>`、`<DB_USER>` 換成實際的值。

1. **主控台點選，不用安裝任何工具**：Cloud SQL 執行個體 →「匯出」→ 格式選 SQL、選資料庫、目的地選一個 Cloud Storage bucket（沒有就新建）→ 匯出完到 Cloud Storage 下載檔案 → 下載後把 bucket 裡那份刪掉。
2. **Cloud Shell**（主控台內建的瀏覽器終端機）：

   ```bash
   gcloud sql export sql <INSTANCE> gs://<BUCKET>/<FILE>.sql --database=<DATABASE>
   gcloud storage cp gs://<BUCKET>/<FILE>.sql .
   cloudshell download <FILE>.sql
   gcloud storage rm gs://<BUCKET>/<FILE>.sql
   ```

   匯出時回報權限不足的話，要先給執行個體的服務帳戶這個 bucket 的寫入權限。
3. **本機指令**：裝 gcloud、Cloud SQL Auth Proxy、PostgreSQL 18 版用戶端。本機 `pg_dump` 的版本不能低於正式環境的 PostgreSQL 18。

   ```bash
   gcloud auth application-default login
   cloud-sql-proxy <PROJECT_ID>:<REGION>:<INSTANCE> --port 5432
   # 另開一個終端機
   pg_dump -Fc -h 127.0.0.1 -p 5432 -U <DB_USER> -d <DATABASE> -f <FILE>.dump
   pg_restore -l <FILE>.dump > /dev/null && echo 可以讀
   ```

共通注意：

- 檔案含使用者的登入資料：放加密的磁碟，不放進 repo 或雲端同步資料夾。
- 這份不取代 §11.1 的 on-demand 備份，退版時一樣先用 on-demand 還原。
- 費用：以目前的資料量不到 1 美分；bucket 裡的檔案下載完就刪。
