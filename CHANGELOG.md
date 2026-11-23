# 更新紀錄

「IN / ARCHIVE」（`jerrylib.com`）後端 API 的所有重要變更都記錄在這個檔案。

格式依循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/)，版本號依循[語意化版本](https://semver.org/lang/zh-TW/)。後端與前端（[`my-dev-grid-front`](https://github.com/jerryyehself/my-dev-grid-front)）各自獨立編版號。`v1.0.0` 是網站正式公開（移除 Cloudflare Access）的那一版，在那之前的內部版本都視為預發行，不逐版列出。

## [1.4.1] - 2026-11-23

### 新增

- 文章匯入時把段落單位存進新資料表 `article_units`（只新增表，不改既有的表）：鍵是 `(article_id, unit_id)`，重新匯入時依明確 ID、標題、內容相似度對上原本的單位，原稿裡不見的單位只填 `removed_at`、不刪列。原稿標題的 `{#id}` 重複或超過 255 字元時，`GET /api/article-sources` 列成 invalid，匯入回 409（#225）。
- `article_units` 新增 `is_container`、`footnotes`、`chunks` 三欄（只新增、不回填）：腳註附給引用它的單位，只有子節、自己幾乎沒有內文的 H2 標成容器，正文超過 1500 字的單位切出檢索用的子片段（#226）。
- 後台新增或修改文章（`POST /api/documentations`、`PUT /api/documentations/{id}`）時，在同一個 transaction 裡同步段落單位；只改標題等欄位時單位不動，原稿標題的 `{#id}` 重複或太長時回 422（`errors.body`），文件的變更一併回滾（#228）。

### 變更

- 同步不再讀 `config/repo_sync.php` 的 `skip_technique_edges` 清單（整份檔案刪除），改成只看資料庫裡的改作連結：專案有一條沒刪除的文件連結、述詞沿改用解析後是 `isBasisFor`，就不寫技術連線，已經連上的舊連線不碰。建置產物（`isBasedOnProject`）照常同步技術連線（#221）。
- 分類說明（`comment`）的長度上限從 100 字放寬到 255 字，新增與修改分類都適用，資料表不用改（#224）。

### 修正

- OAuth 回呼的 state 不符（舊分頁、重複點登入、session 過期）時不再回 500：照常記一筆 `authn_login_fail`（reason `invalid_state`），改成 302 導回並帶 `auth_error=state_expired`。session 模式導回首頁，token 模式導回前端網址；其他例外維持原樣。前端顯示重試提示另行處理（#220）。
- 新增資料修正 migration `2026_11_23_000000_link_my_profile_to_origin_and_downgrade_topic_only_edges`：把 my-profile 以 `isBasisFor` 連到原作（新增一筆已發布、`repository` 分類的文件），並清掉它的技術連線；只有 topic 證據、沒有語言或套件證據的「使用」連線（`usedBy`）改成「關於」（`subjectOfProject`），站主手動指定過的不動。上線前看過名單後決定的例外寫在 migration 裡，以專案與技術名稱比對：9 條其實有用到的維持「使用」並標成手動指定（之後同步不再改），my-dev-grid 與 Vue（兩條）、tailwind 的三條整列刪除；名單上配對不到的只記進日誌。動到的每一列先記進備份表 `sync_predicate_fix_backups`（含動作與原因），上線後該表會保留。`GET /api/graph` 與 `GET /api/graph/path` 上相關專案的技術連線會跟著變（#221）。

## [1.4.0] - 2026-11-20

### 新增

- 述詞棄用：已被連結用到的述詞不能刪（回 422，附 `suggested_action: "deprecate"`），改用 `PATCH /api/relations/{relation}/deprecation` 標為棄用，可指定改用的述詞（`replaced_by_id`），反向述詞一起處理。新增或更新連結時不能再用已棄用的述詞，重送既有連結照常通過；`/api/relations`、`/api/graph`、`/api/graph/path` 只標記棄用、不濾掉。`relations` 新增 `deprecated_at`、`replaced_by_id` 兩個可為 NULL 的欄位，不回填。同步與文章匯入依名稱取用的系統述詞也能棄用，但一定要指定改用對象，程式沿改用鏈找到仍在使用的述詞；舊的連結照舊，新配對用改用對象。刪分類前的引用檢查一併計入「被設為改用對象」（#205）。
- 新增 `isBasisFor`／`isBasedOn`（文件與實作之間的改作來源）、`isBasedOnProject`／`isBasisForProject`（建置產物與來源專案）、`subjectOfProject`／`projectAbout`（專案「關於」某個技術，而不是使用它）三組述詞（#203, #209）。
- GitHub 同步依證據分技術與專案的連線：程式裡有證據（語言統計、套件清單）寫 `usedBy`，只出現在 topic 的寫 `subjectOfProject`，之後看到程式證據才升成 `usedBy`、不降級。專案是外部 repo 的改作（有 `isBasisFor` 文件連結），或在 `config/repo_sync.php` 的 `skip_technique_edges` 清單內時，不寫技術連線（#203, #209）。
- 後台指定技術連線的述詞：`PATCH /api/implementations/{implementation}/techniques/{technique}` 改這一對的述詞並標成手動指定，`DELETE .../manual` 清掉標記、交還給同步；`PUT /api/implementations/{id}`、`PUT /api/techniques/{id}` 新增或改了述詞的技術連線也標成手動指定。手動指定過的連線，同步不改述詞、不另長一列。`technique_implementation` 新增可為 NULL 的 `manually_set_at` 欄位，回應只在登入時帶出（#209）。
- 同步補齊技術連線：多讀 `apps/*`、`packages/*` 這一層的套件清單（最多 10 份，不讀 `node_modules`、`vendor`、tests、examples）；Vue、Nuxt、Bootstrap 依版本限制連到主版號節點，讀不出版本時連到基底節點；套件對照表補上 Alpine.js、Next.js、vue-router、GraphQL、php-parser；樹狀清單被截斷時記錄警告；清單開頭的 BOM 會去掉（#200）。
- 新增 `App\Services\ArticleUnitSplitter`：把文章 Markdown 依 H2、H3 標題切成段落單位並解析標題行尾的 `{#id}`。這一版沒有任何呼叫端，也沒有資料表或 API 的變化（#210）。
- 新增 `docs/tech-debt.md`，記錄刻意先不處理的已知限制（#204）。

### 變更

- 文章匯入的衝突判斷納入標題與日期：站上改過標題、日期或內文，來源再改版時列為 conflict，沒帶 `overwrite_site_edits` 回 409。這一版以前匯入過的文章，下次來源改版時會列為 conflict 一次（#201）。
- 文章匯入覆蓋站上修改須帶 `expected_site_version`：`GET /api/article-sources` 的 conflict 項目新增 `site_version`（站上標題、日期、內文的 SHA-256）；`overwrite_site_edits: true` 的項目缺少或格式不對回 422，與站上現況對不上回 409，整批不寫。**舊版前端勾覆蓋送出會收到 422，前端須同日上線**。匯入交易中的稽核紀錄改為 commit 後才寫，回滾就不留紀錄（#202）。
- 文章匯入拒絕用比已匯入版本更舊的 commit 更新，避免內容被倒回舊版（#199）。
- 技術與專案之間的連線不再過期：移除 14 天未再看到即標過期的機制。`GET /api/graph` 的邊 `stale` 欄位暫時保留、一律為 `false`，`GET /api/graph/path` 不再略過任何邊（#207）。
- 同步的 topic 名稱對照到套件產生的技術（`nextjs`、`alpinejs`、`reactjs`、`expressjs`、`tailwindcss`、`threejs`、`d3js`），不再各長一個節點；只寫上限的版本限制（`<4`、`<=3.2`）不再建版本節點（#208）。
- 測試的限流資料集重置時間留足一小時，避免長時間測試中失效（#206）。

## [1.3.0] - 2026-11-15

### 新增

- 從 GitHub repo 匯入文章：`GET /api/article-sources` 列出來源 repo 最新 commit 裡 front matter 帶 `import: true` 的檔案與八種狀態（new／changed／unchanged／removed／site_deleted／conflict／possible_match／invalid），`POST /api/article-sources/import` 依選定的項目建立（草稿）、更新或連結既有文章；匯入前重算 blob SHA、比對對不上回 409，整批在同一個 transaction 裡寫入；已軟刪除的文章不會復活。來源 repo 與 token 由部署設定帶入，沒設定或 GitHub 讀取失敗時回帶 `reason` 的明確錯誤。匯入與連結寫入稽核事件（#175, #176, #177, #178）。
- `documentations` 新增七個可為 NULL 的匯入來源欄位與 `(source_repo, source_key)` 唯一索引，不出現在任何 API 回應（#176）。
- 新增 `about`／`subjectOf`（文章談到的技術）、`aboutProject`／`projectSubjectOf`（文章談到的專案）述詞；匯入文章時依 front matter 的 `about_techniques`、`about_projects` 建立連結，只新增、不刪除，不認得的名稱只回警告（#182）。
- 預設資料補上 `repository` 分類、`targets`／`targetedBy`、`influences`／`influencedBy` 述詞與 `Claude Code` 技術節點（#187）。
- `GET /api/graph` 的節點新增 `added_at`、`updated_at`，邊新增 `added_at`；未登入時未公開文件的節點與連到它的邊，時間欄位為 `null`；開始記錄連結建立的時間，之前存的連結為 `null`，不回填（#181）。
- `GET /api/graph` 的邊新增 `stale` 欄位：專案到技術的同步連線超過 14 天沒有再被看到即標為過期；`GET /api/graph/path` 的路徑搜尋略過過期的連線（#183）。
- 每日同步也讀各 repo 的 `package.json`、`composer.json`、`requirements.txt`，依對照表推出專案用到的技術並產生連結（#185）。
- 新增 `nomens` 資料表與影子模式：同步與文章匯入除了照舊寫節點與連結，另外記一份讀到的名稱；`php artisan nomens:preview-switch` 預覽改用新規則後哪些連結會不同，不寫入任何資料（#186）。
- CI 加入 Larastan 與測試覆蓋率報告，只報告、不擋 PR；GitHub Actions 改釘 commit SHA，並新增 Dependabot 每週檢查（#180, #168）。

### 變更

- 建立或更新時的外鍵欄位改用共用驗證規則：收到非整數回 422（原本在 PostgreSQL 上回 500），指向已軟刪除的資料也回 422；分類與述詞的 `name` 限 50 字、類號／索書號限兩位數字、`note`／`comment` 須為字串，超長或型別不符回 422（#165, #169）。
- 網址中的 id 不是標準寫法的正整數（例如 `007`、`+1`）或超出整數範圍時回 404；`GET` 實作清單的 `type` 篩選須為整數，否則回 422（#166）。
- 新增述詞時 `call_number` 欄位必須送出（可為 `null`），沒送回 422（#167）。
- 分類名稱唯一性新增與更新都排除已軟刪除的資料；同一個上層分類底下的分類號不能重複；新增述詞的備註限 255 字（#173）。
- 刪除述詞前先檢查：仍被連結或被其他述詞設為反向述詞的，回 422 並附 `reference_counts`（#184）。
- 同步時忽略語言偵測的誤判（`hack`），並把 `procfile` 對應到 Heroku（#183）。
- 升級 `laravel/framework` 13.30.1 到 13.40.0，`phpseclib` 4.0.1 到 4.0.3（修補安全通報）；`symfony/yaml` 改為正式相依（#171, #175）。
- 專案自寫的程式註解改為繁體中文；文章 fixture 移除內部編號與報告路徑，測試補擋（#179, #174）。

## [1.2.0] - 2026-11-09

### 新增

- `GET /api/graph`、`GET /api/graph/path` 的節點新增 `active` 欄位：未公開（草稿）的文件不再對訪客整個藏起來，改成標示狀態的節點（`active` 為 `false`），未登入時 `label` 固定是「未公開」，`subtype` 與 `url` 為 `null`，連到它的邊照常回傳；其他節點的 `active` 一律為 `true`（#149）。
- 建立或更新文件、技術、實作的連結時，檢查兩端實體的分類是否符合述詞的主詞與受詞（子分類算符合）；不符回 422，錯誤指出是哪一筆連結、哪一端、需要什麼分類（#157）。
- CI 加入 Infection 變異測試：只改壞 PR 改到的行，只報告、不擋 PR；測試慣例補上邊界必測清單（#154, #151）。
- 新增訪客掃描測試：所有 `api/` 路由都必須歸類，公開路由不得帶出草稿、不公開與已刪除的資料（#153）。

### 變更

- 設為不公開（`is_visible = false`）的專案，未登入時不再出現在任何公開讀取：實作清單與詳情（詳情回 404）、文件與技術載入的關聯實作、圖譜與路徑、連結清單；帶有效 token 時照常看得到（#137）。
- 未登入時，公開計數只數看得到的內容：`implementations_count`、`own_edges_count`、`reverse_edges_count` 不含不公開的專案、草稿與連到它們的連結；分類的 `documentations_count` 不含草稿文件，數字與對應清單一致（#143, #145）。
- 登入、換發 token、建立登入狀態、session 登入與 `GET /api/user` 回應裡的使用者改用 `UserResource`，只回傳 `id`、`name`、`email`；回應最外層的形狀不變（#136）。
- 正式環境不再提供 `/docs/api` 與 `/docs/api.json`（回 403），文件只在本機環境提供，CI 另外匯出 OpenAPI JSON 為 artifact（#139）。
- 後端移除內嵌的舊後台（Triple）：沒有註冊的路徑一律回 404，Docker 映像檔不再包含 Node 建置階段，後端只提供 API。`/auth/*` 與 `/up` 不受影響（#132）。
- smoke-test 的 sandbox 模式重用現成的 `vendor`，不再整個重裝（#138）。

### 修正

- 述詞的 `own_edges_count`、`reverse_edges_count` 不再數到任一端已軟刪除的連結，與邊清單的 `total` 一致（#163）。
- 圖譜與路徑查詢排除已軟刪除的技術與實作：不再出現指向不存在節點的邊，路徑也不會經過已刪除的中間節點（#161）。

### 移除

- 內嵌的舊後台（Triple）的頁面、Vue 原始碼與 Node／Vite 建置（#132）。

## [1.1.3] - 2026-11-04

### 安全性

- 相依套件升級到 Symfony v8.1.11，修補 2026-11-04 公開的 7 則安全通報：`symfony/http-foundation` 從 v8.1.9、`symfony/http-kernel` 從 v8.1.6、`symfony/routing` 從 v8.1.6、`symfony/yaml`（僅開發環境）從 v8.1.9 升級。涵蓋 CVE-2026-118514、CVE-2026-118516，以及 GHSA-3h27-hjv7-fqjx、GHSA-xg2q-4rrq-657q、GHSA-5mx8-mwrp-5693、GHSA-wg5q-m2ww-f3c9、GHSA-hwfg-qwvh-2wfx。

## [1.1.2] - 2026-11-03

### 安全性

- 相依套件 `symfony/mime` 從 v8.1.6 升級到 v8.1.10，修補公開的安全通報 CVE-2026-45070（PKSA-wtxr-p26d-nn42）。

## [1.1.1] - 2026-10-28

### 安全性

- `GET /api/graph/path` 驗證 `start`／`end` 的節點 id 格式（格式不符回 422），路徑上的文章節點也套用草稿的可見性條件。

## [1.1.0] - 2026-10-27

### 新增

- 可觀測性：正式環境日誌改為單行 JSON（`LOG_CHANNEL=cloud_run`），含 `severity`、`request_id`，並帶 `logging.googleapis.com/trace` 與出錯時的 `httpRequest`，Cloud Logging 可用 `jsonPayload.request_id` 篩出單一請求；每個回應帶 `X-Request-Id` 標頭。未處理的例外以 Error Reporting 認得的 PHP 堆疊格式記錄，不需第三方 SDK。說明與剩餘的手動步驟（5xx 告警）見 `docs/observability.md`（#97）。
- API 文件：`/docs/api`（文件頁）與 `/docs/api.json`（OpenAPI 3.1）公開，不需要登入。由 Scramble 依路由、FormRequest、Resource 自動產生，另外補上 Sanctum bearer token 與 refresh cookie 兩種驗證方式、`/api/graph` 等手動組成的回應形狀、錯誤格式與 429。OAuth 跳轉端點不在文件裡（#98）。
- API 的分頁做法寫進文件：五種資源清單一次回傳全部，只有 `GET /api/relations/{id}/edges` 分頁（#98）。
- API 文件：35 個端點都有中文標題與說明，欄位說明改寫給 API 使用者；`GET /api/user` 改為 invokable controller，正式環境的文件才讀得到它的標題（#116, #117）。
- 安全與稽核事件日誌：登入成功／失敗、token 建立／撤銷／重放、登出、403、429、後台資料變更，以單行 JSON 寫入既有的 Cloud Logging，事件名稱在 `jsonPayload.context.event`。不記 token、密碼、OAuth `code`／`state` 與後台欄位值（#111）。
- CORS 公開 `X-Request-Id` 標頭，前端跨網域也讀得到 request id（#110）。

### 變更

- API 的 4xx／5xx 回應改為 RFC 9457 problem details（`application/problem+json`，含 `type`、`title`、`status`、`detail`、`instance`），向下相容：`message` 與 422 的 `errors` 保留原值，狀態碼不變。500 不含例外內容。`POST /api/scopes`、`POST /api/relations` 的 422 現在也有 `message`（#98）。
- `/api` 底下的錯誤一律回 JSON，不再看 `Accept` header（#98）。
- 文件與實作的 `url`、文件的 `uri` 只接受 http／https 網址，`javascript:`、`data:`、`ftp://` 等其他 scheme 回 422；`uri` 原本不檢查格式（#120）。
- OAuth 帳號未綁定時，日誌不再記 provider 回傳的 email 與姓名，只記 provider 的使用者 id（#111）。
- 部署改為新 revision 先不接流量，檢查 `/up`、`/api/scopes`、404 格式與 `X-Request-Id` 都通過才切換流量；沒過則流量不動（#109）。

### 修正

- 未帶 `Accept: application/json` 的未登入請求回 401，不再是 500（#98）。
- 不存在的 `/api/*` 路徑回 404，不再回 200 的網頁（#98）。

### 安全性

- Google／LINE 登入改用 PKCE（S256）：導向授權頁時帶 `code_challenge`，callback 換 token 時送出存在 session 的 `code_verifier`，授權碼在中途外洩也無法單獨換到 token；session 模式（`/auth/*`）與 token 模式（`/auth/token/*`）都適用（#103）。
- nginx 存取紀錄不再記錄 query string，只記路徑，避免 OAuth callback 的 `code`／`state` 等網址參數寫進 Cloud Logging（#103）。
- 重新建置映像檔，PHP 升到 8.4.27（修補 CVE-2026-109618、CVE-2026-109619）；`symfony/http-foundation`、`symfony/yaml` 升到 8.1.9（修補 CVE-2026-108082、CVE-2026-109413）。CI 加上 `composer audit`，相依套件有已知漏洞時測試會失敗（#118）。
- 部署 workflow 改成每週二自動重建並重新部署一次（建置時一定重新拉基底映像檔），程式碼沒有改動時，PHP 與 alpine 的安全修補也會進到正式環境（#119）。
- `url`、`uri` 欄位限定 http／https，不讓 `javascript:` 這類網址存進會被輸出成連結的欄位（#120）。

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

[1.4.1]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.4.1
[1.4.0]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.4.0
[1.3.0]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.3.0
[1.2.0]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.2.0
[1.1.3]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.1.3
[1.1.2]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.1.2
[1.1.1]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.1.1
[1.1.0]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.1.0
[1.0.1]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.0.1
[1.0.0]: https://github.com/jerryyehself/my-dev-grid/releases/tag/v1.0.0
