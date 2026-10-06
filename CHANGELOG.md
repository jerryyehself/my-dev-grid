# 更新紀錄

「IN / ARCHIVE」（`jerrylib.com`）後端 API 的所有重要變更都記錄在這個檔案。

格式依循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/)，版本號依循[語意化版本](https://semver.org/lang/zh-TW/)。後端與前端（[`my-dev-grid-front`](https://github.com/jerryyehself/my-dev-grid-front)）各自獨立編版號。`v1.0.0` 是網站正式公開（移除 Cloudflare Access）的那一版，在那之前的內部版本都視為預發行，不逐版列出。

## [1.0.1] - 2026-10-06

### 修正

- 更新前端建置用的 npm 依賴（僅 `package-lock.json`，不影響 PHP 執行環境），修正 `npm audit` 回報的 15 個有漏洞套件，其中 3 個為 critical：`form-data`、`shell-quote`、`tar`；其餘涵蓋 `axios`、`vite`、`vue`、`rollup`、`postcss` 等。

## [1.0.0] - 2026-10-04

第一個公開版本。Laravel 13 寫成的 API，部署在 Cloud Run。

### 新增

API 資源

- `scopes`（階層分類號）、`relations`（述詞）、`documentations`（文件／文章）、`techniques`（技術）、`implementations`（實作／專案）五種資源，讀取（`index`／`show`）公開，寫入（`store`／`update`／`destroy`）需要登入，權限由 `app/Policies` 判斷（#6, #14, #17）。
- 文章內文以 Markdown 原文存在 `documentations.body`，不做 trim，也不存渲染後的 HTML（#53）。
- Scope 的詳情與一覽附各分類底下的實體計數，並修掉查詢中的 N+1（#64）。
- `GET /api/relations/{id}/edges` 分頁列出使用某條述詞的邊（#64）。
- 分類號 `class_number` 一律由父類推導，`call_number` 可以留空新增（#61, #67）。
- 資料庫可用 `ScopeSeeder`、`RelationSeeder` 重複執行填入分類與述詞，並附 `ArticleSeeder` 填入 4 篇已發布文章（#80, #84）。

本體論與關係

- 述詞全部成對可逆，`reverse_id` 一律雙向配對，且不能搶走已配對的述詞（#57, #58）。
- 述詞被任何邊引用後（含反向述詞的引用），主詞、受詞、名稱等欄位鎖定唯讀，僅備註可改；API 會回報鎖定狀態，違規時回 422 並以欄位名稱列出錯誤（#60, #63）。
- 述詞的外部詞彙出處拆成 `source_vocabulary`、`source_term` 兩個欄位，空值表示本專案自訂（#65）。
- 加入實作的衍生、伴隨、先後關係（#43）。
- `uses` 的方向為「實作 uses 技術」，與 ER model 一致（#75）。
- 技術名稱統一：大小寫與寫法變體合併成同一筆，同一技術的每個版本各自成一筆，並記錄最後一次同步看到的時間；API 的技術標籤帶版本，例如「Vue 3」（#76, #87）。

GitHub 同步

- `php artisan github:sync-repos` 抓取 GitHub 公開 repo，寫成實作與技術並建立關聯；語言與 topic 對應到技術類別（#15, #18, #24, #29, #35）。
- 正式環境每天同步一次：部署時更新 Cloud Run Job，由 Cloud Scheduler 觸發（#74）。
- 可用 `github:snapshot-repos` 存下 repo 資料的 JSON 快照，離線填充資料庫，測試不必打 GitHub API（#39, #40）。

圖譜 API

- `GET /api/graph` 回傳文件、技術、實作三族節點與它們之間的邊，邊的述詞已解析成名稱；實作節點帶建立時間，文章節點帶子類與網址（#19, #23, #73）。
- `GET /api/graph/path` 查詢起點與終點之間的最短路徑，逆向經過的那一跳回報反向述詞（#47）。

登入與授權

- email＋密碼登入、登出，Google 與 LINE 社群登入（Socialite），`GET /api/user` 查詢登入狀態；寫入權限由 Policy 判斷（#31, #32）。
- 給前端用的登入端點發 Sanctum API token，社群登入完成後把 token 放在 URL fragment 帶回前端，不進伺服器存取紀錄（commit `38b9e19`）。
- LINE 登入只申請 `openid`、`profile`，不申請 email（#90）。
- 重新整理後維持登入：access token 15 分鐘、refresh token 30 天且單次使用，放在 Partitioned httpOnly cookie；重放用過的 refresh token 會撤銷同一次登入的所有 token，從第一次登入起最多 90 天就要重新登入（#85）。
- 草稿文章只有登入的人看得到，公開的讀取端點、圖譜與路徑查詢都不回傳草稿（#72）。

部署

- 以 Dockerfile 建置映像檔，經 GitHub Actions 與 Workload Identity Federation 部署到 Cloud Run，資料庫為 Cloud SQL 的 PostgreSQL；部署時以 Cloud Run Job 執行 migration（#21, #37, #56, #70）。
- GCP 手動設定步驟包成可重複執行的腳本 `scripts/gcp-setup.sh`，另附部署與基礎架構說明文件（#42, #56, #68, #77）。
- `APP_URL` 為 https 時強制產生 https 網址，修正 OAuth 的 `redirect_uri` 變成 http（#82）。
- 部署 workflow 同一時間只跑一個，後到的排隊，不會兩個部署同時切換流量（#89）。
- CI 同時以 SQLite 與 PostgreSQL 18 跑測試（#38, #78）。

安全性

- 頻率限制，依 IP 計算：登入每分鐘 5 次、一般 API 每分鐘 60 次，社群登入與 refresh token 換發另有各自的額度（#79, #85）。
- CORS 只放行前端網址，refresh、session、登出只接受 `Origin` 等於前端網址的請求（commit `38b9e19`, #70, #85）。
- 升級 `league/commonmark` 至 2.10.3，修正兩個安全公告（#86）。

[1.0.1]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.0.1
[1.0.0]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.0.0
