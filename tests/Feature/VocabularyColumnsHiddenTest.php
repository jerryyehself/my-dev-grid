<?php

namespace Tests\Feature;

use App\Enums\ResLabelType;
use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\ResLabel;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 詞表的欄位只出現在刻意公開的地方：
 *
 * - 技術的停用狀態（`deprecated`、`deprecated_at`、`replaced_by_id`）是 `TechniqueResource` 的公開欄位，
 *   技術出現的地方都帶著。
 * - 別名表（`res_labels`）不出現在任何 JSON API 的回應裡。
 *
 * 技術會從好幾條路由帶出來（技術本身、文件與實作的詳情、圖譜、最短路徑、述詞的邊），所以打遍 `api/` 底下
 * 每一條 GET 路由。路由表多了一條 GET 路由、這裡沒有對應的網址，測試就失敗，要回來補。
 *
 * 別名用獨一無二的字串當標記；技術的物件（技術清單、圖譜節點）另外比對完整的 key。
 */
class VocabularyColumnsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const DEPRECATED_AT = '2001-02-03 04:05:06';

    private const ALIAS = 'vocabmark-alias';

    private const TITLE = 'vocabmark-technique-title';

    /** 回應裡一定要出現夾具技術的路由：證明技術真的有被序列化，上面的檢查不是空轉。 */
    private const ROUTES_THAT_SERIALIZE_THE_TECHNIQUE = [
        'api/techniques',
        'api/techniques/{technique}',
        'api/documentations',
        'api/documentations/{documentation}',
        'api/implementations',
        'api/implementations/{implementation}',
        'api/relations/{relation}/edges',
        'api/graph',
        'api/graph/path',
    ];

    /** 沒登入回 401 的 GET 路由。 */
    private const SIGNED_IN_ONLY = ['api/user', 'api/article-sources'];

    /** `TechniqueResource` 給的 key（沒有 pivot、沒有載入關聯時）。 */
    private const TECHNIQUE_KEYS = ['id', 'type', 'title', 'version', 'note', 'deprecated', 'deprecated_at', 'replaced_by_id', 'created_at', 'updated_at', 'scope'];

    /** `/api/graph` 節點的 key，跟 `GraphController::index()` 的 `#[Response]` 一致。 */
    private const GRAPH_NODE_KEYS = ['id', 'type', 'label', 'created_at', 'added_at', 'updated_at', 'subtype', 'url', 'active', 'deprecated', 'replaced_by'];

    private Documentation $documentation;

    private Technique $technique;

    private Technique $concept;

    private Implementation $implementation;

    private Relation $relation;

    private Scope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->scope = Scope::where('name', 'packagetool')->firstOrFail();
        $this->relation = Relation::where('name', 'about')->firstOrFail();
        $this->documentation = Documentation::factory()->create([
            'type' => Scope::where('name', 'post')->value('id'),
            'status' => Documentation::STATUS_PUBLISHED,
        ]);
        $replacement = Technique::factory()->create(['type' => $this->scope->id, 'title' => 'Heroku']);
        // 已停用、指向改用對象的技術，又有連結：舊程式會照常把它帶出來
        $this->technique = Technique::factory()->create(['type' => $this->scope->id, 'title' => self::TITLE]);
        DB::table('techniques')->where('id', $this->technique->id)->update(['deprecated_at' => self::DEPRECATED_AT, 'replaced_by_id' => $replacement->id]);
        $this->concept = Technique::factory()->create(['type' => Scope::where('name', 'packagetool')->value('id'), 'title' => '靜態託管']);
        $this->implementation = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id'), 'is_visible' => true]);
        $this->documentation->techniques()->attach($this->technique->id, ['relation_id' => $this->relation->id]);
        $this->implementation->techniques()->attach($this->technique->id, ['relation_id' => Relation::where('name', 'uses')->value('id')]);
        EntityRelation::create([
            'entity_type' => 'technique',
            'subject_id' => $this->technique->id,
            'object_id' => $this->concept->id,
            'relation_id' => Relation::where('name', 'broader')->value('id'),
        ]);
        ResLabel::create(['res_type' => 'technique', 'res_id' => $this->technique->id, 'label' => self::ALIAS, 'label_type' => ResLabelType::Alt, 'locale' => 'en', 'note' => 'vocabmark-note']);
        ResLabel::create(['res_type' => 'implementation', 'res_id' => $this->implementation->id, 'label' => self::ALIAS, 'label_type' => ResLabelType::Hidden]);

        // 夾具真的寫進去了，不然下面「回應裡沒有這些值」是空轉
        $this->assertSame(self::DEPRECATED_AT, DB::table('techniques')->where('id', $this->technique->id)->value('deprecated_at'));
        $this->assertSame(2, ResLabel::count());
    }

    #[TestDox('每條 api 的 GET 路由在這裡都有對應的網址，沒有多餘的項目')]
    public function test_every_api_get_route_has_a_url_here(): void
    {
        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && in_array('GET', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();
        $declared = array_keys($this->getUrls());

        $this->assertSame([], array_values(array_diff($actual, $declared)), '這些 GET 路由還沒列進 getUrls()');
        $this->assertSame([], array_values(array_diff($declared, $actual)), 'getUrls() 裡這些項目已經沒有對應的路由');
    }

    #[TestDox('訪客讀每條公開的 GET 路由，回應都不含別名')]
    public function test_guest_responses_never_contain_vocabulary_columns(): void
    {
        $serialized = [];

        foreach ($this->getUrls() as $uri => $url) {
            if (in_array($uri, self::SIGNED_IN_ONLY, true)) {
                $this->getJson($url)->assertUnauthorized();

                continue;
            }

            $response = $this->getJson($url)->assertOk();
            $this->assertNoVocabularyColumns($response, "訪客 GET {$url}");
            if (str_contains($response->getContent(), self::TITLE)) {
                $serialized[] = $uri;
            }
        }

        foreach (self::ROUTES_THAT_SERIALIZE_THE_TECHNIQUE as $uri) {
            $this->assertContains($uri, $serialized, "{$uri} 的回應裡沒有夾具技術，檢查可能空轉");
        }
    }

    #[TestDox('登入後讀每條 GET 路由，回應也不含別名')]
    public function test_signed_in_responses_never_contain_vocabulary_columns(): void
    {
        $this->actingAsOwner();

        foreach ($this->getUrls() as $uri => $url) {
            // 文章來源清單讀的是 GitHub 上的檔案，不讀技術，測試環境沒設定 GitHub 時回 503
            if ($uri === 'api/article-sources') {
                continue;
            }
            $this->assertNoVocabularyColumns($this->getJson($url)->assertOk(), "登入 GET {$url}");
        }
    }

    #[TestDox('技術清單每一筆的 key 是固定的一組，停用的技術帶停用時間與改用對象')]
    public function test_technique_list_items_keep_their_keys(): void
    {
        $items = collect($this->getJson('/api/techniques')->assertOk()->json('data'));

        $this->assertCount(4, $items); // 3 筆＋TechniqueSeeder 的 Claude Code
        foreach ($items as $item) {
            $this->assertEqualsCanonicalizing(self::TECHNIQUE_KEYS, array_keys($item), "{$item['title']} 的 key 變了");
        }
        $deprecated = $items->firstWhere('id', $this->technique->id);
        $this->assertTrue($deprecated['deprecated']);
        $this->assertSame(self::DEPRECATED_AT, $deprecated['deprecated_at']);
        $this->assertSame(Technique::where('title', 'Heroku')->value('id'), $deprecated['replaced_by_id']);
        $this->assertFalse($items->firstWhere('id', $this->concept->id)['deprecated']);
    }

    #[TestDox('圖譜節點的 key 是固定的一組，概念詞與已停用的技術照常是 technique 節點')]
    public function test_graph_nodes_keep_their_keys(): void
    {
        $graph = $this->getJson('/api/graph')->assertOk();
        $nodes = collect($graph->json('nodes'))->keyBy('id');

        foreach ($nodes as $node) {
            $this->assertSame(self::GRAPH_NODE_KEYS, array_keys($node), "{$node['id']} 的 key 變了");
        }
        foreach ([$this->technique, $this->concept] as $technique) {
            $node = $nodes->get('technique-'.$technique->id);
            $this->assertSame('technique', $node['type']);
            $this->assertSame($technique->title, $node['label']);
            $this->assertSame(Scope::find($technique->type)->name, $node['subtype']);
            $this->assertTrue($node['active']);
        }
        // broader 的邊照現有的做法輸出（entity_relations），述詞名稱就是 broader
        $this->assertTrue(collect($graph->json('edges'))->contains(fn (array $edge) => $edge['source'] === 'technique-'.$this->technique->id
            && $edge['target'] === 'technique-'.$this->concept->id
            && $edge['predicate'] === 'broader'));
    }

    /**
     * `api/` 底下每條 GET 路由（key 是路由的 uri）要打的網址。
     *
     * @return array<string, string>
     */
    private function getUrls(): array
    {
        $techniqueNode = 'technique-'.$this->technique->id;
        $implementationNode = 'implementation-'.$this->implementation->id;

        return [
            'api/user' => '/api/user',
            'api/article-sources' => '/api/article-sources',
            'api/scopes' => '/api/scopes',
            'api/scopes/create' => '/api/scopes/create',
            'api/scopes/{scope}' => "/api/scopes/{$this->scope->id}",
            'api/relations' => '/api/relations',
            'api/relations/create' => '/api/relations/create',
            'api/relations/{relation}' => "/api/relations/{$this->relation->id}",
            'api/relations/{relation}/edges' => "/api/relations/{$this->relation->id}/edges?per_page=100",
            'api/documentations' => '/api/documentations',
            'api/documentations/{documentation}' => "/api/documentations/{$this->documentation->id}",
            'api/techniques' => '/api/techniques',
            'api/techniques/{technique}' => "/api/techniques/{$this->technique->id}",
            'api/implementations' => '/api/implementations',
            'api/implementations/{implementation}' => "/api/implementations/{$this->implementation->id}",
            'api/graph' => '/api/graph',
            'api/graph/path' => "/api/graph/path?start={$techniqueNode}&end={$implementationNode}",
        ];
    }

    private function assertNoVocabularyColumns(TestResponse $response, string $where): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString(self::ALIAS, $body, "{$where} 帶出了別名");
        $this->assertStringNotContainsString('vocabmark-note', $body, "{$where} 帶出了別名的說明");

        $leakedKeys = array_values(array_intersect(['normalized', 'label_type', 'res_labels', 'labels'], $this->allKeys($response->json() ?? [])));
        $this->assertSame([], $leakedKeys, "{$where} 帶出了別名表的欄位");
    }

    /**
     * JSON 裡每一層的 key。
     *
     * @param  array<mixed>  $json
     * @return list<string>
     */
    private function allKeys(array $json): array
    {
        $keys = [];
        foreach ($json as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                array_push($keys, ...$this->allKeys($value));
            }
        }

        return $keys;
    }
}
