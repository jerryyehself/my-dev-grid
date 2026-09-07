# 本地開發環境重建

拉到最新 `main` 之後，怎麼讓本地環境（含資料庫內容）跟上最新狀態。這份文件講「操作步驟」，不重複解釋各個 seeder/migration 為什麼這樣設計——那些理由留在對應的程式碼註解、commit message、PR 裡。

## 1. 安裝依賴、準備 `.env`（首次設置，或 `vendor/`／`.env` 不存在時）

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env.example` 預設 `DB_CONNECTION=sqlite`，本地開發最簡單的做法是用 sqlite——建一個空的 `database/database.sqlite` 檔案即可，不必另外裝 MySQL/Postgres。

## 2. 重建 Schema ＋ 分類／關聯種子資料

```bash
php artisan migrate:fresh --seed
```

會清空重跑所有 migration（含最新的資料回填 migration），再跑 `DatabaseSeeder`（＝ `ScopeSeeder` ＋ `RelationSeeder`）。跑完之後，本地資料庫裡 `Scope`／`Relation` 的分類架構、`comment`／`note` 內容就是 `main` 上最新的版本。

**⚠️ 已知限制**：`ScopeSeeder`／`RelationSeeder` 用的是 `Scope::create()`／`Relation::create()`（不是 `firstOrCreate`），不是為了「重複執行安全」設計的。本地資料庫已經 seed 過、裡面已經有資料時，直接再跑 `db:seed` 會插入重複列，不會覆蓋更新。想乾淨拿到最新內容，安全做法就是上面的 `migrate:fresh --seed`——會整個清空重建，本地如果有不想丟的手動測試資料，先自行備份。

## 3. 填入真實 GitHub repo 資料（Implementation／Technique）

只跑完第 2 步，`Scope`／`Relation` 的分類架構是有了，但 `Implementation`／`Technique` 還是空的（沒有任何實際 repo/技術資料）。要填入真實內容：

```bash
# .env 裡設定：GITHUB_TOKEN=<你的 GitHub personal access token>
php artisan github:sync-repos
```

這個指令會打 GitHub API 撈你帳號底下的公開 repo，透過 `SaveReposDataService` 寫入 `Implementation`（每個 repo）與 `Technique`（依 repo 的 languages／topics，含 framework／packagetool 分類邏輯）。`save_repos_content()` 用的是 `updateOrCreate`，這個指令本身重複執行是安全的，不會累積重複資料。

正式環境（production）另外有 `routes/console.php` 裡的 `Schedule::command('github:sync-repos')->daily()` 每日排程，本地開發不需要另外設排程，手動照上面指令跑一次即可。

## 快速對照表

| 想做什麼 | 指令 |
|---|---|
| 全新環境從零建置 | `composer install` → `.env` → `migrate:fresh --seed` → `github:sync-repos` |
| 只想拿到最新的分類/關聯架構（會清空重建） | `migrate:fresh --seed` |
| 只想拿到最新的真實 repo/技術資料（架構已經是最新的） | `github:sync-repos` |
