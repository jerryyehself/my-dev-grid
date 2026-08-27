<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PivotRelationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_documentation_attaches_techniques_with_relation_id()
    {
        $scope = Scope::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => 'Laravel Docs',
            'techniques' => [
                ['id' => $technique->id, 'relation_id' => $relation->id],
            ],
        ]);

        $response->assertCreated();
        $documentation = Documentation::where('title', 'Laravel Docs')->firstOrFail();

        $this->assertDatabaseHas('documentation_technique', [
            'documentation_id' => $documentation->id,
            'technique_id' => $technique->id,
            'relation_id' => $relation->id,
        ]);
        $response->assertJsonPath('data.techniques.0.relation_id', $relation->id);
    }

    public function test_store_documentation_rejects_unknown_relation_id()
    {
        $scope = Scope::factory()->create();
        $technique = Technique::factory()->create();

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => 'Laravel Docs',
            'techniques' => [
                ['id' => $technique->id, 'relation_id' => 999999],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('techniques.0.relation_id');
    }

    public function test_update_documentation_syncs_implementations_with_relation_id()
    {
        $documentation = Documentation::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create();

        $response = $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => $documentation->title,
            'implementations' => [
                ['id' => $implementation->id, 'relation_id' => $relation->id],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('documentation_implementation', [
            'documentation_id' => $documentation->id,
            'implementation_id' => $implementation->id,
            'relation_id' => $relation->id,
        ]);
        $response->assertJsonPath('data.implementations.0.relation_id', $relation->id);
    }

    public function test_update_documentation_resyncs_replacing_previous_pivot_set()
    {
        $documentation = Documentation::factory()->create();
        $techniqueA = Technique::factory()->create();
        $techniqueB = Technique::factory()->create();
        $relation = Relation::factory()->create();

        $documentation->techniques()->attach($techniqueA->id, ['relation_id' => $relation->id]);

        $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => $documentation->title,
            'techniques' => [
                ['id' => $techniqueB->id, 'relation_id' => $relation->id],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('documentation_technique', [
            'documentation_id' => $documentation->id,
            'technique_id' => $techniqueA->id,
        ]);
        $this->assertDatabaseHas('documentation_technique', [
            'documentation_id' => $documentation->id,
            'technique_id' => $techniqueB->id,
            'relation_id' => $relation->id,
        ]);
    }

    public function test_show_documentation_resource_exposes_relation_id_on_nested_techniques()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $response = $this->getJson("/api/documentations/{$documentation->id}");

        $response->assertOk()
            ->assertJsonPath('techniques.0.relation_id', $relation->id);
    }

    public function test_technique_resource_does_not_expose_relation_id_when_not_loaded_via_pivot()
    {
        $technique = Technique::factory()->create();

        $response = $this->getJson('/api/techniques');

        $response->assertOk();
        $this->assertArrayNotHasKey('relation_id', $response->json('data.0'));
    }
}
