# 命名與程式碼風格

依據 [PSR-1](https://www.php-fig.org/psr/psr-1/)、[PSR-12](https://www.php-fig.org/psr/psr-12/)（透過 Laravel Pint）、Laravel 的命名慣例，以及這個 repo 現有程式碼的寫法。

## 程式碼風格

- 以 **Laravel Pint 的 Laravel preset** 為準（repo 沒有 `pint.json`，就是用預設的 preset，以 PSR-12 為基礎）。送 PR 前跑 `./vendor/bin/pint` 自動修正，或用 `./vendor/bin/pint --test` 只檢查。
- 目前整個 repo 都通過 `pint --test`。CI 還沒有跑這項檢查；要不要加進 CI，另外決定。
- 縮排、換行、檔尾空行照 `.editorconfig`：4 個空白、LF、檔尾留一行空行。
- Pint 不會改名稱。下面的命名規則（PSR-1 的部分）要靠審查。

## 類別

- 類別名用 StudlyCaps（PSR-1），一個檔案一個類別，檔名與類別名相同，命名空間對應資料夾（PSR-4，見 `composer.json` 的 `autoload`）。
- model 用單數：`Documentation`、`Relation`。連結表的 model 是兩邊名稱加 `Link`：`DocumentationTechniqueLink`。
- controller：`<Model>Controller`；登入相關的放 `Auth/`，用它做的事命名：`TokenRefreshController`、`CurrentUserController`。只有一個 action 的 controller 用 `__invoke`：`CurrentUserController`。
- FormRequest：`Store<Model>Request`、`Update<Model>Request`。
- Resource：`<Model>Resource`。Policy：`<Model>Policy`。
- 驗證規則寫成一個描述條件的句子：`ReverseIsAvailable`、`ReverseIsSwapped`。
- 例外以 `Exception` 結尾，名稱說明發生了什麼：`RelationLockedException`、`RefreshTokenRejectedException`。
- trait 用動詞第三人稱，說明它替使用的類別做什麼：`SyncsPivotRelations`、`IssuesFrontendTokens`、`CapturesSecurityEvents`。
- service 依它做的事命名，不一定要以 `Service` 結尾：`RefreshTokenFamilies`、`RelationEdgeQuery`、`SocialiteDriver`。
- 值是固定幾種的，用 enum，case 用 StudlyCaps：`RefreshTokenOutcome::Rotated`、`SecurityEvent::LoginFail`。
- 不會被繼承的工具類別加 `final`：`SecurityLog`、`AdminAudit`、`SocialiteDriver`。

## 方法、屬性、常數

- 方法用 camelCase（PSR-1）：`syncReverse()`、`viewerCanSeeDrafts()`。
- 回傳 bool 的方法用 `is`／`has`／`can` 開頭：`isPublished()`、`hasOwnReferences()`、`viewerCanSeeDrafts()`。
- query scope 用 `scope` 前綴，呼叫時去掉前綴：`scopeVisibleToViewer()` → `->visibleToViewer()`；`scopeWithDetailCounts()` → `->withDetailCounts()`。
- 新的 accessor 用回傳 `Attribute` 的寫法，方法名用 camelCase：`Technique::label()`。舊的 `getXxxAttribute()`（`SetCURIEAttribute`）改到時再換。
- 類別常數用 UPPER_CASE（PSR-1）：`Documentation::STATUS_PUBLISHED`、`Relation::LOCKED_FIELDS`、`RelationEdgeQuery::MAX_PER_PAGE`。
- 變數、屬性用 camelCase：`$validatedData`、`$scopeIdCache`。
- 新的屬性與參數要寫型別（提議：新寫的 action 也加回傳型別，例如 `: JsonResponse`；`Auth/` 底下的 controller 已經有，資源 controller 還沒有）。
- 能寫成 `private readonly` 建構子屬性的就這樣寫：`RelationEdgeQuery::__construct(private readonly Relation $relation)`。

## 資料庫

- 資料表名用複數 snake_case：`documentations`、`entity_relations`。
- 多對多的連結表用兩個單數名稱、依字母排序：`documentation_technique`、`technique_implementation`（Laravel 的預設）。
- 欄位用 snake_case：`git_repo_created_at`、`source_vocabulary`。
- （提議）新的外鍵欄位用 `<單數名稱>_id`：`relation_id`、`subject_id`。現有的 `documentations.type`、`scopes.parent_class` 不符合，改名要照 [`data.md`](data.md) 分次部署。
- migration 檔名用 Laravel 產生的時間戳記加動作描述：`2026_09_16_000000_add_body_to_documentations_table.php`、`..._reclassify_vue3_topic_from_packagetool_to_framework.php`。

## 路由與指令

- API 路徑用複數資源名：`/api/scopes`、`/api/relations/{relation}/edges`。
- 具名路由用點分隔、由大到小：`api.auth.login`、`auth.token.social.callback`。
- artisan 指令寫成 `<範圍>:<動詞>-<名詞>`：`github:sync-repos`、`github:snapshot-repos`。

## API 回應的 key

- JSON key 用 snake_case：`full_call_number`、`new_child_call_number`、`own_edges_count`。
- 計數欄位以 `_count` 結尾，跟 Laravel `withCount()` 的命名一致：`children_count`。

## 目前已知不符的地方

- 方法名用了 snake_case（PSR-1）：`App\Service\GitService` 的 `get_repos`、`clean_repo_info`、`get_languages`；`App\Service\SaveReposDataService` 的 `save_repos_data`、`save_repos_content`、`link_project_to_techniques`、`find_or_create_technique`、`link_framework_to_base_language`、`link_official_docs`、`scope_id`、`relation_id`。改名時要一起改 `SyncGitHubRepos` 指令跟測試的呼叫點。
- 測試類別 `tests/Feature/relationCRUDTest.php` 開頭是小寫，不是 StudlyCaps（PSR-1）。改成 `RelationCRUDTest` 時，檔名要一起改。
- `SaveReposDataService::$gitService` 存的是 repo 清單，不是 `GitService`；而且沒有型別，又是 public。
- 回應 key 不是 snake_case 的地方：`ScopeResource`、`RelationResource` 的 `ReferenceCode`；`GET /api/graph/path` 的 `storedDirection`、`hasDefinedReverse`。這些是對外的 API，改名會讓前端壞掉，要先新增新的 key、等前端改完再拿掉舊的，不能順手改。
- `App\Logging\AdminAudit` 的註解寫 `snapshot:github-repos`，實際的指令是 `github:snapshot-repos`。
