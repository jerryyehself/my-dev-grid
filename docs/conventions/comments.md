# 註解與 PHPDoc

依據 [PSR-5](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc.md) 與 [PSR-19](https://github.com/php-fig/fig-standards/blob/master/proposed/phpdoc-tags.md)（兩份都還是草案）、[phpDocumentor 的 DocBlock 指南](https://docs.phpdoc.org/guide/guides/docblocks.html)、[Laravel 貢獻指南的 PHPDoc 一節](https://laravel.com/docs/12.x/contributions#phpdoc)、[Spatie 的 PHP 指南（Docblocks）](https://spatie.be/guidelines/php)、[Scramble 的 request 與 response 文件](https://scramble.dedoc.co/usage/request)，以及這個 repo 現有程式碼的寫法。

程式碼風格（縮排、`use` 排序、docblock 的 `@param` 對齊）交給 Pint，見 [`naming.md`](naming.md)。這裡管的是「寫什麼、寫在哪、用什麼語言寫」。

## 要不要寫

- **寫為什麼，不寫做了什麼。** 程式碼讀得出來的事不用註解重講一遍；讀不出來的才寫：取捨的理由、踩過的坑、刻意不做的事、必須跟別處保持一致的地方、跟外部規格的對應。
  ```php
  // 不要在關聯定義裡排除自己：eager loading 時 Eloquent 會對一個沒有 key 的 newInstance()
  // 呼叫關聯方法，where('id', '!=', $this->id) 會退化成 id IS NOT NULL，什麼都濾不掉。
  ```
- **不要寫的：**
  - 複述名稱或型別：`/** 取得使用者 */ function getUser(): User`。
  - Laravel 產生器留下的樣板句子（見下面「樣板註解」）。
  - 被註解掉的程式碼。要留的用 git 留，不要放在檔案裡。
  - （提議）改動歷史（「2026-09-17 之前這裡叫…」）。歷史寫在 commit 和 PR；只有「不寫會讓下一個人把它改回去」的時候，才用一兩句話交代現況為什麼是這樣。
- **公開 repo，註解也是公開的。** 不寫密鑰、正式環境的資料、維護者的原話、私有 repo 的路徑，見 [`issues.md`](issues.md)「不能寫」。決策編號（`D-05`）可以寫，但要同時寫出結論，不要只丟編號。
- 註解和程式碼不一致時，改程式碼的人一起改註解。對不上的註解比沒有註解更糟（現有的例子在最後面的「目前已知不符的地方」）。

## 型別優先，docblock 補型別補不到的

PHP 的原生型別（參數、回傳、屬性）能表達的，就不要再用 docblock 重講一遍。Laravel 自己的貢獻指南與 Spatie 的指南都是這樣主張。

- 型別完整的方法**不需要 docblock**；有 docblock 就是因為有話要說。
  ```php
  public function viewerCanSeeDrafts(): bool
  ```
- **要寫 `@param`／`@return`／`@var` 的情況：原生型別表達不出內容。**
  - 陣列與集合的內容：`@return array<string, mixed>`、`@return Collection<int, RefreshToken>`、`@var list<string>`。
  - 固定 key 的陣列：`@return array{0: RefreshTokenOutcome, 1: TokensDTO|null}`。
  - 型別對、但缺一句話就看不懂的參數：`@param  string  $mode  session（Triple 後台）或 token（my-dev-grid-front）`。
- **不要寫的：** `@return void`、跟原生型別一模一樣的 `@param string $name`。這類重複的 tag，Pint 的 `no_superfluous_phpdoc_tags` 會自動刪掉。原生型別寫不出來時（方法沒有宣告回傳型別），`@return Provider` 這種只有型別的 tag 才有用（`SocialiteDriver::for()`）；能補上原生型別就補，再刪掉 tag。
- `@throws`：呼叫端必須知道、而且從方法簽名看不出來的例外才寫（PSR-19 建議每個 throw 都寫；我們只寫對呼叫端有意義的，例如驗證類的例外交給框架處理，不用列）。目前 repo 還沒有這種寫法。
- 格式照 Laravel：`@param` 後面空兩格、型別、空兩格、變數名、空兩格、說明（Pint 會對齊）。tag 之間依 `@param`、`@return`、`@throws` 的順序，不同種類的 tag 之間空一行。
  ```php
  /**
   * @param  Collection<int, Relation>  $relationsById  以 id 為 key
   * @return array<int, string>
   *
   * @throws RelationLockedException
   */
  ```
- docblock 裡的類別名稱用 `use` 匯入後的短名稱，不寫 `\Illuminate\...` 全名（跟 Spatie 一樣）。
- 只有一個 tag、沒有文字說明時，寫成單行：`/** @var list<string> */`、`/** @use HasFactory<UserFactory> */`。

## 結構：摘要、說明、tag

PSR-5 與 phpDocumentor 把 docblock 分成三段，順序固定：

1. **摘要**：第一行，一句話，句號結尾。說明這個東西「是什麼」或「為什麼存在」。
2. **說明**：空一行之後，需要時才寫。放為什麼、限制、取捨、不明顯的行為。可以用 Markdown（反引號、`-` 清單）。
3. **tag**：最後，跟上面空一行。

只有摘要一句時，不需要三段都有：`/** 開啟 new_child_call_number。 */` 就夠了。

## 語言

- 說明文字用**繁體中文**（跟 `CLAUDE.md` 的語言慣例一致）。
- tag 名稱（`@param`）、型別（`array<string, mixed>`）、程式碼、檔名、套件名、既有的英文專有名詞（Eloquent、Sanctum、problem details）維持原文，不硬翻。引用程式碼用反引號：`Relation::syncReverse()`。
- 中文句子用全形標點（，。：；（））。中英文之間不強制加空白。
- 例外訊息（`new Exception('…')`、`abort(409, '…')`）是給使用者看的文字，用中文；跟註解是兩回事，規則在 [`api.md`](api.md)「錯誤」。
- 既有的英文註解不用為了翻譯單獨開 PR，改到那個檔案時順手換成中文（清單在最後面）。

## `/** */` 與 `//`

| 寫法 | 用在哪裡 |
| --- | --- |
| `/** … */` | 類別、方法、屬性、常數的說明；以及 Resource／FormRequest 陣列 key 上方的欄位說明（會公開，見下一節） |
| `//` | 方法**裡面**，說明下面那一行或那一段程式為什麼這樣寫 |

- 不用 `/* … */` 和 `#`。
- `//` 寫在被說明的程式碼正上方，不寫在行尾；說明的範圍是它下面到下一個空行為止。
- 多行的 `//` 寫成一段話，不要每行斷句。
- 沒有內容的空方法，`//` 後面不用補字：Laravel 產生的 `register(): void { // }`。

## 給 Scramble 的註解，與只給開發者的註解

Scramble 產生 `/docs/api` 時，會讀這幾個位置的註解，**原文公開**。這些位置不要寫給開發者看的話。實際行為可以對照 `vendor/dedoc/scramble/src/Infer/Visitors/PhpDocResolver.php` 與 `Support/OperationExtensions/` 底下的檔案。

| 位置 | 會變成 | 寫法 |
| --- | --- | --- |
| controller action 上的 `#[Endpoint(title, description)]` | 端點標題與說明 | 見 [`api.md`](api.md) |
| controller action 的 PHPDoc | 沒有 `#[Endpoint]` 時的標題（第一行）與說明；`description` 有填會蓋掉說明 | 有 `#[Endpoint]` 就不要再寫 |
| controller action 的 `@param Model $x  說明` | 路徑參數的說明 | 需要時才寫 |
| FormRequest 的**類別** docblock | 請求本文的說明（端點沒有 `description` 時） | 預設不寫 |
| FormRequest `rules()` 裡 key 正上方的註解 | 欄位說明 | 下面「key 的註解」 |
| Resource `toArray()` 裡 key 正上方的註解 | 欄位說明 | 下面「key 的註解」 |

不會公開的位置：方法裡其他地方的 `//`、方法與屬性的 docblock（controller action 以外）、Resource 的類別 docblock、migration、seeder、model、service、Policy。這些放心寫給開發者。（上表的公開行為，用探針註解實際跑過 `scramble:export` 確認過。）

### key 的註解

- 給 API 讀者看的說明：寫成緊貼 key 的單行 `/** … */`。內容是欄位的意義與限制，不是實作理由。可以用 `@example`、`@default`、`@format`，要覆寫推斷出來的型別用 `@var`（Scramble 規定：必須是字串或合法的 JSON）。
- 只給開發者看的理由：寫成 `/** */` **上方**的 `//` 註解。
- **同一個 key 上有 `/** */` 時，`//` 不會被輸出；只有 `//`、沒有 `/** */` 時，`//` 的內容會被當成欄位說明公開。** Scramble 把 key 上方所有註解合併起來當作說明，沒有 docblock 才會用到 `//`。所以：key 上要寫開發者理由，就連同一行 `/** … */` 一起寫；沒有要給 API 讀者的說明，就不要在 key 上方寫 `//`，改寫在 `rules()`／`toArray()` 的 docblock 或方法內其他位置。
- 給 API 讀者的文字不要出現：內部的決策或規格編號、issue 編號、私有 repo 的路徑、資料庫裡實際有幾筆資料、寫給前端的指示。

## TODO 與標記

- （提議）TODO 一律寫成 `// TODO(#123): 要做什麼，以及為什麼現在還沒做。`，括號裡是 issue 編號。沒有 issue 就先開 issue（見 [`issues.md`](issues.md)），不要留下查不到來源的 TODO。做完就刪掉。PSR-19 的 `@todo` 也要求說明後續要做什麼，短的 issue 編號可以接受。
- 現有的 `// ponytail:` 標記（五個 Policy）：表示「這裡刻意先做簡單版」，後面寫為什麼簡單可以、什麼時候要重做。保留這個用法，不新增別的標記名稱。
- 已有的 `NOTE(日期):`（`SaveReposDataService` 的類別 docblock）不再新增；那種資訊直接寫進說明，不用加日期標記。

## 樣板註解

Laravel 的產生器（`make:policy`、`make:request`、`make:resource`、`make:migration`、`make:seeder`、`make:factory`、新專案的 `User` 與 `AppServiceProvider`）會留下英文的樣板註解。**產生之後就刪掉**，不要留著：

- `Determine whether the user can …`、`Determine if the user is authorized to make this request.`
- `Get the validation rules that apply to the request.`、`Transform the resource into an array.`
- `Run the migrations.`、`Reverse the migrations.`、`Run the database seeds.`、`Seed the application's database.`
- `Register any application services.`、`Bootstrap any application services.`
- `Define the model's default state.`、`The attributes that are mass assignable.` 之類。

刪掉之後，docblock 裡還有 `@return array<string, mixed>` 這類補型別的 tag 就留著，寫成單行或只剩 tag。

樣板註解下面的程式碼如果有不明顯的地方，換成真正的說明，不要只刪掉。例如 FormRequest 的 `authorize()`：

```php
public function authorize(): bool
{
    // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
    return true;
}
```

controller 的 PHPDoc 會變成端點標題（見上面），所以樣板註解留在 controller 上，會直接出現在公開的 API 文件裡。

## 範本

複製後把 `<…>` 換成實際內容。沒有東西可寫的段落整段刪掉，不要留空殼或填「無」。

### 類別

```php
/**
 * <一句話：這個類別負責什麼。>
 *
 * <為什麼需要它、為什麼這樣設計、有什麼不明顯的限制。沒有就整段省略。>
 */
class <Name>
```

例：`App\Rules\ReverseIsAvailable`（先說規則是什麼，再說沒有這道檢查會怎樣，最後列出允許的情況）。

### 方法

```php
/**
 * <一句話：意圖，或不明顯的行為。名稱和型別已經說明白就不要寫這個方法的 docblock。>
 *
 * @param  <型別>  $<name>  <型別補不到的限制。沒有就省略這行。>
 * @return <array<string, mixed> 這類泛型。原生型別已經夠了就省略這行。>
 *
 * @throws <ExceptionClass> <什麼情況會丟。沒有就省略。>
 */
public function <name>(): <type>
```

例：`ScopeResource::withFormHints()`（只有說明）、`RefreshTokenFamilies` 的 `@return Collection<int, RefreshToken>`（只有泛型）。

### controller action

公開的標題與說明寫在 `#[Endpoint]`，不寫 PHPDoc：

```php
#[Endpoint(
    title: '<簡短的中文標題>',
    description: '<給 API 使用者看的補充。沒有補充價值就省略這個參數。>',
)]
public function show(<Model> $<model>)
```

### FormRequest 的 `rules()` key

```php
// <只給開發者看的理由：為什麼這樣驗證。不會公開。>
/** <給 API 讀者看的欄位說明，單行。> */
'<field>' => '<rules>',
```

例：`StoreDocumentationRequest` 的 `body`。

### Resource 的 `toArray()` key

```php
// <只給開發者看的理由：為什麼要曝光這個欄位、為什麼是這個值。不會公開。>
/** <給 API 讀者看的欄位說明，單行。> */
'<field>' => $this-><field>,
```

例：`ScopeResource` 的 `parent_class`。需要範例值時：

```php
/**
 * <欄位說明>
 * @example "<範例值>"
 */
```

### migration

```php
/**
 * <為什麼要這次改動：背景，以及踩到的問題。>
 *
 * <不明顯的取捨；刻意沒做的事與原因。>
 */
return new class extends Migration
{
    public function up(): void
    {
        // …
    }

    /**
     * <只有 down() 不能完整還原，或刻意不還原時才寫：原因，以及該怎麼補救。>
     */
    public function down(): void
    {
        // …
    }
};
```

例：`2026_09_23_010518_make_call_number_nullable_on_scopes_and_relations_tables.php`（類別 docblock）、`2026_09_09_000000_reclassify_vue3_topic_from_packagetool_to_framework.php`（`down()` 刻意不還原）。`up()` 與 `down()` 做的事和檔名一致時，不用寫 docblock。

### 例外

```php
/**
 * <什麼情況下丟出，對應什麼 HTTP 狀態碼。>
 *
 * <為什麼由例外自己負責 render() 或 report()，而不是呼叫端各自處理。>
 */
class <Name>Exception extends RuntimeException
{
    /** <為什麼不寫進 error log：屬於用戶端錯誤。> */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        // …
    }
}
```

例：`App\Exceptions\RefreshTokenRejectedException`。

### 方法裡的 `//`

```php
// <為什麼這樣做；不要寫「這行做什麼」。>
```

## 目前已知不符的地方

- 英文的說明文字（要換成中文）：`App\Service\GitService`、`App\Service\SaveReposDataService`、`App\Http\Controllers\GraphController`、`App\Traits\SetCURIEAttribute`、`App\Traits\SyncsPivotRelations`、`App\Models\Relation`、`App\Models\EntityRelation` 與三個 `*Link` model、`App\Exceptions\RelationLockedException` 的類別 docblock、`App\Console\Commands\SnapshotGitHubRepos`、`database/seeders` 底下的 `GitHubReposSnapshotSeeder`、`RelationSeeder`。
- 部分舊註解用半形標點（`,`、`:`、`(`）寫中文，例如 `StoreScopeRequest` 的 `parent_class`。
- 英文例外訊息：`RelationLockedException::__construct()` 的訊息是英文，`render()` 才是中文（見 [`api.md`](api.md)「錯誤」）。
- `SaveReposDataService` 的類別 docblock 帶了 `NOTE(2026-09-07)` 的歷史說明。
