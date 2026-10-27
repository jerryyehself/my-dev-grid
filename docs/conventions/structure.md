# 檔案結構與各層責任

採 Laravel 預設的「依類型分」資料夾。這個 repo 只有一個 API，資料夾數量少，不另外分功能資料夾。

## 資料夾

| 位置                                 | 放什麼                                                        | 例子                                      |
| ------------------------------------ | ------------------------------------------------------------- | ----------------------------------------- |
| `app/Http/Controllers/`              | 資源的 controller                                             | `DocumentationController`                 |
| `app/Http/Controllers/Auth/`         | 登入、登出、token 相關的 controller                           | `TokenRefreshController`                  |
| `app/Http/Controllers/Auth/Concerns/`| 只給幾支 controller 共用的 trait                              | `IssuesFrontendTokens`                    |
| `app/Http/Requests/`                 | 輸入驗證（FormRequest）                                       | `StoreScopeRequest`                       |
| `app/Http/Resources/`                | 輸出形狀（JsonResource）                                      | `ScopeResource`                           |
| `app/Http/Middleware/`               | middleware                                                    | `EnsureFrontendOrigin`                    |
| `app/Http/` 根層                     | 只跟 HTTP 有關、不屬於上面任何一類的小工具                    | `ProblemDetails`、`RefreshTokenCookie`    |
| `app/Policies/`                      | 授權，一個 model 一支                                         | `DocumentationPolicy`                     |
| `app/Rules/`                         | 自訂驗證規則                                                  | `ReverseIsAvailable`                      |
| `app/Models/`                        | Eloquent model、連結表 model                                  | `Relation`、`DocumentationTechniqueLink`  |
| `app/Service/`                       | 跨 controller、指令共用的商業邏輯與查詢                       | `RefreshTokenFamilies`、`RelationEdgeQuery` |
| `app/Exceptions/`                    | 自訂例外（自己負責變成 HTTP 回應）                            | `RelationLockedException`                 |
| `app/Logging/`                       | 日誌格式、安全事件、稽核紀錄                                  | `SecurityLog`、`AdminAudit`               |
| `app/OpenApi/`                       | Scramble 推不出來、要手動補的文件設定                         | `ProblemDetailsResponses`                 |
| `app/Console/Commands/`              | artisan 指令                                                  | `SyncGitHubRepos`                         |
| `app/Traits/`                        | 跨層共用的 trait                                              | `SyncsPivotRelations`                     |
| `database/seeders/fixtures/`         | seeder 讀的固定資料（JSON、Markdown）                         | `github-repos-snapshot.json`              |
| `tests/Feature/`、`tests/Unit/`      | 測試，見 [`testing.md`](testing.md)                           |                                           |
| `tests/Concerns/`                    | 測試共用的 trait                                              | `CapturesSecurityEvents`                  |

- 新增資料夾前先確認上表沒有合適的位置。

## 各層負責什麼

下面這些規則是 SOLID 落到這個 repo 的具體做法：一個類別只做一件事（單一職責）、依賴從外面傳進來（依賴反轉）、共用行為放在共同的源頭，不在每個呼叫點各寫一份（開放封閉）。

### controller 只做串接

一個 action 只做四件事，依序是：授權、取得驗證過的輸入、呼叫 model 或 service、用 Resource 回傳。

```php
// app/Http/Controllers/DocumentationController.php
public function store(StoreDocumentationRequest $request)
{
    $this->authorize('create', Documentation::class);
    $data = $request->validated();
    $documentation = Documentation::create(Arr::except($data, ['techniques', 'implementations']));
    // …syncPivot()…
    return response()->json([
        'data' => new DocumentationResource($documentation->load([...])),
        'message' => 'Documentation created.',
    ], 201);
}
```

- 演算法、多步驟的資料處理、會被第二個地方用到的邏輯，都搬到 `app/Service/` 或 model。例子：述詞的邊怎麼查、怎麼分頁，寫在 `RelationEdgeQuery`，`RelationController::edges()` 只呼叫它。
- 幾支 controller 共用的小段流程寫成 trait：`SyncsPivotRelations::syncPivot()`、`IssuesFrontendTokens`。

### 驗證放 FormRequest

- 每個寫入 action 都收 `Store<Model>Request`／`Update<Model>Request`，controller 只讀 `$request->validated()`。
- 一條規則寫不完的檢查，寫成 `app/Rules/` 的類別：`ReverseIsAvailable`、`ReverseIsSwapped`。
- FormRequest 的 `authorize()` 固定回 `true`，授權交給 Policy（見下一節）。

### 授權放 Policy

- 一個 model 一支 Policy（`app/Policies/<Model>Policy.php`），Laravel 依名稱自動對應，不用另外註冊。
- controller 用 `$this->authorize('update', $model)` 呼叫。路由層的 `auth:sanctum` 只擋沒登入的請求，不能取代 Policy。

### 輸出形狀放 Resource

- 回傳 model 一律經過 `<Model>Resource`，不要直接回傳 model（不然加一個欄位就會被公開出去）。
- 關聯用 `whenLoaded()`、計數用 `whenCounted()`。要多查資料庫的欄位做成可以選擇打開的方法：`ScopeResource::withFormHints()`，只有 show 會開。
- 回應不是 model、只能手動組陣列的（例如圖譜），要在 action 上用 `#[Response(...)]` 寫明形狀，見 [`api.md`](api.md)。

### 不管從哪裡寫入都要成立的規則，放 model

- 只要寫進資料庫就一定要成立的規則（例如被引用的述詞不能改名），放在 model 事件裡，不放在 controller：`Relation::booted()` 的 `updating` 會丟 `RelationLockedException`，從 controller、artisan 指令、seeder 寫入都擋得到。
- 例外自己負責變成 HTTP 回應（`render()`），也自己決定要不要寫進 error log（`report()`）。呼叫端不用自己 try/catch：`RelationLockedException`。
- 錯誤回應的格式只在一個地方定義：`bootstrap/app.php` 的 `withExceptions()` 加上 `App\Http\ProblemDetails`。

### 依賴從外面傳進來

- service 用方法參數注入，或用建構子注入：`TokenRefreshController::refresh(Request $request, RefreshTokenFamilies $families)`、`RequestLogProcessor::__construct(private readonly Container $app)`。
- 拿 model 當參數的查詢物件，可以直接 `new`：`new RelationEdgeQuery($relation)`。
- （提議）不要在類別裡 `new` 一個會打外部服務的類別，也不要用 `app()`、`request()` 這類 helper 臨時拿依賴。測試要換掉它時才換得掉。
- （提議）目前沒有自訂 interface。等某個依賴真的出現第二種實作時再抽 interface，不要先抽好備用。

## 目前已知不符的地方

- `GraphController`：最短路徑（BFS）、組節點、組邊都寫在 controller 裡，`path()` 也在 controller 裡驗證。之後要大改圖譜時，先把邏輯搬進 `app/Service/`。
- `ScopeController::store/update` 推出 `class_number`、`RelationController::store` 推出 `parent_class`：這是領域規則，應該放在 model 或 service。
- `ScopeController::update` 回傳 `'data' => $scope`（原始 model），沒有經過 `ScopeResource`。
- `CurrentUserController`、`IssuesFrontendTokens::tokenPairResponse()` 直接回傳 `User` model（現在靠 `$hidden` 擋掉密碼）。
- `SaveReposDataService::__construct` 自己 `new GitService` 並在建構子裡打 GitHub API；`IssuesFrontendTokens` 用 `app()` 拿 `RefreshTokenFamilies`；`RelationController::edges` 用 `request()`。
- `App\Traits\SetCURIEAttribute` 除了 accessor，還定義了 `parent/children/siblings` 關聯，名字跟內容對不上。
- `ScopeController::edit`、`RelationController::edit` 沒有路由，是死碼。
