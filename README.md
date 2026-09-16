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

## API

讀（`index` / `show`）完全公開，寫（`store` / `update` / `destroy`）收斂到 `auth:sanctum`，實際擋權邏輯在 `app/Policies`。路由定義見 `routes/api.php`。

| 端點 | 說明 |
| --- | --- |
| `GET /api/graph` | 整張圖：三族節點 ＋ 三張 pivot ＋ `entity_relations` 的邊，`relation_id` 已解析成述詞名稱 |
| `GET /api/graph/path` | 起訖點之間的最短路徑（BFS，邊當無向處理）。逆著走的那一跳會回報反向述詞 |
| `GET /api/scopes` | 階層分類號。三族：`0000` Documentation、`1000` Technique、`2000` Implementation |
| `GET /api/relations` | 述詞。15 條全部成對可逆，`reverse_id` 互指 |
| `GET /api/documentations` | 文件／文章。`body` 欄位存 Markdown 原文 |
| `GET /api/techniques` | 技術 |
| `GET /api/implementations` | 實作／專案 |

### 文章的內文

`documentations.body` 存的是 **Markdown 原文**，不是渲染後的 HTML——渲染在前端走 AST 產生 Vue 元件，不經過 `v-html`。所以這一欄刻意不受 `TrimStrings` 中介層處理（`bootstrap/app.php`）：Markdown 文件開頭的四個空白縮排是「程式碼區塊」，被 trim 掉等於默默改掉文件的意思。

## 部署

Cloud Run ＋ Cloud SQL Postgres，`--min-instances=0` 可以縮到零。設計與實際設定見 [`docs/deployment-gcp.md`](docs/deployment-gcp.md)、[`docs/infrastructure-concepts.md`](docs/infrastructure-concepts.md)，workflow 在 `.github/workflows/deploy-cloud-run.yml`。
