<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
