# 上線與退版的步驟

`CLAUDE.md`「分支策略」寫原則（每週一由 `develop` 開 PR 進 `main`、要不要上線先問使用者、squash 合併、合完把 `main` 合回 `develop`），這份寫開上線 PR、合併後、退版時才用得到的步驟。開上線 PR 前讀一次。

## 1. 上線 PR 要寫什麼

- 把這一批寫進 `CHANGELOG.md` 的版本標題（`## [X.Y.Z] - YYYY-MM-DD`）。合併後 `release.yml` 在 `main` 上建 tag 與 Release，tag 會打在上線的那個 commit。
- 列出這一批所有的 migration（`git diff --name-only origin/main...origin/develop -- database/migrations`，沒有就寫「無」）。有 migration 的話，逐支照 [`zero-downtime-migrations.md`](zero-downtime-migrations.md) §7 再檢查一次。
- 這一批只要有任何一項 `CLAUDE.md`「高風險改動」列的東西，就照那一節把回滾方式、驗證範圍與資料安全檢查（含上線前、上線後的確認）寫進內部上線紀錄，上線 PR 只寫「已依內部清單完成」。

## 2. 合併後：把 `main` 合回 `develop`

上線 PR 用 squash 合併（`main` 上一次上線就是一個 commit）。合併完一定要把 `main` 合回 `develop`，不合回去，下次上線 PR 的歷史會對不上：

1. `git merge --no-ff origin/main`，一般 push，不是 force push。
2. `develop` 設了必要檢查，沒跑過 CI 的 merge commit 直推會被擋：先把這個 merge commit 推到 `claude/merge-main-vX.Y.Z` 分支、開 PR 到 `develop` 只為了跑 CI。
3. CI 全綠後用 `git push origin <同一個 SHA>:refs/heads/develop` 快轉推送。不要在網頁上合併那個 PR（squash 或 rebase 會改寫歷史）；快轉推不上去就停下來問使用者。
4. 確認 `develop` 還在：repo 開了「合併後自動刪除分支」，上線 PR 的來源分支就是 `develop`，靠 `develop` 的保護規則（擋刪除與 force push）才不會被自動刪掉。

前端（`my-dev-grid-front`）用同一套做法。

## 3. 退版

程式碼先照內部上線紀錄的回滾指令把流量切回上一個 revision（指令、退版維持多久、怎麼擋住之後的部署見 [`zero-downtime-migrations.md`](zero-downtime-migrations.md) §6.1），再用 `git revert <squash commit>` 開 PR 到 `main`，走 `CLAUDE.md`「嚴重安全問題的修補」的流程。

部署失敗（新 revision 沒通過檢查）時流量沒動，不用退版，也不要手動跑 `--to-latest`。

## 4. 哪些情況會部署

- `deploy-cloud-run.yml` 的 push、每週排程、手動執行都只在 `main` 上部署，手動執行選了其他分支會跳過。
- `release.yml` 也只在 `main` 上建 Release。
