# 測試

工具是 PHPUnit（`php artisan test`，等同 `composer test`）。寫法參考 `tests/Feature/DocumentationCRUDTest.php`、`tests/Feature/DraftVisibilityTest.php`。

## 格式

- 經過 HTTP、資料庫、框架的測試放 `tests/Feature/`；不需要啟動 Laravel 的純函式測試放 `tests/Unit/`（`TechniqueLabelTest`、`CloudLoggingFormatterTest`）。
- 測試類別叫 `<受測對象>Test`：`ScopeCountsTest`、`SaveReposDataServiceTest`。測試 migration 的叫 `<migration 描述>MigrationTest`：`FixUsesRelationDirectionMigrationTest`。
- 測試方法用 `test_` 開頭的英文 snake_case，寫成描述行為的句子：`test_guest_gets_404_for_a_draft_and_200_for_a_published_documentation`。測試為什麼存在、守的是哪個情境，寫在類別或方法的中文 PHPDoc。
- **每個測試方法都要加繁體中文的 `#[TestDox('…')]`**（`use PHPUnit\Framework\Attributes\TestDox;`），描述「預期的行為」，讀起來像一句話，控制在 40 字以內，從測試內容寫，不是把方法名翻成中文。這樣 `php artisan test --testdox`（或 `vendor/bin/phpunit --testdox`）的報告就是中文的行為清單。方法名維持英文，不改。
  ```php
  #[TestDox('新增文件時，ftp:// 網址回 422')]
  public function test_store_rejects_ftp_url()
  ```
  有 `#[DataProvider]` 的測試，`#[TestDox]` 照寫在同一個方法上，PHPUnit 會把資料集名稱接在描述後面。
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
2. **功能壞了測試要會失敗。** 寫完試著想：把被測的那行改壞，這個測試會不會變紅？做法與判斷見下面「把程式改壞來檢查測試」。
3. **涵蓋拒絕的情況。** 每個寫入端點至少測：沒登入回 401、驗證失敗回 422、成功。讀取端點要測沒登入看不到草稿（`DraftVisibilityTest`）。其他邊界見下面「邊界必測清單」。
4. **彼此獨立、結果穩定。** 不打真的外部服務：GitHub API 用 `Http::fake()`（`GitServiceTest`），或用 fixture（`GitHubReposSnapshotSeeder`）。需要時間時用 `$this->travel()`／`Carbon::setTestNow()`，結束後還原。
5. **效能退化也要測。** 預先載入關聯是為了避免 N+1 時，用查詢次數驗證：`DB::enableQueryLog()`，資料增加後查詢次數不能跟著增加（`ScopeCountsTest::test_scope_index_does_not_n_plus_one`）。
6. **日誌也是行為。** 安全事件與稽核紀錄用 `Tests\Concerns\CapturesSecurityEvents` 檢查：有記到該記的事件，也沒有記到不該記的值（token、密碼）。
7. **好讀。** 一個測試驗證一件事；準備資料、執行、驗證三段分清楚。

## 邊界必測清單

新增或修改下列東西時，測試要涵蓋對應的案例。同一個行為要跑多組資料時，用 `#[DataProvider]` 列成一張表（每組資料取名字），一眼看得出哪些情況列到了。

- **接 id 的端點或查詢**：存在、不存在、已軟刪除，三個分開測。Laravel 的路由模型綁定預設不取軟刪除的資料，用了 `withTrashed()` 就會改變這個行為，所以「不存在」和「已刪除」不能互相代替。有草稿概念的再加上草稿，用訪客與登入者各測一次。
- **起訖或範圍**：起訖相同、起訖反過來、剛好在邊界上，以及邊界前後各一。
- **可為 NULL 的欄位**：NULL 與空字串分開測。
- **多種型別或列舉**：每一種都要列到，不能只測一種（例：圖的每一種節點型別）。
- **關聯**：關聯另一端的資料被刪除或變成看不到時，這一端的回應不能把它帶出來。用一個獨特的字串當標記建這筆資料，再用 `assertDontSee`／`assertJsonMissing` 檢查整個回應，不只檢查某一個欄位。

## 把程式改壞來檢查測試

把被測的程式故意改壞一點（例如把 `>` 改成 `>=`、`||` 改成 `&&`、刪掉一行、拿掉一個 middleware），再跑測試：測試變紅，表示它守住了這行；還是綠的，就是測試的漏洞。手動改，或用工具自動改（變異測試），都是同一件事。

- **只往上檢查一層。** 測試檢查程式；改壞程式檢查測試。改壞程式這一步本身不寫測試，也不再找別的東西檢查它：它只是拿現有的測試去跑被改壞的程式，沒有新的判斷可以出錯。測試裡如果需要確認自己不是空轉（例如已登入的對照組要看得到草稿），寫成那個測試裡的一個斷言，不另外寫測試。
- **不追分數。** 有些改法跟原本意思一樣（例如整數的 `>= 1` 改成 `> 0`），測試不可能抓到；日誌文字、排版這類被改壞也沒有人受影響。每一個沒被抓到的改法只問一件事：「這行壞了，使用者會不會受影響？」
  - 會（登入與授權、可見範圍、邊界條件、會改資料的地方）→ 補測試。
  - 不會，或意思跟原本一樣 → 不處理。
- **範圍跟著改動走。** 只改壞這次改到的程式，不是整個專案。

**CI 會自動做一次（`.github/workflows/infection.yml`）。** 用 [Infection](https://infection.github.io/) 在每個 PR 上自動改壞「這個 PR 新增或修改的行」，再跑現有測試。

- **只看報告，不擋 PR。** 沒被抓到的改法不會讓檢查變紅，也不設分數門檻；變紅只代表 Infection 本身出錯（例如現有測試沒過）。
- **結果看哪裡。** PR「Files changed」上對應那一行的警告註解（每個沒被抓到的改法一則，內容是改壞前後的差異）；Actions 裡「Infection」這個 job 的 log 有完整清單；log 檔也附在該次執行的 `infection` artifact。
- **看到註解怎麼辦。** 照上面的原則，只問「這行壞了，使用者會不會受影響？」會就補測試，不會就略過，不用為了讓註解消失而補。
- **範圍。** PR 沒改到 `app/` 底下的 PHP 時這個檢查不會啟動；`develop` 到 `main` 的上線 PR 也不跑（每個改動在進 `develop` 的 PR 上已經跑過）。只在 SQLite 上跑。
- **本機怎麼跑。** Infection 不在 `composer.json` 裡。從 [Infection 的 GitHub release](https://github.com/infection/infection/releases) 下載跟 workflow 同一版的 `infection.phar`（版本號看 `infection.yml` 的 `tools:`），本機要有 pcov 或 Xdebug 才能算覆蓋率，並且有 `.env`（`APP_KEY` 不能是空的）。在專案根目錄：
  ```bash
  php infection.phar --git-diff-lines --git-diff-base=origin/develop --show-mutations --threads=max
  ```
  還沒 commit 的改動也算。只想看某一個檔案：把 `--git-diff-lines --git-diff-base=…` 換成 `--filter=app/Rules/ReverseIsAvailable.php`。設定在根目錄的 `infection.json5`，報告寫到 `build/infection/`（已加進 `.gitignore`）。

## 目前已知不符的地方

- `tests/Unit/ExampleTest.php` 是 Laravel 樣板，什麼都沒測，可以刪掉。
- `tests/Feature/relationCRUDTest.php` 類別名開頭小寫（見 [`naming.md`](naming.md)）。
