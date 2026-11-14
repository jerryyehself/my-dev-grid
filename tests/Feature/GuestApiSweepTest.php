<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Traits\ResolvesPositiveIntegerRouteKeys;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 訪客（沒登入）打遍 `api/` 底下每一條路由，新增的端點不可能沒人歸類就上線。
 *
 * 單獨測每個端點的測試（`DraftVisibilityTest`、`CurrentUserTest` 等）只守得住寫的時候
 * 想到的那幾支；新加一條路由、忘了掛 `auth:sanctum` 或忘了套可見性條件，不會有任何測試變紅。
 * 這裡反過來從 `Route::getRoutes()` 出發：
 *
 * - `expectations()` 是明文的對照表，每條路由（方法加網址）一筆，歸成三類：
 *   `auth`（訪客一律 401）、`public`（訪客可讀，但不能 5xx，也不能帶出看不到的資料）、
 *   `special`（登入、登出、換發 token 這種有自己規則的，逐一寫明訪客會得到什麼）。
 * - 路由表裡有、對照表沒有的路由，測試失敗並指名要歸類；對照表有、路由表沒有的，也失敗。
 * - 公開路由用一組夾具資料打：每種實體都有已公開、草稿或不公開、已軟刪除的列，
 *   每一列的文字欄位都帶獨一無二的標記字串，任何一個訪客回應只要含有看不到的那幾個標記就失敗。
 *
 * - 網址裡的 id 格式不合法（不是正整數、超出 bigint 範圍）時，每一條帶 id 的路由都要回 404
 *   （沒登入的寫入路由先回 401），而且那個值不能出現在任何查詢的參數裡。
 *   路由表裡新增的帶 id 路由，只要綁定的 model 沒有套用 `ResolvesPositiveIntegerRouteKeys`，
 *   結構檢查與這個掃描都會失敗，所以不需要有人記得回來補。
 *
 * 範圍是 `api/` 底下的路由；`routes/web.php` 的 `/auth/*` 不在這裡。
 */
class GuestApiSweepTest extends TestCase
{
    use RefreshDatabase;

    private const KIND_AUTH = 'auth';

    private const KIND_PUBLIC = 'public';

    private const KIND_SPECIAL = 'special';

    /** 看得到的列的標記。掃描結束後每一個都要出現在某個訪客回應裡，證明夾具有被讀到。 */
    private const LIVE_MARKERS = [
        'documentation' => 'sweep-live-documentation',
        'technique' => 'sweep-live-technique',
        'implementation' => 'sweep-live-implementation',
        'scope' => 'sweep-live-scope',
        'scope under a deleted parent' => 'sweep-live-orphaned-scope',
        'relation' => 'sweep-live-relation',
    ];

    /** 訪客不該看到的列的標記：名稱只是讓失敗訊息好讀。 */
    private const HIDDEN_MARKERS = [
        'draft documentation' => 'sweep-draft-documentation',
        'null-status documentation' => 'sweep-nullstatus-documentation',
        'hidden implementation' => 'sweep-hidden-implementation',
        'deleted documentation' => 'sweep-deleted-documentation',
        'deleted technique' => 'sweep-deleted-technique',
        'deleted implementation' => 'sweep-deleted-implementation',
        'deleted scope' => 'sweep-deleted-scope',
        'deleted top-level scope' => 'sweep-deleted-toplevel-scope',
        'deleted relation' => 'sweep-deleted-relation',
    ];

    /**
     * 夾具的 id，依「路由參數名稱 => 變體名稱 => id」排。第一個變體一定是看得到的那一列。
     *
     * @var array<string, array<string, int>>
     */
    private array $ids = [];

    private int $requestCount = 0;

    /**
     * 每條 `api/` 路由（方法加網址）一筆。新增路由時照這裡的格式補一筆並寫明理由。
     *
     * - `auth`：訪客一律 401。可以另外用 `middleware` 列出必須掛著的 middleware。
     * - `public`：訪客可讀。端點讀查詢參數（篩選、分頁）的，要在 `queries` 列出來，掃描會帶著打一遍。
     * - `special`：有自己規則，`cases` 逐一列出訪客在各種情況下得到的狀態碼
     *   （`origin` 表示有沒有帶前端的 `Origin`）。
     *
     * @return array<string, array<string, mixed>>
     */
    private static function expectations(): array
    {
        return [
            // 登入、換發 token
            'POST api/auth/login' => self::special('登入本來就是給訪客用的；要掛 login limiter（每分鐘 5 次），沒帶帳密回 422。', [
                ['origin' => false, 'status' => 422],
            ], ['throttle:login']),
            'POST api/auth/logout' => self::special('不掛 auth:sanctum，bearer 或 refresh cookie 任一個就能登出自己；改由 Origin 檢查擋掉非前端的請求。', [
                ['origin' => false, 'status' => 403],
                ['origin' => true, 'status' => 200],
            ], ['throttle:token-refresh', 'frontend.origin']),
            'POST api/auth/refresh' => self::special('只看 refresh cookie、不帶 Authorization；要求前端的 Origin，有 Origin 但沒 cookie 回 401。', [
                ['origin' => false, 'status' => 403],
                ['origin' => true, 'status' => 401],
            ], ['throttle:token-refresh', 'frontend.origin']),
            'POST api/auth/session' => self::auth('OAuth 回呼的短效 token 換正式 token，要登入；另外要前端的 Origin。', ['throttle:token-refresh', 'frontend.origin']),
            'GET api/user' => self::auth('前端開機確認登入狀態，沒登入回 401。'),

            // 分類
            'GET api/scopes' => self::publicRead(),
            'GET api/scopes/create' => self::publicRead('表單欄位定義。'),
            'GET api/scopes/{scope}' => self::publicRead(),
            'POST api/scopes' => self::auth(),
            'PUT api/scopes/{scope}' => self::auth(),
            'PATCH api/scopes/{scope}' => self::auth(),
            'DELETE api/scopes/{scope}' => self::auth(),

            // 述詞
            'GET api/relations' => self::publicRead(),
            'GET api/relations/create' => self::publicRead('表單欄位定義。'),
            'GET api/relations/{relation}' => self::publicRead(),
            'GET api/relations/{relation}/edges' => self::publicRead('使用某條述詞的邊（分頁）。', ['per_page=100']),
            'POST api/relations' => self::auth(),
            'PUT api/relations/{relation}' => self::auth(),
            'PATCH api/relations/{relation}' => self::auth(),
            'DELETE api/relations/{relation}' => self::auth(),

            // 文件
            'GET api/documentations' => self::publicRead('草稿只給登入的人看。'),
            'GET api/documentations/{documentation}' => self::publicRead('草稿對訪客等同不存在。'),
            'POST api/documentations' => self::auth(),
            'PUT api/documentations/{documentation}' => self::auth(),
            'PATCH api/documentations/{documentation}' => self::auth(),
            'DELETE api/documentations/{documentation}' => self::auth(),

            // 技術
            'GET api/techniques' => self::publicRead(),
            'GET api/techniques/{technique}' => self::publicRead(),
            'POST api/techniques' => self::auth(),
            'PUT api/techniques/{technique}' => self::auth(),
            'PATCH api/techniques/{technique}' => self::auth(),
            'DELETE api/techniques/{technique}' => self::auth(),

            // 實作
            'GET api/implementations' => self::publicRead('設為不公開的實作只給登入的人看，帶 type 篩選也一樣。', ['type={scope}']),
            'GET api/implementations/{implementation}' => self::publicRead('不公開的實作對訪客等同不存在。'),
            'POST api/implementations' => self::auth(),
            'PUT api/implementations/{implementation}' => self::auth(),
            'PATCH api/implementations/{implementation}' => self::auth(),
            'DELETE api/implementations/{implementation}' => self::auth(),

            // 從文章來源 repo 匯入文章：清單帶出來源檔案路徑，連讀取也要登入
            'GET api/article-sources' => self::auth('列出文章來源 repo 的檔案與匯入狀態，只給登入的人看。'),
            'POST api/article-sources/import' => self::auth(),

            // 知識圖譜
            'GET api/graph' => self::publicRead('節點與邊，不含草稿與不公開的實作。'),
            'GET api/graph/path' => self::publicRead('最短路徑，起訖點與經過的節點不含草稿與不公開的實作。'),
        ];
    }

    /**
     * @param  list<string>  $middleware
     * @return array<string, mixed>
     */
    private static function auth(string $why = '寫入端點，沒登入一律 401。', array $middleware = []): array
    {
        return ['kind' => self::KIND_AUTH, 'why' => $why, 'middleware' => $middleware];
    }

    /**
     * @param  list<string>  $queries  端點讀的查詢參數，每一項是一條 query string；
     *                                 `{scope}` 這類參數會換成夾具裡每一種分類的 id。
     * @return array<string, mixed>
     */
    private static function publicRead(string $why = '公開唯讀。', array $queries = []): array
    {
        return ['kind' => self::KIND_PUBLIC, 'why' => $why, 'middleware' => [], 'queries' => $queries];
    }

    /**
     * @param  list<array{origin: bool, status: int}>  $cases
     * @param  list<string>  $middleware
     * @return array<string, mixed>
     */
    private static function special(string $why, array $cases, array $middleware): array
    {
        return ['kind' => self::KIND_SPECIAL, 'why' => $why, 'cases' => $cases, 'middleware' => $middleware];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFixture();
    }

    #[TestDox('每條 api 路由都在對照表裡歸類，對照表也沒有多餘的項目')]
    public function test_every_api_route_is_classified_and_no_entry_is_stale()
    {
        $actual = array_keys($this->apiRoutes());
        $declared = array_keys(self::expectations());

        $unclassified = array_values(array_diff($actual, $declared));
        $this->assertSame([], $unclassified, '這些路由還沒歸類：'.implode('、', $unclassified)
            .'。請在 GuestApiSweepTest::expectations() 各加一筆，標明是 auth（訪客 401）、public（訪客可讀）還是 special，並寫明理由。');

        $stale = array_values(array_diff($declared, $actual));
        $this->assertSame([], $stale, '對照表裡這些項目已經沒有對應的路由：'.implode('、', $stale).'。請從 expectations() 刪掉。');

        foreach (self::expectations() as $key => $entry) {
            $this->assertContains($entry['kind'], [self::KIND_AUTH, self::KIND_PUBLIC, self::KIND_SPECIAL], "{$key} 的分類不是 auth、public、special 其中之一。");
            $this->assertNotSame('', $entry['why'], "{$key} 沒有寫理由。");
        }
    }

    #[TestDox('訪客打需要登入的路由，一律回 401')]
    public function test_guest_gets_401_from_every_auth_required_route()
    {
        $failures = [];
        foreach ($this->routesOfKind(self::KIND_AUTH) as $key => $route) {
            [$method, $uri] = explode(' ', $key, 2);
            $status = $this->guestCall($method, $this->urlFor($uri))->getStatusCode();

            if ($status !== 401) {
                $failures[] = "{$key} 回 {$status}";
            }
        }

        $this->assertSame([], $failures, '這些歸類為 auth 的路由，訪客沒有得到 401（是不是漏掛了 auth:sanctum？）');
    }

    #[TestDox('有特殊規則的路由，訪客得到對照表寫明的結果')]
    public function test_guest_gets_the_documented_outcome_on_special_routes()
    {
        $failures = [];
        foreach ($this->routesOfKind(self::KIND_SPECIAL) as $key => $route) {
            [$method, $uri] = explode(' ', $key, 2);

            foreach (self::expectations()[$key]['cases'] as $case) {
                $server = $case['origin'] ? ['HTTP_ORIGIN' => config('app.frontend_url')] : [];
                $status = $this->guestCall($method, $this->urlFor($uri), $server)->getStatusCode();

                if ($status !== $case['status']) {
                    $label = $case['origin'] ? '帶前端 Origin' : '不帶 Origin';
                    $failures[] = "{$key}（{$label}）應回 {$case['status']}，實際 {$status}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    #[TestDox('對照表要求的 middleware 都還掛在路由上')]
    public function test_routes_keep_the_middleware_the_table_requires()
    {
        $this->assertSame([], $this->missingMiddleware(), '這些路由少了對照表要求的 middleware');
    }

    #[TestDox('訪客讀公開路由不會 5xx，回應也不含草稿、不公開或已刪除的資料')]
    public function test_guest_responses_from_public_routes_never_contain_hidden_rows()
    {
        $failures = [];
        $seenLive = array_fill_keys(array_values(self::LIVE_MARKERS), false);

        foreach ($this->routesOfKind(self::KIND_PUBLIC) as $key => $route) {
            [$method, $uri] = explode(' ', $key, 2);

            foreach ($this->callsFor($key) as $call) {
                $response = $this->guestCall($method, $call['url']);
                $status = $response->getStatusCode();
                $body = $this->withoutAllowedPredicateNames($uri, $response->getContent());
                $where = "{$method} {$call['url']}（{$call['label']}）";

                if ($status >= 500) {
                    $failures[] = "{$where} 回 {$status}";
                }
                // 看得到的那一列一定要打得開，不然下面「沒有洩漏」只是空轉
                if ($call['live'] && $status !== 200) {
                    $failures[] = "{$where} 應回 200，實際 {$status}";
                }

                foreach (self::HIDDEN_MARKERS as $name => $marker) {
                    if (str_contains($body, $marker)) {
                        $failures[] = "{$where} 洩漏了 {$name}（{$marker}）";
                    }
                }
                foreach (array_keys($seenLive) as $marker) {
                    $seenLive[$marker] = $seenLive[$marker] || str_contains($body, $marker);
                }
            }
        }

        $this->assertSame([], $failures, '訪客的公開回應出問題');

        $neverSeen = array_keys(array_filter($seenLive, fn (bool $seen) => ! $seen));
        $this->assertSame([], $neverSeen, '這些看得到的列沒有出現在任何訪客回應裡，夾具可能沒接上，掃描有空轉的風險');
    }

    #[TestDox('同一批資料，登入後看得到草稿與不公開的實作，所以訪客的掃描不是空轉')]
    public function test_signed_in_viewer_sees_the_rows_the_guest_sweep_checks_for()
    {
        $this->actingAsOwner();

        $documentations = $this->getJson('/api/documentations')->assertOk()->getContent();
        $this->assertStringContainsString(self::HIDDEN_MARKERS['draft documentation'], $documentations);
        $this->assertStringContainsString(self::HIDDEN_MARKERS['null-status documentation'], $documentations);
        $this->assertStringNotContainsString(self::HIDDEN_MARKERS['deleted documentation'], $documentations);

        $implementations = $this->getJson('/api/implementations')->assertOk()->getContent();
        $this->assertStringContainsString(self::HIDDEN_MARKERS['hidden implementation'], $implementations);
        $this->assertStringNotContainsString(self::HIDDEN_MARKERS['deleted implementation'], $implementations);

        // 登入後，經過草稿文件就連得起來
        $start = 'technique-'.$this->ids['technique']['live'];
        $end = 'implementation-'.$this->ids['implementation']['live'];
        $this->getJson("/api/graph/path?start={$start}&end={$end}")
            ->assertOk()
            ->assertJsonPath('found', true);
    }

    #[TestDox('訪客的分類筆數不含草稿、不公開與已刪除的列')]
    public function test_guest_scope_counts_exclude_hidden_rows()
    {
        $scopes = $this->guestCall('GET', '/api/scopes')->assertOk()->json('data');
        $scope = collect($scopes)->firstWhere('id', $this->ids['scope']['live']);

        // 每種實體各有一列看得到的，其餘是草稿、不公開或已軟刪除
        $this->assertSame(1, $scope['documentations_count']);
        $this->assertSame(1, $scope['techniques_count']);
        $this->assertSame(1, $scope['implementations_count']);
    }

    /**
     * 不合法的路由 id：不是數字、負數、小數、剛好超出 bigint 上限（9223372036854775807）一位，
     * 以及 0、帶前導零、帶正號（寫法不是正整數的標準形式）。
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedRouteIds(): iterable
    {
        yield '字母' => ['abc'];
        yield '負數' => ['-1'];
        yield '小數' => ['1.5'];
        yield '超出 bigint 範圍' => ['9223372036854775808'];
        yield '零' => ['0'];
        yield '前導零' => ['007'];
        yield '正號' => ['+1'];
    }

    #[TestDox('每條帶 id 的路由，綁定的 model 都套用了路由 id 檢查')]
    public function test_every_model_bound_route_parameter_uses_the_route_key_guard()
    {
        $checked = [];
        $failures = [];

        foreach ($this->apiRoutes() as $key => $route) {
            foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
                $model = $parameter->getType()->getName();
                $checked[$key][] = $model;

                if (! in_array(ResolvesPositiveIntegerRouteKeys::class, class_uses_recursive($model), true)) {
                    $failures[] = "{$key} 綁定的 {$model}";
                }
            }
        }

        $this->assertSame([], $failures, '這些路由綁定的 model 沒有套用 '.ResolvesPositiveIntegerRouteKeys::class.'：');
        // 沒抓到任何帶 id 的路由，代表上面的迴圈空轉
        $this->assertArrayHasKey('GET api/relations/{relation}/edges', $checked);
        $this->assertArrayHasKey('DELETE api/scopes/{scope}', $checked);
    }

    #[TestDox('bigint 上限本身是合法的 id，照常查詢')]
    public function test_the_largest_bigint_is_still_a_valid_route_id()
    {
        $scope = Scope::factory()->create(['id' => PHP_INT_MAX]);

        $this->getJson('/api/scopes/'.PHP_INT_MAX)
            ->assertOk()
            ->assertJsonPath('id', $scope->id);
        $this->getJson('/api/scopes/'.$this->ids['scope']['live'])->assertOk();
    }

    #[DataProvider('malformedRouteIds')]
    #[TestDox('訪客在網址放不合法的 id：公開路由回 404，需登入的路由回 401')]
    public function test_guest_gets_404_or_401_for_a_malformed_route_id(string $badId)
    {
        $this->assertSame([], $this->malformedIdFailures($badId, signedIn: false));
    }

    #[DataProvider('malformedRouteIds')]
    #[TestDox('登入後在網址放不合法的 id：每條帶 id 的路由都回 404')]
    public function test_signed_in_owner_gets_404_for_a_malformed_route_id(string $badId)
    {
        $this->actingAsOwner();

        $this->assertSame([], $this->malformedIdFailures($badId, signedIn: true));
    }

    /**
     * 把每條 `api/` 路由的 `{參數}` 換成不合法的 id 打一遍，回傳不符預期的項目。
     *
     * 預期：登入後一律 404（驗證與授權都在綁定之後，所以空的請求本文也沒關係）；
     * 訪客打需要登入的路由先回 401，其餘回 404。不論哪種，那個值都不能出現在任何查詢的參數裡。
     *
     * @return list<string>
     */
    private function malformedIdFailures(string $badId, bool $signedIn): array
    {
        $failures = [];
        $exercised = 0;
        DB::enableQueryLog();

        foreach ($this->apiRoutes() as $key => $route) {
            if ($route->parameterNames() === []) {
                continue;
            }

            [$method, $uri] = explode(' ', $key, 2);
            $url = '/'.preg_replace('/\{\w+\}/', $badId, $uri);
            $expected = ! $signedIn && self::expectations()[$key]['kind'] === self::KIND_AUTH ? 401 : 404;

            DB::flushQueryLog();
            $status = $this->callFromFreshIp($method, $url)->getStatusCode();
            $exercised++;

            if ($status !== $expected) {
                $failures[] = "{$method} {$url} 應回 {$expected}，實際 {$status}";
            }
            foreach (DB::getQueryLog() as $query) {
                foreach ($query['bindings'] as $binding) {
                    if (is_scalar($binding) && (string) $binding === $badId) {
                        $failures[] = "{$method} {$url} 把 {$badId} 送進了查詢：{$query['query']}";
                    }
                }
            }
        }

        DB::disableQueryLog();
        $this->assertGreaterThan(0, $exercised, '帶 id 的路由數量比預期少，掃描可能空轉');

        return $failures;
    }

    /**
     * 準備夾具：每種實體至少一列看得到、一列看不到（草稿／不公開）、一列已軟刪除，
     * 每一列所有文字欄位都放同一個標記字串。看不到的列都連到看得到的列，
     * 這樣圖譜、述詞的邊、技術與實作的詳情都有機會把它們帶出來。
     */
    private function seedFixture(): void
    {
        $parentScope = Scope::factory()->create([
            'class_number' => 80, 'call_number' => '00', 'parent_class' => null,
            'name' => 'sweep-live-scope-parent', 'comment' => 'sweep-live-scope-parent',
        ]);
        $scope = Scope::factory()->create([
            'class_number' => 80, 'call_number' => '01', 'parent_class' => $parentScope->id,
            'name' => self::LIVE_MARKERS['scope'], 'comment' => self::LIVE_MARKERS['scope'], 'note' => self::LIVE_MARKERS['scope'],
        ]);
        // 已刪除的分類與看得到的分類同一個父層，從父層的 children、兄弟分類都有機會帶出來
        $deletedScope = Scope::factory()->create([
            'class_number' => 80, 'call_number' => '02', 'parent_class' => $parentScope->id,
            'name' => self::HIDDEN_MARKERS['deleted scope'], 'comment' => self::HIDDEN_MARKERS['deleted scope'], 'note' => self::HIDDEN_MARKERS['deleted scope'],
        ]);

        // 已刪除的頂層分類，底下還有一筆看得到的子分類（刪除分類不會連帶刪子分類）：
        // 表單選項（只列頂層）和子分類的 parent 都有機會把它帶出來
        $deletedTopLevelScope = Scope::factory()->create([
            'class_number' => 81, 'call_number' => '00', 'parent_class' => null,
            'name' => self::HIDDEN_MARKERS['deleted top-level scope'], 'comment' => self::HIDDEN_MARKERS['deleted top-level scope'], 'note' => self::HIDDEN_MARKERS['deleted top-level scope'],
        ]);
        $orphanedScope = Scope::factory()->create([
            'class_number' => 81, 'call_number' => '01', 'parent_class' => $deletedTopLevelScope->id,
            'name' => self::LIVE_MARKERS['scope under a deleted parent'], 'comment' => self::LIVE_MARKERS['scope under a deleted parent'], 'note' => self::LIVE_MARKERS['scope under a deleted parent'],
        ]);

        $relation = $this->makeRelation($scope, self::LIVE_MARKERS['relation'], '90');
        // 已刪除述詞的 reverse_id 指向看得到的述詞；建立時 Relation 會把看得到的述詞也反過來指向它
        $deletedRelation = $this->makeRelation($scope, self::HIDDEN_MARKERS['deleted relation'], '91', $relation->id);

        $documentation = $this->makeDocumentation($scope, self::LIVE_MARKERS['documentation']);
        $draft = $this->makeDocumentation($scope, self::HIDDEN_MARKERS['draft documentation'], ['status' => 0]);
        $nullStatus = $this->makeDocumentation($scope, self::HIDDEN_MARKERS['null-status documentation'], ['status' => null]);
        $deletedDocumentation = $this->makeDocumentation($scope, self::HIDDEN_MARKERS['deleted documentation']);
        // 看得到的文件，但所屬分類已刪除
        $this->makeDocumentation($deletedScope, 'sweep-live-orphaned-documentation');

        $technique = $this->makeTechnique($scope, self::LIVE_MARKERS['technique']);
        $deletedTechnique = $this->makeTechnique($scope, self::HIDDEN_MARKERS['deleted technique']);

        $implementation = $this->makeImplementation($scope, self::LIVE_MARKERS['implementation']);
        $hiddenImplementation = $this->makeImplementation($scope, self::HIDDEN_MARKERS['hidden implementation'], ['is_visible' => false]);
        $deletedImplementation = $this->makeImplementation($scope, self::HIDDEN_MARKERS['deleted implementation']);

        // 看得到的三列之間只連一條（文件與技術），用來確認邊本身是看得到的；
        // 另一條用已刪除的述詞連，看述詞名稱會不會從邊帶出來。
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $deletedRelation->id]);

        // 其餘的連線都連到看不到的列。
        foreach ([$draft, $nullStatus, $deletedDocumentation] as $hidden) {
            $hidden->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
            $hidden->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);
        }
        $deletedTechnique->documentations()->attach($documentation->id, ['relation_id' => $relation->id]);
        $deletedTechnique->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);
        foreach ([$hiddenImplementation, $deletedImplementation] as $hidden) {
            $hidden->documentations()->attach($documentation->id, ['relation_id' => $relation->id]);
            $hidden->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        }

        // 同一型別之間的邊（entity_relations）也各連一條到看不到的列
        foreach ([
            ['technique', $technique, $deletedTechnique],
            ['documentation', $documentation, $draft],
            ['implementation', $implementation, $hiddenImplementation],
        ] as [$type, $subject, $object]) {
            EntityRelation::create([
                'entity_type' => $type,
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'relation_id' => $relation->id,
            ]);
        }

        // 連線都建好之後才軟刪除（建立連線時會檢查兩端存在）
        $deletedDocumentation->delete();
        $deletedTechnique->delete();
        $deletedImplementation->delete();
        $deletedScope->delete();
        $deletedTopLevelScope->delete();
        $deletedRelation->delete();

        $this->ids = [
            'documentation' => [
                'live' => $documentation->id,
                'draft' => $draft->id,
                'null status' => $nullStatus->id,
                'deleted' => $deletedDocumentation->id,
            ],
            'technique' => ['live' => $technique->id, 'deleted' => $deletedTechnique->id],
            'implementation' => [
                'live' => $implementation->id,
                'hidden' => $hiddenImplementation->id,
                'deleted' => $deletedImplementation->id,
            ],
            'scope' => [
                'live' => $scope->id,
                'live parent' => $parentScope->id,
                'live under a deleted parent' => $orphanedScope->id,
                'deleted' => $deletedScope->id,
                'deleted top-level' => $deletedTopLevelScope->id,
            ],
            'relation' => ['live' => $relation->id, 'deleted' => $deletedRelation->id],
        ];
    }

    private function makeRelation(Scope $scope, string $marker, string $classNumber, ?int $reverseId = null): Relation
    {
        return Relation::factory()->create([
            'subject_id' => $scope->id,
            'object_id' => $scope->id,
            'class_number' => $classNumber,
            'call_number' => '00',
            'name' => $marker,
            'note' => $marker,
            'reverse_id' => $reverseId,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeDocumentation(Scope $scope, string $marker, array $overrides = []): Documentation
    {
        return Documentation::factory()->create([
            'type' => $scope->id,
            'title' => $marker,
            'url' => "https://example.test/{$marker}",
            'uri' => $marker,
            'body' => $marker,
            'note' => $marker,
            ...$overrides,
        ]);
    }

    private function makeTechnique(Scope $scope, string $marker): Technique
    {
        return Technique::factory()->create([
            'type' => $scope->id,
            'title' => $marker,
            'note' => $marker,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeImplementation(Scope $scope, string $marker, array $overrides = []): Implementation
    {
        return Implementation::factory()->create([
            'type' => $scope->id,
            'title' => $marker,
            'sub_title' => $marker,
            'description' => $marker,
            'url' => "https://example.test/{$marker}",
            'is_visible' => true,
            ...$overrides,
        ]);
    }

    /**
     * `api/` 底下的路由，key 是「方法 網址」。HEAD 是 GET 自動帶的，不另外算；
     * `PUT|PATCH` 的路由拆成兩筆，各自要有 401。
     *
     * @return array<string, RoutingRoute>
     */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== 'api' && ! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes["{$method} {$route->uri()}"] = $route;
                }
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * 對照表歸為某一類、而且真的存在的路由。對照表與路由表對不上的情況由
     * `test_every_api_route_is_classified_and_no_entry_is_stale` 負責報告。
     *
     * @return array<string, RoutingRoute>
     */
    private function routesOfKind(string $kind): array
    {
        return array_filter(
            $this->apiRoutes(),
            fn (RoutingRoute $route, string $key) => (self::expectations()[$key]['kind'] ?? null) === $kind,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * 對照表要求掛著、但路由實際沒有的 middleware。
     *
     * 對照表跟路由收集到的都是 `throttle:login`、`frontend.origin` 這種別名（還有 `api` 群組），
     * 兩邊都先用 router 展開成類別名稱再比。
     *
     * @return list<string>
     */
    private function missingMiddleware(): array
    {
        $missing = [];
        foreach ($this->apiRoutes() as $key => $route) {
            $required = self::expectations()[$key]['middleware'] ?? [];
            $present = app('router')->resolveMiddleware($route->gatherMiddleware());

            foreach (app('router')->resolveMiddleware($required) as $middleware) {
                if (! in_array($middleware, $present, true)) {
                    $missing[] = "{$key} 沒有 {$middleware}";
                }
            }
        }

        return $missing;
    }

    /**
     * 圖譜的邊允許出現已軟刪除述詞的名稱，掃描前先把這兩個欄位的這個值拿掉。
     *
     * 連結 model 的 `relation()` 刻意用 `withTrashed()`：述詞刪除後，既有的邊仍要讀得出
     * 當初是哪個述詞，而述詞是公開的詞彙，不是私人資料。所以 `/api/graph` 與
     * `/api/graph/path` 的 `edges[].predicate`、`edges[].label` 可以是已刪除述詞的名稱。
     * 只放行這兩個欄位的這一個值：已刪除述詞的名稱出現在其他欄位或其他端點
     * （例如 `/api/relations`），或這兩個欄位出現別的看不到的標記，仍然失敗。
     */
    private function withoutAllowedPredicateNames(string $uri, string $body): string
    {
        if (! in_array($uri, ['api/graph', 'api/graph/path'], true)) {
            return $body;
        }

        $data = json_decode($body, true);
        if (! is_array($data) || ! isset($data['edges']) || ! is_array($data['edges'])) {
            return $body;
        }

        $allowed = self::HIDDEN_MARKERS['deleted relation'];
        foreach ($data['edges'] as $index => $edge) {
            foreach (['predicate', 'label'] as $field) {
                if (($edge[$field] ?? null) === $allowed) {
                    $data['edges'][$index][$field] = null;
                }
            }
        }

        return json_encode($data);
    }

    /** 把網址裡的 `{參數}` 換成夾具裡看得到的那一列的 id。 */
    private function urlFor(string $uri): string
    {
        return '/'.preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $match) => (string) $this->ids[$match[1]]['live'],
            $uri,
        );
    }

    /**
     * 掃描一條公開路由要打的請求：看得到的那一列，加上該型別所有看不到的列。
     * 網址有夾具不認得的參數時直接失敗，不默默略過。
     *
     * @return list<array{label: string, url: string, live: bool}>
     */
    private function callsFor(string $key): array
    {
        $uri = explode(' ', $key, 2)[1];

        if ($uri === 'api/graph/path') {
            return $this->pathCalls();
        }

        if (! preg_match_all('/\{(\w+)\}/', $uri, $matches)) {
            $calls = [['label' => '無參數', 'url' => '/'.$uri, 'live' => true]];
        } else {
            $this->assertCount(1, $matches[1], "{$uri} 有多個路由參數，請擴充 GuestApiSweepTest::callsFor() 讓掃描涵蓋它。");
            $parameter = $matches[1][0];
            $this->assertArrayHasKey($parameter, $this->ids, "{$uri} 的路由參數 {{$parameter}} 沒有對應的夾具，請在 seedFixture() 補上。");

            $calls = [];
            foreach ($this->ids[$parameter] as $variant => $id) {
                $calls[] = [
                    'label' => $variant,
                    'url' => '/'.str_replace('{'.$parameter.'}', (string) $id, $uri),
                    'live' => str_starts_with($variant, 'live'),
                ];
            }
        }

        return [...$calls, ...$this->queryCalls($calls, self::expectations()[$key]['queries'] ?? [])];
    }

    /**
     * 帶查詢參數的請求：每個基本請求各帶每條 query string 打一次，
     * query string 裡的 `{scope}` 這類參數再換成夾具裡每一種列的 id。
     *
     * @param  list<array{label: string, url: string, live: bool}>  $calls
     * @param  list<string>  $queries
     * @return list<array{label: string, url: string, live: bool}>
     */
    private function queryCalls(array $calls, array $queries): array
    {
        $withQueries = [];
        foreach ($queries as $query) {
            $variants = [$query => 'query'];
            if (preg_match('/\{(\w+)\}/', $query, $match)) {
                $this->assertArrayHasKey($match[1], $this->ids, "查詢參數 {$query} 的 {{$match[1]}} 沒有對應的夾具，請在 seedFixture() 補上。");
                $variants = [];
                foreach ($this->ids[$match[1]] as $variant => $id) {
                    $variants[str_replace($match[0], (string) $id, $query)] = $variant;
                }
            }

            foreach ($calls as $call) {
                foreach ($variants as $resolved => $variant) {
                    $withQueries[] = [
                        'label' => "{$call['label']}，?{$resolved}（{$variant}）",
                        'url' => $call['url'].'?'.$resolved,
                        'live' => $call['live'],
                    ];
                }
            }
        }

        return $withQueries;
    }

    /**
     * 最短路徑：夾具裡每種節點兩兩配對，起點或終點是看不到的列的情況都涵蓋。
     *
     * @return list<array{label: string, url: string, live: bool}>
     */
    private function pathCalls(): array
    {
        $nodes = [];
        foreach (['documentation', 'technique', 'implementation'] as $type) {
            foreach ($this->ids[$type] as $variant => $id) {
                $nodes["{$type} {$variant}"] = ["{$type}-{$id}", $variant === 'live'];
            }
        }

        $calls = [];
        $labels = array_keys($nodes);
        foreach ($labels as $i => $startLabel) {
            foreach (array_slice($labels, $i + 1) as $endLabel) {
                [$start, $startLive] = $nodes[$startLabel];
                [$end, $endLive] = $nodes[$endLabel];

                $calls[] = [
                    'label' => "{$startLabel} 到 {$endLabel}",
                    'url' => "/api/graph/path?start={$start}&end={$end}",
                    'live' => $startLive && $endLive,
                ];
            }
        }

        return $calls;
    }

    /**
     * 以訪客身分打一個請求，不帶 `Accept: application/json`。
     *
     * 刻意不帶：沒登入的 DELETE 沒有 Accept 時，Laravel 預設會導去不存在的 `login` 路由而 500
     * （`bootstrap/app.php` 的 `redirectGuestsTo(null)` 守的就是這個），要確認各路由都是 401。
     *
     * 每個請求用不同的來源 IP（`X-Forwarded-For` 最右邊那個，見 `AppServiceProvider::clientIp()`），
     * 掃描的請求數超過每分鐘 60 次的限制時才不會被 429 擋下；limiter 本身照常掛著，
     * 沒有用 `withoutMiddleware` 關掉。
     *
     * @param  array<string, string>  $server
     */
    private function guestCall(string $method, string $url, array $server = []): TestResponse
    {
        return $this->callFromFreshIp($method, $url, $server);
    }

    /**
     * 每個請求用不同的來源 IP 打（理由見 `guestCall()`）。登入與否由呼叫前有沒有 `actingAs()` 決定。
     *
     * @param  array<string, string>  $server
     */
    private function callFromFreshIp(string $method, string $url, array $server = []): TestResponse
    {
        $this->requestCount++;
        $server['HTTP_X_FORWARDED_FOR'] = long2ip(ip2long('198.18.0.0') + $this->requestCount);

        return $this->call($method, $url, [], [], [], $server);
    }
}
