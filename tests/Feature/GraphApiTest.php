<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_graph_response_has_nodes_and_edges_keys()
    {
        $response = $this->getJson('/api/graph');

        $response->assertOk()->assertJsonStructure(['nodes', 'edges']);
    }

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

    public function test_path_requires_start_and_end_query_params()
    {
        $response = $this->getJson('/api/graph/path');

        $response->assertStatus(422);
    }

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
