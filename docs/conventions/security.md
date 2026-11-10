# 安全

這份文件寫的是後端程式碼一定要守的規則。依據 [Laravel 文件](https://laravel.com/docs)（Authorization、Validation、Eloquent Mass Assignment）與 [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/)（Input Validation、Mass Assignment、Logging）。這些規則都靠審查與測試守住。

## 授權

- 寫入要過兩層：路由層的 `auth:sanctum`（擋沒登入的請求），以及 controller 裡的 `$this->authorize()`（交給 Policy 判斷）。只靠其中一層不夠：路由少掛 middleware，或 controller 少寫一行，就會被打穿。
- 新增 model 時，一起新增 `<Model>Policy`，寫入的 action 都呼叫 `authorize()`。測試至少涵蓋「沒登入回 401」。
- 現在是單一管理者的封閉系統，Policy 的判斷是「有登入」（`DocumentationPolicy::create` 回傳 `$user !== null`）。開放多人使用前，所有 Policy 都要重新設計。

## 草稿與公開讀取

- 讀取端點公開、不需要登入，所以每個會回傳文件的地方，都要自己濾掉草稿：清單用 `->visibleToViewer()`，關聯載入時也一樣（`TechniqueController::show`）。
- 用 query builder 或手動組資料的地方，要另外處理：`RelationEdgeQuery` 濾掉連到草稿的邊，`GraphController` 的圖譜照常列出草稿節點與連到它的邊（`active` 為 false），但沒登入時把它的標籤換成「未公開」、不帶 `subtype` 與 `url`（`GraphController::labelFor()`、`nodesFor()`）。已軟刪除的文件、技術、實作，連結表與 `entity_relations` 裡的邊要自己排除（`GraphController::deletedDocumentationNodeIds()`、`deletedTechniqueAndImplementationNodeIds()`）。
- 沒登入的人打草稿的 show，回 404 而不是 403，不透露這個 id 有一篇草稿（`DocumentationController::show`）。

## 輸入

- 每個寫入端點都用 FormRequest 驗證，存進資料庫的只能是 `$request->validated()`，不要用 `$request->all()`。
- 每個 model 都用 `$fillable` 列出可以批次寫入的欄位，不要用 `$guarded = []`。
- 外鍵欄位的規則一律用 `App\Rules\ForeignId::rules()` 產生，不要自己寫 `exists:` 字串或 `Rule::exists()`：`'type' => ['required', ...ForeignId::rules('scopes')]`。要對 `exists` 多加條件時傳第二個參數（`StoreScopeRequest` 的 `parent_class` 只收頂層分類）。它回傳 `bail`、`integer`，加上排除軟刪除的 `exists`，少一項都會出事：
  - 沒有 `integer`，非數字的值（`abc`、`1.5`）會原封不動送進 PostgreSQL 的 bigint 欄位查詢而回 500；SQLite 不會報錯，只有 CI 的 PostgreSQL 那一輪才看得到。
  - `exists` 直接查資料表，不知道 model 有軟刪除；不排除的話，已刪除的分類、述詞、實體還能被新資料連上。指到已軟刪除的資料一律回 422。
  - `SoftDeletedForeignKeyTest` 會讀每支 FormRequest 的規則：直接寫 `exists`、漏了其中一項，或新的外鍵欄位沒列進那份測試，測試都會失敗。
- 文字欄位一律加 `string`；資料表欄位有長度上限（`varchar(N)`、`char(N)`）的，規則要有對應的上限：`'name' => 'required|string|max:50|unique:relations'`。超過長度的值 SQLite 照樣寫入，PostgreSQL 會在寫入時丟錯而回 500；沒有 `string`，陣列會一路送到查詢或寫入才出錯。有 `numeric` 的欄位，`max` 比的是數值大小，位數上限要用 `max_digits`（`class_number`、`call_number`）。`FieldLengthValidationTest` 守住分類與述詞的這些欄位。
- 會拿去跟 id 欄位比較的查詢參數（`?type=`）也要先驗證是整數，用 `Index<Model>Request`：`IndexImplementationRequest`。
- 網址上的 id 不是合法的正整數（含超出 bigint 範圍）時，回 404，不拿去查資料庫。這由 model 的 `ResolvesPositiveIntegerRouteKeys` trait 負責，每個會被路由綁定的 model 都要套用；`GuestApiSweepTest` 會檢查每條帶 id 的路由，漏套的話測試會失敗。
- 自訂規則與 closure 裡也一樣：拿請求的值去 `find()`、`where()` 之前，先確認它是整數（`filter_var($value, FILTER_VALIDATE_INT)`，判斷要跟 `integer` 規則一致），不是就不查。查的時候用一般的 `find()`，不加 `withTrashed()`：已軟刪除的資料當作不存在，由欄位自己的規則回報。`bail` 只擋同一個欄位後面的規則，擋不到別的欄位的規則或 closure 去讀這個值（例：`UpdateScopeRequest` 的 `call_number` 會讀 `parent_class`，`RelationFitsLinkedScopes` 會讀連結的 `id` 與 `type`）。
- 可以從其他欄位推出來的值，不收呼叫端傳來的：`class_number` 一律從父分類推（`ScopeController::store`）。
- 寫原始 SQL（`DB::raw`、`whereRaw`）時，值一律用 binding（`whereRaw('lower(title) = ?', [...])`）；非得把字串直接放進 SQL 的，只能放程式裡寫死的值，而且要先過白名單檢查（`RelationEdgeQuery::quoted()`）。
- Markdown 內文（`body`）存原文，不在後端改動或轉成 HTML：`TrimStrings` 刻意排除它（`bootstrap/app.php`）。內容怎麼安全地顯示，由前端負責。

## 網址

- （提議，修改中）每個存進資料庫的網址都只收 `http`、`https`。FormRequest 用 `'url' => 'nullable|url:http,https'`，不要只寫 `url`：Laravel 的 `url` 規則不帶參數時，會接受上百種協定（包含 `data:`、`file:`）。
- 不是從 FormRequest 進來的網址（GitHub 同步的 `html_url`、seeder 的 fixture）也要檢查協定，不符合的就不存。
- 每新增一個網址來源，補一個「協定不是 http／https 時回 422（或不寫入）」的測試。
- 前端顯示時會再過一次 `safeLink()`。這是兩道各自獨立的防線，後端不能因為前端會擋就不檢查。

## 登入、token、cookie

- refresh token 只放在 httpOnly、Secure、SameSite=None、Partitioned 的 cookie 裡，不進 JSON（`RefreshTokenCookie::make`、`IssuesFrontendTokens`）。
- 會讀寫 refresh cookie 的端點，掛 `frontend.origin`（`EnsureFrontendOrigin`）。
- OAuth 一律開 PKCE，透過 `SocialiteDriver::for()` 取得 driver，不要直接呼叫 `Socialite::driver()`。
- 需要「只能用一次」的 token，用刪除的筆數判斷，並放在 transaction 裡，才不會被同時送來的請求各用一次（`TokenRefreshController::session`）。
- rate limit 的 key 用 `AppServiceProvider::clientIp()`，不要直接用 `$request->ip()`：`X-Forwarded-For` 最左邊的值是用戶端可以自己填的。
- 登入相關的端點都要掛具名的 rate limiter（`throttle:login`、`throttle:social-login`、`throttle:token-refresh`）。

## 日誌

- 安全事件一律透過 `App\Logging\SecurityLog` 寫，事件名稱用 `App\Enums\SecurityEvent` enum，不要自己呼叫 `Log::warning()` 寫安全事件。
- 日誌裡不能出現：密碼、access／refresh token 的值、OAuth 的 `code`／`state`、session id、金鑰、請求內容。使用者只記內部的 `user_id`。完整的事件表見 [`docs/observability.md`](../observability.md)。
- 後台資料的變更由 `AdminAudit` observer 記錄。新增可以在後台編輯的 model 時，把它加進 `AdminAudit::MODELS`；連結表用 `syncPivot()` 同步，稽核紀錄才記得到。

## 秘密與設定

- 程式裡讀設定一律用 `config()`，不要在 `app/` 裡直接呼叫 `env()`（`route:cache`、`config:cache` 之後 `env()` 會讀不到）。
- `.env` 系列的檔案，只 commit `.env.example`、`.env.production.example`，而且裡面只放佔位值。
- 錯誤回應不帶 stack trace：正式環境 `APP_DEBUG=false`，500 只回 problem details 的固定欄位。
- 改 CORS、cookie、middleware、`bootstrap/app.php`、`config/` 的 PR 屬於高風險改動，照 `CLAUDE.md` 的規定處理。

## 目前已知不符的地方

- `StoreDocumentationRequest`、`UpdateDocumentationRequest`、`StoreImplementationRequest`、`UpdateImplementationRequest` 的 `url` 還是 `nullable|url`（修改中）。`SaveReposDataService` 寫入 GitHub 的 `html_url` 時沒有檢查協定。
