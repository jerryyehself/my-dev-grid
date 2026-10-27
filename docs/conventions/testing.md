# 測試

工具是 PHPUnit（`php artisan test`，等同 `composer test`）。寫法參考 `tests/Feature/DocumentationCRUDTest.php`、`tests/Feature/DraftVisibilityTest.php`。

## 格式

- 經過 HTTP、資料庫、框架的測試放 `tests/Feature/`；不需要啟動 Laravel 的純函式測試放 `tests/Unit/`（`TechniqueLabelTest`、`CloudLoggingFormatterTest`）。
- 測試類別叫 `<受測對象>Test`：`ScopeCountsTest`、`SaveReposDataServiceTest`。測試 migration 的叫 `<migration 描述>MigrationTest`：`FixUsesRelationDirectionMigrationTest`。
- 測試方法用 `test_` 開頭的英文 snake_case，寫成描述行為的句子：`test_guest_gets_404_for_a_draft_and_200_for_a_published_documentation`。測試為什麼存在、守的是哪個情境，寫在類別或方法的中文 PHPDoc。
- 碰到資料庫的測試 `use RefreshDatabase`。
- 用 factory 準備資料（`Documentation::factory()->create()`），需要分類、述詞時跑 seeder（`$this->seed()`），不要寫死 id（見 [`data.md`](data.md)）。
- 需要登入時用 `$this->actingAsOwner()`（`tests/TestCase.php`）。

## 每次都要過的檢查

- CI 在 SQLite 跟 PostgreSQL 兩種資料庫各跑一次全套測試（`.github/workflows/tests.yml`），兩邊都要過。本機只跑 SQLite 時，碰到 SQL 寫法、排序、流水號的改動要特別注意。
- 改到 API 文件相關的程式碼時，加跑 `php artisan scramble:export`（見 [`api.md`](api.md)）。
- 送 PR 前跑 `./vendor/bin/pint --test`（見 [`naming.md`](naming.md)）。

## 好的測試

1. **測行為，不測實作。** 從 HTTP 打端點，驗證狀態碼、JSON、資料庫的結果，不驗證 controller 內部的變數。
   ```php
   $response->assertCreated()->assertJsonFragment(['title' => 'Laravel Docs']);
   $this->assertDatabaseHas('documentations', ['title' => 'Laravel Docs', 'type' => $scope->id]);
   ```
2. **功能壞了測試要會失敗。** 寫完試著想：把被測的那行改壞，這個測試會不會變紅？
3. **涵蓋拒絕的情況。** 每個寫入端點至少測：沒登入回 401、驗證失敗回 422、成功。讀取端點要測沒登入看不到草稿（`DraftVisibilityTest`）。
4. **彼此獨立、結果穩定。** 不打真的外部服務：GitHub API 用 `Http::fake()`（`GitServiceTest`），或用 fixture（`GitHubReposSnapshotSeeder`）。需要時間時用 `$this->travel()`／`Carbon::setTestNow()`，結束後還原。
5. **效能退化也要測。** 預先載入關聯是為了避免 N+1 時，用查詢次數驗證：`DB::enableQueryLog()`，資料增加後查詢次數不能跟著增加（`ScopeCountsTest::test_scope_index_does_not_n_plus_one`）。
6. **日誌也是行為。** 安全事件與稽核紀錄用 `Tests\Concerns\CapturesSecurityEvents` 檢查：有記到該記的事件，也沒有記到不該記的值（token、密碼）。
7. **好讀。** 一個測試驗證一件事；準備資料、執行、驗證三段分清楚。

## 目前已知不符的地方

- `tests/Unit/ExampleTest.php` 是 Laravel 樣板，什麼都沒測，可以刪掉。
- `tests/Feature/relationCRUDTest.php` 類別名開頭小寫（見 [`naming.md`](naming.md)）。
