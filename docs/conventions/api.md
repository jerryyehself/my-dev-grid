# API

## 路由

- API 路由寫在 `routes/api.php`，一律指到 controller 方法，不要寫 closure：正式環境會跑 `route:cache`，closure 會被序列化，Scramble 就讀不到它身上的 `#[Endpoint]`，文件裡的標題會不見。只有一個 action 時用可呼叫的 controller：`Route::get('/user', CurrentUserController::class)`。
- 資源路由分成兩組：讀（`index`、`show`）公開，寫（`store`、`update`、`destroy`）放在 `auth:sanctum` 群組裡。新資源也加進這兩組 `Route::apiResources`。
- 固定路徑（`scopes/create`）要註冊在 `apiResources` 之前，不然會被當成 `{scope}` 的值。
- 需要額外限制的端點，在路由上掛具名 limiter 或 middleware：`throttle:login`、`frontend.origin`。limiter 定義在 `AppServiceProvider::configureRateLimiting()`。

## 回應形狀

照現有五種資源的形狀：

| action    | 狀態碼 | 形狀                                                          |
| --------- | ------ | ------------------------------------------------------------- |
| `index`   | 200    | `{ "type": "<複數資源名>", "data": [Resource…] }`             |
| `show`    | 200    | Resource 本身，不包 `data`                                    |
| `store`   | 201    | `{ "data": Resource, "message": "<Model> created." }`         |
| `update`  | 200    | `{ "data": Resource, "message": "<Model> updated." }`         |
| `destroy` | 200    | `{ "message": "<名稱> was deleted." }`                        |

- 時間欄位格式化成 `Y-m-d H:i:s`：`optional($this->created_at)->format('Y-m-d H:i:s')`。
- 清單一次回傳全部；資料量會隨使用量成長的清單才分頁，每頁筆數設預設值與上限常數：`RelationEdgeQuery::DEFAULT_PER_PAGE`、`MAX_PER_PAGE`。
- 清單要預先載入 Resource 會用到的關聯與計數（`with()`、`withCount()`、`withReferenceCounts()`），不要讓每一列各自查一次。加了會多查資料庫的欄位，就補一個查詢次數的測試（`ScopeCountsTest::test_scope_index_does_not_n_plus_one`）。

## 錯誤

- 錯誤回應一律是 RFC 9457 problem details，由 `bootstrap/app.php` 的 `withExceptions()` 套用 `App\Http\ProblemDetails`。不要在 controller 裡自己組錯誤 JSON。
- 要回錯誤時，用 `abort(<狀態碼>, '<中文訊息>')`，或丟一個自己有 `render()` 的例外：`abort(409, '登入狀態剛更新過，請重試。')`、`RelationLockedException`。
- 422 的 `errors` 以輸入欄位名當 key，前端才能把訊息顯示在對應欄位下面（`RelationLockedException::render()`）。
- 屬於用戶端錯誤的例外，`report()` 回 `false`，不要寫進 error log。

## API 文件（Scramble）

controller 的 PHPDoc、`#[Endpoint]` 文字，以及 Resource 與 FormRequest 陣列 key 正上方的註解，都會原樣寫進 API 文件。這個 repo 是公開的，CI 匯出的文件檔有 GitHub 帳號的人都能下載，所以這些文字一律當成公開內容寫。

- 文件只在本機開發環境（`APP_ENV=local`）提供：本機跑起來後開 `/docs/api`（文件頁）或 `/docs/api.json`（OpenAPI JSON）。其他環境（包括正式環境）由 `config/scramble.php` 的 `RestrictedDocsAccess` 回 403，登入也一樣。
- 需要文件檔時，到 GitHub Actions 的 Tests workflow 找那次執行的 `openapi` artifact，裡面是 `scramble:export` 匯出的 `api.json`（SQLite 那一組產生，保留天數依 repo 的 artifact 設定）。本機要匯出就跑 `php artisan scramble:export`，檔案寫到 repo 根目錄的 `api.json`。
- `routes/api.php` 的每個 action 都要有 `#[\Dedoc\Scramble\Attributes\Endpoint(title: …)]`，標題寫簡短的中文給 API 使用者看；有補充價值時再加一句 `description`。`description` 有填時，會蓋掉 PHPDoc 的描述。
  ```php
  #[Endpoint(
      title: '取得文件',
      description: '回傳單一文件，含所屬分類與關聯的技術、實作。草稿對未登入的人等同不存在，回 404。',
  )]
  public function show(Documentation $documentation)
  ```
- 不要留 Laravel 產生的樣板註解（"Display a listing of the resource." 之類），不然它會變成端點標題。註解的整體寫法見 [`comments.md`](comments.md)。
- Resource 的 `toArray()` 與 FormRequest 的 `rules()` 裡，key 正上方的註解會變成欄位說明。給 API 讀者看的說明，寫成緊貼 key 的單行 `/** … */`；只有開發者需要知道的理由，寫成它上方的 `//` 註解。key 上有 `/** */` 時，同一處的 `//` 註解不會被輸出；反過來，key 上只有 `//`、沒有 `/** */` 時，`//` 的內容會被當成欄位說明公開，FormRequest 的類別 docblock 也會變成請求本文的說明。完整的寫法與範本見 [`comments.md`](comments.md)。
  ```php
  // app/Http/Requests/StoreDocumentationRequest.php
  // 內文是 Markdown 原文，不設長度上限：…（給開發者）
  /** 文件內文（Markdown 原文），沒有長度上限。 */
  'body' => 'nullable|string',
  ```
- 回應是手動組的（不是 Resource）時，用 `#[Response(200, type: 'array{…}')]` 寫明形狀：`GraphController::index`、`RelationController::edges`。不在 FormRequest 裡的查詢參數，用 `#[QueryParameter]` 寫明：`RelationController::edges` 的 `page`、`per_page`。
- 給 API 讀者看的文字不要出現：內部的決策或規格編號、issue 編號、私有 repo 的路徑、資料庫裡實際有幾筆資料、寫給前端的指示。
- 改完先跑 `php artisan route:cache`，再跑 `php artisan scramble:export` 檢查輸出，最後跑 `php artisan route:clear`。`tests/Feature/ApiDocsTest.php` 守住文件的基本結構；新增手動組的回應時，在那裡補一個測試。
- `routes/web.php` 的 OAuth 跳轉不在 API 文件裡，不用加 `#[Endpoint]`（`ApiDocsTest::test_document_excludes_oauth_redirects_and_web_routes`）。

## 目前已知不符的地方

- `update` 寫入失敗（`update()` 回傳 `false`）時，四個資源 controller 都回 200 加 `'… update failed'`，不是錯誤狀態碼。
- `ScopeController::update` 的 `data` 是原始 model，跟其他資源的 `update` 形狀不同（見 [`structure.md`](structure.md)）。
