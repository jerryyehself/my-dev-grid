# 網頁開發學習資源資料庫

紀錄並管理自己學做作品及學習時的過程和內容。透過排程獲取git api資訊後加值，希望除了呈現給工程師外，也能讓非工程師瞭解我學習的內容。

## 學習過程概念

借鑑Linked Data三元組的觀念，將做side project或學習特定程式的過程記錄下來。

![](https://github.com/jerryyehself/my-dev-grid/blob/main/docs/readme/er_model_mechanism.svg?raw=true "本體機制圖：Scope 階層、Relation 三元組、唯讀鎖定規則")

![](https://github.com/jerryyehself/my-dev-grid/blob/main/docs/readme/er_model_concept.svg?raw=true "三大類、子類與跨類關聯圖")

### Scope(主詞/受詞)

判斷紀錄屬於哪個類別，主要包括文件(Documentation)、使用技術(Technique)、實際執行(Implementation)

![](https://github.com/jerryyehself/my-dev-grid/blob/main/docs/readme/scope_sample.png?raw=true "主詞/受詞範例")

### Relation

連接不同類別的關係為何

### 期望目標

希望未來做專案的時候可以獲得更多靈感，或減少解決問題的時間。與專案委託人接洽時，可以更快速地跟對方解釋如何做到，成果如何等。

[前一專案](https://github.com/jerryyehself/Laravel-LearningLibrary)抓取 github 資料的邏輯已經搬過來了，見下面的排程指令。

## 開發

本地環境（含資料庫內容）怎麼從零建起來，見 [`docs/local-dev-setup.md`](docs/local-dev-setup.md)。最短路徑：

```bash
composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite          # .env.example 預設 DB_CONNECTION=sqlite
php artisan migrate:fresh --seed        # Scope / Relation 分類架構
php artisan serve                       # http://localhost:8000
php artisan test                        # 全套測試
```

排程指令（從前一專案搬過來的 GitHub 取資邏輯）：

- `php artisan github:sync-repos` — 打 GitHub API，把 repo 資料加值成 `Implementation` / `Technique` 與它們之間的關聯
- `php artisan github:snapshot-repos` — 把當下的 repo 資料存成快照 fixture，讓測試不用打外部 API

## 前端

[`my-dev-grid-front`](https://github.com/jerryyehself/my-dev-grid-front)（Vue 3 SPA，部署在 Cloudflare，網址 `jerrylib.com`，正式公開前用 Cloudflare Access 鎖住）是這個 API 的公開網站，也是現在的正式管理介面：文章、`Scope`（階層分類號）、`Relation`（述詞）都在那邊新增/編輯。舊的內嵌 Vue 後台 Triple（`resources/js`，session cookie 登入）還在，但只是還沒被移除（D-48，見 `my-dev-grid-skills/docs/decision-register.md`），不是主要維護目標，也沒有 Triple 沒有而 `my-dev-grid-front` 有的資料——兩邊管的都只是 `Scope`／`Relation`。

## API

讀（`index` / `show`）完全公開，寫（`store` / `update` / `destroy`）收斂到 `auth:sanctum`，實際擋權邏輯在 `app/Policies`。路由定義見 `routes/api.php`。

| 端點 | 說明 |
| --- | --- |
| `GET /api/graph` | 整張圖：三族節點 ＋ 三張 pivot ＋ `entity_relations` 的邊，`relation_id` 已解析成述詞名稱 |
| `GET /api/graph/path` | 起訖點之間的最短路徑（BFS，邊當無向處理）。逆著走的那一跳會回報反向述詞 |
| `GET/POST/PUT/DELETE /api/scopes` | 階層分類號。三族：`0000` Documentation、`1000` Technique、`2000` Implementation。寫入需要登入 |
| `GET/POST/PUT/DELETE /api/relations` | 述詞。15 條全部成對可逆，`reverse_id` 互指；被任何邊引用後大部分欄位鎖定唯讀（僅 `note` 可改）。寫入需要登入 |
| `GET /api/relations/{id}/edges` | 一條述詞底下的邊，分頁 |
| `GET/POST/PUT/DELETE /api/documentations` | 文件／文章。`body` 欄位存 Markdown 原文。寫入需要登入 |
| `GET/POST/PUT/DELETE /api/techniques` | 技術。寫入 API 已經有了（需要登入），但兩個前端（含 Triple）都還沒有管理畫面，實際資料靠 GitHub sync 自動 find-or-create，列為技術債 |
| `GET/POST/PUT/DELETE /api/implementations` | 實作／專案。同上 |

### 認證

`my-dev-grid-front` 用 Sanctum **API token**，不是 session cookie（D-56，`decision-register.md`）：

- 帳號密碼：`POST /api/auth/login`／`POST /api/auth/logout`，`GET /api/user` 確認登入狀態。
- Google／LINE：`GET /auth/token/{provider}/redirect` → callback（`TokenSocialAuthController`）登入後，帶著 token 導回前端的 `/auth/callback`。

跨 origin 的 SPA 要用 cookie 模式，前後端得共用同一個根網域。前端已經在 `jerrylib.com`，後端還沒部署、之後會先在 `*.run.app`；要等後端也掛上 `api.jerrylib.com` 這類子網域，才重新評估要不要換成 cookie 模式（`management-debt-ledger.md`）。

Triple 是同源的內嵌後台，繼續用它原本的 session cookie（`SessionAuthController`，社群登入走 `/auth/{provider}/*`）。兩套認證刻意不共用同一支 controller。

### 文章的內文

`documentations.body` 存的是 **Markdown 原文**，不是渲染後的 HTML——渲染在前端走 AST 產生 Vue 元件，不經過 `v-html`。所以這一欄刻意不受 `TrimStrings` 中介層處理（`bootstrap/app.php`）：Markdown 文件開頭的四個空白縮排是「程式碼區塊」，被 trim 掉等於默默改掉文件的意思。

## 部署

Cloud Run ＋ Cloud SQL Postgres，`--min-instances=0` 可以縮到零。設計與實際設定見 [`docs/deployment-gcp.md`](docs/deployment-gcp.md)、[`docs/infrastructure-concepts.md`](docs/infrastructure-concepts.md)，workflow 在 `.github/workflows/deploy-cloud-run.yml`。

**還沒做第一次部署**（`deployment-gcp.md` 第 8 步）。workflow 在 push 到 `main` 時觸發，但 GCP 相關的 repository variables 還沒設定，所以目前每次都是 skipped。
