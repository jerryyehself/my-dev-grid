<?php

namespace Tests\Feature;

use App\Http\Controllers\GraphController;
use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class GraphApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('技術版本節點的標籤會帶上版本號')]
    public function test_technique_version_records_are_labelled_with_their_version()
    {
        // 版本是獨立的一筆，title 相同（2026-09-30），標籤不帶版本的話圖上會有兩個一樣的「Vue」
        $base = Technique::factory()->create(['title' => 'Vue']);
        $v3 = Technique::factory()->create(['title' => 'Vue', 'version' => '3']);

        $response = $this->getJson('/api/graph');

        $response->assertJsonFragment(['id' => "technique-{$base->id}", 'type' => 'technique', 'label' => 'Vue']);
        $response->assertJsonFragment(['id' => "technique-{$v3->id}", 'type' => 'technique', 'label' => 'Vue 3']);
    }

    #[TestDox('沒有邊的實體也會列為圖的節點')]
    public function test_graph_lists_every_entity_as_a_node_even_without_edges()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();

        $response = $this->getJson('/api/graph');

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => "documentation-{$documentation->id}",
            'type' => 'documentation',
            'label' => $documentation->title,
        ]);
        $response->assertJsonFragment([
            'id' => "technique-{$technique->id}",
            'type' => 'technique',
            'label' => $technique->title,
        ]);
        $response->assertJsonFragment([
            'id' => "implementation-{$implementation->id}",
            'type' => 'implementation',
            'label' => $implementation->title,
        ]);
    }

    #[TestDox('只有文件節點帶子類型與網址')]
    public function test_documentation_nodes_carry_subtype_and_url_other_nodes_do_not()
    {
        $documentation = Documentation::factory()->create(['url' => 'https://www.php.net/docs.php']);
        $technique = Technique::factory()->create();

        $nodes = collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->keyBy('id');

        $documentationNode = $nodes["documentation-{$documentation->id}"];
        $this->assertSame($documentation->scope?->name, $documentationNode['subtype']);
        $this->assertSame('https://www.php.net/docs.php', $documentationNode['url']);

        $techniqueNode = $nodes["technique-{$technique->id}"];
        $this->assertNull($techniqueNode['subtype']);
        $this->assertNull($techniqueNode['url']);
    }

    #[TestDox('圖會解析文件連技術的邊與述詞')]
    public function test_graph_resolves_documentation_technique_pivot_edge_with_predicate()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'documents']);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $response = $this->getJson('/api/graph');

        $response->assertOk();
        $response->assertJsonFragment([
            'source' => "documentation-{$documentation->id}",
            'target' => "technique-{$technique->id}",
            'predicate' => 'documents',
            'label' => 'documents',
            'relation_id' => $relation->id,
        ]);
    }

    #[TestDox('圖會解析文件連實作的邊')]
    public function test_graph_resolves_documentation_implementation_pivot_edge()
    {
        $documentation = Documentation::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create(['name' => 'documents']);
        $documentation->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);

        $response = $this->getJson('/api/graph');

        $response->assertOk()->assertJsonFragment([
            'source' => "documentation-{$documentation->id}",
            'target' => "implementation-{$implementation->id}",
            'predicate' => 'documents',
        ]);
    }

    #[TestDox('圖會解析技術連實作的邊')]
    public function test_graph_resolves_technique_implementation_pivot_edge()
    {
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create(['name' => 'uses']);
        $technique->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);

        $response = $this->getJson('/api/graph');

        $response->assertOk()->assertJsonFragment([
            'source' => "technique-{$technique->id}",
            'target' => "implementation-{$implementation->id}",
            'predicate' => 'uses',
        ]);
    }

    #[TestDox('圖會解析同類型實體間的關係邊')]
    public function test_graph_resolves_same_type_entity_relation_edge()
    {
        $subjectTechnique = Technique::factory()->create();
        $objectTechnique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'requires']);

        EntityRelation::factory()->create([
            'entity_type' => 'technique',
            'subject_id' => $subjectTechnique->id,
            'object_id' => $objectTechnique->id,
            'relation_id' => $relation->id,
        ]);

        $response = $this->getJson('/api/graph');

        $response->assertOk()->assertJsonFragment([
            'source' => "technique-{$subjectTechnique->id}",
            'target' => "technique-{$objectTechnique->id}",
            'predicate' => 'requires',
        ]);
    }

    #[TestDox('關係被軟刪除後，邊仍能解析出述詞')]
    public function test_graph_edge_still_resolves_predicate_after_relation_soft_delete()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'documents']);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $relation->delete();

        $response = $this->getJson('/api/graph');

        $response->assertOk()->assertJsonFragment([
            'source' => "documentation-{$documentation->id}",
            'target' => "technique-{$technique->id}",
            'predicate' => 'documents',
        ]);
    }

    /**
     * 已發布與草稿各一篇文件，各自連到技術、實作，彼此之間也有一條同型別的邊，另外沒刪的那篇也連到
     * 已刪除的草稿（已刪除的文件只在邊的 target 端），然後兩篇都軟刪除。
     * 軟刪除不會動到連結表，所以這些連結列都還在。
     *
     * @return array{0: Documentation, 1: Documentation, 2: Documentation}
     */
    private function softDeletedDocumentationsWithLinks(): array
    {
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        $published = Documentation::factory()->create(['title' => '已刪除的已發布文章', 'status' => 1]);
        $draft = Documentation::factory()->create(['title' => '已刪除的草稿', 'status' => 0]);
        // 留一篇沒刪的，確認濾掉的只有已刪除文件的邊
        $remaining = Documentation::factory()->create(['status' => 1]);

        foreach ([$published, $draft, $remaining] as $documentation) {
            $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
            $documentation->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);
        }
        EntityRelation::factory()->documentation()->create([
            'subject_id' => $published->id,
            'object_id' => $draft->id,
            'relation_id' => $relation->id,
        ]);
        // 已刪除的文件只在 target 端：只檢查邊的 source 端濾不掉這一條
        EntityRelation::factory()->documentation()->create([
            'subject_id' => $remaining->id,
            'object_id' => $draft->id,
            'relation_id' => $relation->id,
        ]);

        $published->delete();
        $draft->delete();

        return [$published, $draft, $remaining];
    }

    private function assertGraphOmitsDocumentations(TestResponse $response, Documentation ...$documentations): void
    {
        $nodeIds = collect($response->json('nodes'))->pluck('id');
        $edges = collect($response->json('edges'));
        $endpoints = $edges->pluck('source')->concat($edges->pluck('target'));

        foreach ($documentations as $documentation) {
            $nodeId = "documentation-{$documentation->id}";
            $this->assertNotContains($nodeId, $nodeIds);
            // source 端與 target 端各自檢查，已刪除的文件只出現在其中一端時也要濾掉
            $this->assertNotContains($nodeId, $edges->pluck('source'));
            $this->assertNotContains($nodeId, $edges->pluck('target'));
            $this->assertNotContains($nodeId, $endpoints);
            $response->assertJsonMissing(['label' => $documentation->title]);
        }

        // 每條邊的兩端都要是回傳的節點
        $this->assertEmpty($endpoints->diff($nodeIds));
    }

    #[TestDox('訪客的圖不含已刪除文件的節點與邊')]
    public function test_guest_graph_omits_soft_deleted_documentations_and_their_edges()
    {
        [$published, $draft, $remaining] = $this->softDeletedDocumentationsWithLinks();

        $response = $this->getJson('/api/graph')->assertOk();

        $this->assertGraphOmitsDocumentations($response, $published, $draft);
        $this->assertContains("documentation-{$remaining->id}", collect($response->json('edges'))->pluck('source'));
    }

    #[TestDox('登入後的圖也不含已刪除文件的節點與邊')]
    public function test_signed_in_graph_omits_soft_deleted_documentations_and_their_edges()
    {
        [$published, $draft, $remaining] = $this->softDeletedDocumentationsWithLinks();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/graph')->assertOk();

        $this->assertGraphOmitsDocumentations($response, $published, $draft);
        $this->assertContains("documentation-{$remaining->id}", collect($response->json('edges'))->pluck('source'));
    }

    #[TestDox('查路徑時不會經過已刪除的文件')]
    public function test_path_does_not_go_through_a_soft_deleted_documentation()
    {
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        $documentation = Documentation::factory()->create();
        // 技術 ↔ 文件 ↔ 專案：唯一的路徑經過這篇文件
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $documentation->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);
        $documentation->delete();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/graph/path?start=technique-{$technique->id}&end=implementation-{$implementation->id}")
            ->assertOk()
            ->assertExactJson(['found' => false, 'nodes' => [], 'edges' => []]);
    }

    #[TestDox('帶無效 token 時，圖的查詢次數不隨文件筆數增加')]
    public function test_graph_with_an_invalid_token_does_not_cost_extra_queries_per_documentation()
    {
        // 文件節點的可見性判斷依賴「登入態每個請求只判斷一次」：token 無效時 Sanctum 不會記住結果，
        // 每個節點各判斷一次的話，文件每多一篇就多查一次 token 表。草稿與已發布的都要有，
        // 沒有文件的話 scope 的 eager loading 也不會發查詢，基準值會不一樣
        Documentation::factory()->create(['status' => 0]);
        Documentation::factory()->create(['status' => 1]);

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withToken('1|not-a-real-token')->getJson('/api/graph')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $baseline = $countQueries();
        Documentation::factory()->count(20)->create(['status' => 0]);
        Documentation::factory()->count(20)->create(['status' => 1]);
        $afterGrowth = $countQueries();

        $this->assertSame($baseline, $afterGrowth, "文件多 40 篇後查詢數從 {$baseline} 次變成 {$afterGrowth} 次。");
    }

    /** @return array<string, array{0: bool}> */
    public static function viewers(): array
    {
        return ['訪客' => [false], '登入' => [true]];
    }

    #[TestDox('起訖點是同一篇已刪除的文件時，路徑找不到')]
    #[DataProvider('viewers')]
    public function test_path_from_a_soft_deleted_documentation_to_itself_is_not_found(bool $signedIn)
    {
        $documentation = Documentation::factory()->create(['title' => '已刪除的文章', 'status' => 1]);
        $documentation->delete();
        if ($signedIn) {
            Sanctum::actingAs(User::factory()->create());
        }
        $nodeId = "documentation-{$documentation->id}";

        $this->getJson("/api/graph/path?start={$nodeId}&end={$nodeId}")
            ->assertOk()
            ->assertExactJson(['found' => false, 'nodes' => [], 'edges' => []]);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function nodeTypesAndViewers(): array
    {
        $cases = [];
        foreach (['documentation', 'technique', 'implementation'] as $type) {
            $cases["{$type}／訪客"] = [$type, false];
            $cases["{$type}／登入"] = [$type, true];
        }

        return $cases;
    }

    #[TestDox('起訖點是同一個不存在的節點時，路徑找不到（三種節點都一樣）')]
    #[DataProvider('nodeTypesAndViewers')]
    public function test_path_from_a_nonexistent_node_to_itself_is_not_found(string $type, bool $signedIn)
    {
        if ($signedIn) {
            Sanctum::actingAs(User::factory()->create());
        }

        $this->getJson("/api/graph/path?start={$type}-999999&end={$type}-999999")
            ->assertOk()
            ->assertExactJson(['found' => false, 'nodes' => [], 'edges' => []]);
    }

    #[TestDox('起點或終點是已刪除的技術時，兩個方向都找不到路徑')]
    #[DataProvider('viewers')]
    public function test_path_with_a_soft_deleted_technique_at_either_end_is_not_found(bool $signedIn)
    {
        // 起訖點本身已刪除：由起訖點的存在檢查擋下（邊的那一側另有 rawEdges() 排除，見下面中間節點的測試）
        $technique = Technique::factory()->create();
        $documentation = Documentation::factory()->create(['status' => 1]);
        $relation = Relation::factory()->create(['name' => 'specs']);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $technique->delete();
        if ($signedIn) {
            Sanctum::actingAs(User::factory()->create());
        }
        $techniqueId = "technique-{$technique->id}";
        $documentationId = "documentation-{$documentation->id}";

        foreach ([[$techniqueId, $documentationId], [$documentationId, $techniqueId]] as [$start, $end]) {
            $this->getJson("/api/graph/path?start={$start}&end={$end}")
                ->assertOk()
                ->assertExactJson(['found' => false, 'nodes' => [], 'edges' => []]);
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function softDeletableEntityTypesAndViewers(): array
    {
        $cases = [];
        foreach (['technique', 'implementation'] as $type) {
            $cases["{$type}／訪客"] = [$type, false];
            $cases["{$type}／登入"] = [$type, true];
        }

        return $cases;
    }

    private function createEntity(string $type, array $attributes = []): Technique|Implementation
    {
        return match ($type) {
            'technique' => Technique::factory()->create($attributes),
            'implementation' => Implementation::factory()->create($attributes),
        };
    }

    /** 文件連到技術或實作的 pivot 邊（文件是 source、技術或實作是 target）。 */
    private function linkDocumentationTo(Documentation $documentation, Technique|Implementation $entity, Relation $relation): void
    {
        $links = $entity instanceof Technique ? $documentation->techniques() : $documentation->implementations();
        $links->attach($entity->id, ['relation_id' => $relation->id]);
    }

    /**
     * 唯一的一條兩步路徑，中間是技術或實作：
     * 技術為中間時是 文件 → 技術 → 實作，實作為中間時是 文件 → 實作 ← 技術（實作是兩條邊的 target）。
     * 中間節點用獨特的標題，方便檢查整個回應有沒有帶出它。
     *
     * @return array{0: string, 1: string, 2: Technique|Implementation} 起點 id、終點 id、中間的實體
     */
    private function twoHopPathThrough(string $type): array
    {
        $relation = Relation::factory()->create(['name' => 'specs']);
        $documentation = Documentation::factory()->create(['status' => 1]);
        $middle = $this->createEntity($type, ['title' => "中間的{$type}節點標記"]);

        $this->linkDocumentationTo($documentation, $middle, $relation);
        if ($middle instanceof Technique) {
            $end = Implementation::factory()->create();
            $middle->implementations()->attach($end->id, ['relation_id' => $relation->id]);
            $endId = "implementation-{$end->id}";
        } else {
            $end = Technique::factory()->create();
            $end->implementations()->attach($middle->id, ['relation_id' => $relation->id]);
            $endId = "technique-{$end->id}";
        }

        return ["documentation-{$documentation->id}", $endId, $middle];
    }

    /** 回傳的圖裡沒有這個節點，也沒有任何一條邊以它為 source 或 target，而且每條邊的兩端都是回傳的節點。 */
    private function assertGraphOmitsNode(TestResponse $response, string $nodeId, string $label): void
    {
        $nodeIds = collect($response->json('nodes'))->pluck('id');
        $edges = collect($response->json('edges'));

        $this->assertNotContains($nodeId, $nodeIds);
        // source 端與 target 端各自檢查，只出現在其中一端時也要濾掉
        $this->assertNotContains($nodeId, $edges->pluck('source'));
        $this->assertNotContains($nodeId, $edges->pluck('target'));
        $response->assertJsonMissing(['label' => $label]);
        $this->assertEmpty($edges->pluck('source')->concat($edges->pluck('target'))->diff($nodeIds));
    }

    #[TestDox('已刪除的技術或實作在路徑中間時，圖不含它，路徑也找不到')]
    #[DataProvider('softDeletableEntityTypesAndViewers')]
    public function test_soft_deleted_entity_in_the_middle_of_a_path_is_omitted_from_graph_and_path(string $type, bool $signedIn)
    {
        [$startId, $endId, $middle] = $this->twoHopPathThrough($type);
        if ($signedIn) {
            Sanctum::actingAs(User::factory()->create());
        }
        $middleId = "{$type}-{$middle->id}";

        // 對照組：沒刪之前路徑走得通，下面的「找不到」才是刪除造成的，不是資料沒接起來
        $this->getJson("/api/graph/path?start={$startId}&end={$endId}")
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('nodes.1.id', $middleId);

        $middle->delete();

        $graph = $this->getJson('/api/graph')->assertOk();
        $this->assertGraphOmitsNode($graph, $middleId, $middle->title);
        // 兩端沒刪，照常是節點
        $this->assertContains($startId, collect($graph->json('nodes'))->pluck('id'));
        $this->assertContains($endId, collect($graph->json('nodes'))->pluck('id'));

        foreach ([[$startId, $endId], [$endId, $startId]] as [$from, $to]) {
            $this->getJson("/api/graph/path?start={$from}&end={$to}")
                ->assertOk()
                ->assertExactJson(['found' => false, 'nodes' => [], 'edges' => []]);
        }
    }

    #[TestDox('已刪除的技術或實作只在邊的 target 端時，圖不含它的邊，路徑也不經過它')]
    #[DataProvider('softDeletableEntityTypesAndViewers')]
    public function test_soft_deleted_entity_that_is_only_an_edge_target_is_omitted(string $type, bool $signedIn)
    {
        // 已刪除的實體在每一條邊上都只是 target：兩篇文件的 pivot 邊，加上同型別的 entity_relations 邊。
        // 只濾掉 source 端的話，這些邊全都留得下來
        $relation = Relation::factory()->create(['name' => 'specs']);
        $target = $this->createEntity($type, ['title' => "只當 target 的{$type}標記"]);
        $other = $this->createEntity($type);
        $docA = Documentation::factory()->create(['status' => 1]);
        $docB = Documentation::factory()->create(['status' => 1]);
        $this->linkDocumentationTo($docA, $target, $relation);
        $this->linkDocumentationTo($docB, $target, $relation);
        EntityRelation::factory()->create([
            'entity_type' => $type,
            'subject_id' => $other->id,
            'object_id' => $target->id,
            'relation_id' => $relation->id,
        ]);
        if ($signedIn) {
            Sanctum::actingAs(User::factory()->create());
        }
        $targetId = "{$type}-{$target->id}";
        $docAId = "documentation-{$docA->id}";
        $docBId = "documentation-{$docB->id}";
        $otherId = "{$type}-{$other->id}";

        // 對照組：沒刪之前，三個活著的節點都靠這個 target 連起來
        $this->getJson("/api/graph/path?start={$docAId}&end={$docBId}")->assertOk()->assertJsonPath('found', true);
        $this->getJson("/api/graph/path?start={$otherId}&end={$docAId}")->assertOk()->assertJsonPath('found', true);

        $target->delete();

        $graph = $this->getJson('/api/graph')->assertOk();
        $this->assertGraphOmitsNode($graph, $targetId, $target->title);
        $this->assertContains($docAId, collect($graph->json('nodes'))->pluck('id'));
        $this->assertContains($otherId, collect($graph->json('nodes'))->pluck('id'));
        $this->assertSame([], $graph->json('edges'));

        foreach ([[$docAId, $docBId], [$otherId, $docAId]] as [$from, $to]) {
            $this->getJson("/api/graph/path?start={$from}&end={$to}")
                ->assertOk()
                ->assertExactJson(['found' => false, 'nodes' => [], 'edges' => []]);
        }
    }

    #[TestDox('沒有任何邊的現存節點查自己，路徑只有該節點')]
    #[DataProvider('viewers')]
    public function test_path_from_an_existing_node_without_edges_to_itself_is_found(bool $signedIn)
    {
        $documentation = Documentation::factory()->create(['title' => '沒有邊的文章', 'status' => 1]);
        if ($signedIn) {
            Sanctum::actingAs(User::factory()->create());
        }
        $nodeId = "documentation-{$documentation->id}";

        $this->getJson("/api/graph/path?start={$nodeId}&end={$nodeId}")
            ->assertOk()
            ->assertExactJson([
                'found' => true,
                'nodes' => [['id' => $nodeId, 'type' => 'documentation', 'label' => '沒有邊的文章']],
                'edges' => [],
            ]);
    }

    #[TestDox('技術與實作節點的 active 一律為 true')]
    public function test_technique_and_implementation_nodes_are_always_active()
    {
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();

        $nodes = collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->keyBy('id');

        $this->assertTrue($nodes["technique-{$technique->id}"]['active']);
        $this->assertTrue($nodes["implementation-{$implementation->id}"]['active']);
    }

    #[TestDox('圖的回應包含 nodes 與 edges')]
    public function test_graph_response_has_nodes_and_edges_keys()
    {
        $response = $this->getJson('/api/graph');

        $response->assertOk()->assertJsonStructure(['nodes', 'edges']);
    }

    #[TestDox('只有實作節點回報建立日期')]
    public function test_graph_only_reports_created_at_for_implementation_nodes()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create(['git_repo_created_at' => '2025-06-11 00:00:00']);

        $response = $this->getJson('/api/graph');

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => "documentation-{$documentation->id}",
            'created_at' => null,
        ]);
        $response->assertJsonFragment([
            'id' => "technique-{$technique->id}",
            'created_at' => null,
        ]);
        $response->assertJsonFragment([
            'id' => "implementation-{$implementation->id}",
            'created_at' => '2025-06-11',
        ]);
    }

    #[TestDox('查路徑缺少起點或終點時回 422')]
    public function test_path_requires_start_and_end_query_params()
    {
        $response = $this->getJson('/api/graph/path');

        $response->assertStatus(422);
    }

    #[TestDox('起點等於終點時路徑只有該節點')]
    public function test_path_is_trivially_found_when_start_equals_end()
    {
        $documentation = Documentation::factory()->create();

        $response = $this->getJson("/api/graph/path?start=documentation-{$documentation->id}&end=documentation-{$documentation->id}");

        $response->assertOk()->assertExactJson([
            'found' => true,
            'nodes' => [[
                'id' => "documentation-{$documentation->id}",
                'type' => 'documentation',
                'label' => $documentation->title,
            ]],
            'edges' => [],
        ]);
    }

    #[TestDox('可找出兩節點間的直接邊')]
    public function test_path_finds_direct_edge_between_two_nodes()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $response = $this->getJson("/api/graph/path?start=documentation-{$documentation->id}&end=technique-{$technique->id}");

        $response->assertOk();
        $response->assertJsonPath('found', true);
        $response->assertJsonPath('nodes.0.id', "documentation-{$documentation->id}");
        $response->assertJsonPath('nodes.1.id', "technique-{$technique->id}");
        $response->assertJsonFragment([
            'source' => "documentation-{$documentation->id}",
            'target' => "technique-{$technique->id}",
            'predicate' => 'specs',
            'storedDirection' => 'forward',
            'hasDefinedReverse' => true,
        ]);
    }

    #[TestDox('逆向走邊時回報反向關係名稱')]
    public function test_path_reports_reverse_relation_name_when_travelling_backward()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $reverse = Relation::factory()->create(['name' => 'specifiedBy']);
        $forward = Relation::factory()->create(['name' => 'specs', 'reverse_id' => $reverse->id]);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $forward->id]);

        // 反過來查（從 technique 走到 documentation），邊在資料庫裡存的方向是
        // documentation -> technique，這裡是逆向走那條邊。
        $response = $this->getJson("/api/graph/path?start=technique-{$technique->id}&end=documentation-{$documentation->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'source' => "technique-{$technique->id}",
            'target' => "documentation-{$documentation->id}",
            'predicate' => 'specifiedBy',
            'storedDirection' => 'reverse',
            'hasDefinedReverse' => true,
        ]);
    }

    #[TestDox('沒有定義反向時沿用原關係名稱')]
    public function test_path_falls_back_to_original_name_when_reverse_relation_undefined()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs', 'reverse_id' => null]);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $response = $this->getJson("/api/graph/path?start=technique-{$technique->id}&end=documentation-{$documentation->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'source' => "technique-{$technique->id}",
            'target' => "documentation-{$documentation->id}",
            'predicate' => 'specs',
            'storedDirection' => 'reverse',
            'hasDefinedReverse' => false,
        ]);
    }

    #[TestDox('可跨不同關係找出多跳最短路徑')]
    public function test_path_finds_multi_hop_shortest_path_across_different_relations()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();
        $specs = Relation::factory()->create(['name' => 'specs']);
        $uses = Relation::factory()->create(['name' => 'uses']);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $specs->id]);
        $technique->implementations()->attach($implementation->id, ['relation_id' => $uses->id]);

        $response = $this->getJson("/api/graph/path?start=documentation-{$documentation->id}&end=implementation-{$implementation->id}");

        $response->assertOk();
        $response->assertJsonPath('found', true);
        $response->assertJsonPath('nodes.0.id', "documentation-{$documentation->id}");
        $response->assertJsonPath('nodes.1.id', "technique-{$technique->id}");
        $response->assertJsonPath('nodes.2.id', "implementation-{$implementation->id}");
        $response->assertJsonCount(2, 'edges');
    }

    #[TestDox('節點不相連時回報找不到路徑')]
    public function test_path_reports_not_found_when_nodes_are_disconnected()
    {
        $documentationA = Documentation::factory()->create();
        $documentationB = Documentation::factory()->create();

        $response = $this->getJson("/api/graph/path?start=documentation-{$documentationA->id}&end=documentation-{$documentationB->id}");

        $response->assertOk()->assertExactJson([
            'found' => false,
            'nodes' => [],
            'edges' => [],
        ]);
    }

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function nodeTypes(): array
    {
        return [
            '文件' => ['documentation', Documentation::class],
            '技術' => ['technique', Technique::class],
            '實作' => ['implementation', Implementation::class],
        ];
    }

    /**
     * @param  class-string  $modelClass
     */
    #[TestDox('每一種節點都帶 added_at（建立時間）與 updated_at（最後更新時間）')]
    #[DataProvider('nodeTypes')]
    public function test_every_node_type_reports_added_at_and_updated_at(string $type, string $modelClass)
    {
        $this->travelTo('2026-11-01 08:00:00');
        $model = $modelClass::factory()->create();
        $this->travelTo('2026-11-02 09:30:00');
        $model->update(['title' => '改過的標題']);

        $nodes = collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->keyBy('id');

        $node = $nodes["{$type}-{$model->id}"];
        $this->assertSame('2026-11-01 08:00:00', $node['added_at']);
        $this->assertSame('2026-11-02 09:30:00', $node['updated_at']);
    }

    #[TestDox('實作節點的 created_at 仍是 GitHub 建立日期，不被 added_at 取代')]
    public function test_implementation_created_at_keeps_meaning_alongside_added_at()
    {
        $this->travelTo('2026-11-01 08:00:00');
        $implementation = Implementation::factory()->create(['git_repo_created_at' => '2025-06-11 00:00:00']);

        $nodes = collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->keyBy('id');

        $node = $nodes["implementation-{$implementation->id}"];
        $this->assertSame('2025-06-11', $node['created_at']);
        $this->assertSame('2026-11-01 08:00:00', $node['added_at']);
    }

    #[TestDox('沒登入看到的未公開文件節點，added_at 與 updated_at 為 null')]
    public function test_guest_does_not_see_unpublished_documentation_times()
    {
        $this->travelTo('2026-11-01 08:00:00');
        $draft = Documentation::factory()->create(['status' => 0]);

        $nodes = collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->keyBy('id');

        $node = $nodes["documentation-{$draft->id}"];
        $this->assertSame(GraphController::UNPUBLISHED_LABEL, $node['label']);
        $this->assertNull($node['added_at']);
        $this->assertNull($node['updated_at']);
    }

    #[TestDox('登入後看到的未公開文件節點，帶 added_at 與 updated_at')]
    public function test_logged_in_user_sees_unpublished_documentation_times()
    {
        $this->travelTo('2026-11-01 08:00:00');
        $draft = Documentation::factory()->create(['status' => 0]);
        Sanctum::actingAs(User::factory()->create());

        $nodes = collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->keyBy('id');

        $node = $nodes["documentation-{$draft->id}"];
        $this->assertSame('2026-11-01 08:00:00', $node['added_at']);
        $this->assertSame('2026-11-01 08:00:00', $node['updated_at']);
    }

    /**
     * 圖上所有種類的邊：三張 pivot，加上 entity_relations 的三種同型別關係。
     * 每一種回傳 [來源節點 id, 目標節點 id, 該邊所在的表, 找到那一列的條件]。
     *
     * @return array<string, array{0: string}>
     */
    public static function edgeKinds(): array
    {
        return [
            '文件→技術（pivot）' => ['documentation_technique'],
            '文件→實作（pivot）' => ['documentation_implementation'],
            '技術→實作（pivot）' => ['technique_implementation'],
            '技術→技術（entity_relations）' => ['entity_relations:technique'],
            '文件→文件（entity_relations）' => ['entity_relations:documentation'],
            '實作→實作（entity_relations）' => ['entity_relations:implementation'],
        ];
    }

    /**
     * 建一條指定種類的邊，回傳 [source, target, 表名, where 條件]。
     *
     * @return array{0: string, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function makeEdge(string $kind): array
    {
        $relation = Relation::factory()->create();

        if (str_starts_with($kind, 'entity_relations:')) {
            $type = substr($kind, strlen('entity_relations:'));
            $class = EntityRelation::ENTITY_TYPES[$type];
            [$subject, $object] = [$class::factory()->create(), $class::factory()->create()];
            $edge = EntityRelation::create([
                'entity_type' => $type,
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'relation_id' => $relation->id,
            ]);

            return ["{$type}-{$subject->id}", "{$type}-{$object->id}", 'entity_relations', ['id' => $edge->id]];
        }

        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();

        return match ($kind) {
            'documentation_technique' => $this->attachEdge(
                $documentation->techniques(), $technique->id, $relation->id,
                "documentation-{$documentation->id}", "technique-{$technique->id}",
                ['documentation_id' => $documentation->id, 'technique_id' => $technique->id],
            ),
            'documentation_implementation' => $this->attachEdge(
                $documentation->implementations(), $implementation->id, $relation->id,
                "documentation-{$documentation->id}", "implementation-{$implementation->id}",
                ['documentation_id' => $documentation->id, 'implementation_id' => $implementation->id],
            ),
            'technique_implementation' => $this->attachEdge(
                $technique->implementations(), $implementation->id, $relation->id,
                "technique-{$technique->id}", "implementation-{$implementation->id}",
                ['technique_id' => $technique->id, 'implementation_id' => $implementation->id],
            ),
        };
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function attachEdge($relationship, int $relatedId, int $relationId, string $source, string $target, array $where): array
    {
        $relationship->sync([$relatedId => ['relation_id' => $relationId]]);

        return [$source, $target, $relationship->getTable(), $where];
    }

    /** @return array<string, mixed>|null */
    private function findEdge(TestResponse $response, string $source, string $target): ?array
    {
        return collect($response->json('edges'))->first(fn (array $e) => $e['source'] === $source && $e['target'] === $target);
    }

    #[TestDox('每一種邊都帶 added_at（連結列的建立時間）')]
    #[DataProvider('edgeKinds')]
    public function test_every_edge_kind_reports_added_at(string $kind)
    {
        $this->travelTo('2026-11-01 08:00:00');
        [$source, $target] = $this->makeEdge($kind);

        $edge = $this->findEdge($this->getJson('/api/graph')->assertOk(), $source, $target);

        $this->assertNotNull($edge, '圖裡找不到這條邊');
        $this->assertSame('2026-11-01 08:00:00', $edge['added_at']);
    }

    #[TestDox('邊沒有 created_at（開始記錄前的舊資料）時，added_at 回 null，邊照常出現')]
    #[DataProvider('edgeKinds')]
    public function test_edge_without_created_at_reports_null_added_at(string $kind)
    {
        [$source, $target, $table, $where] = $this->makeEdge($kind);
        DB::table($table)->where($where)->update(['created_at' => null, 'updated_at' => null]);

        $response = $this->getJson('/api/graph')->assertOk();

        $edge = $this->findEdge($response, $source, $target);
        $this->assertNotNull($edge, '沒有時間的邊不能從圖裡消失');
        $this->assertArrayHasKey('added_at', $edge);
        $this->assertNull($edge['added_at']);
        $this->assertNotNull($edge['predicate']);
    }

    #[TestDox('邊既有的欄位在新增 added_at 後都還在')]
    public function test_edge_keeps_existing_fields_alongside_added_at()
    {
        $relation = Relation::factory()->create(['name' => 'documents']);
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $documentation->techniques()->sync([$technique->id => ['relation_id' => $relation->id]]);

        $edge = $this->findEdge($this->getJson('/api/graph')->assertOk(), "documentation-{$documentation->id}", "technique-{$technique->id}");

        $this->assertSame(
            ['added_at', 'label', 'predicate', 'relation_id', 'source', 'stale', 'target'],
            collect($edge)->keys()->sort()->values()->all(),
        );
        $this->assertSame('documents', $edge['predicate']);
        $this->assertSame($relation->id, $edge['relation_id']);
    }
}
