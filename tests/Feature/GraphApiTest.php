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
}
