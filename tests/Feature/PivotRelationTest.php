<?php

namespace Tests\Feature;

use App\Exceptions\RelationLockedException;
use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\TechniqueImplementationLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PivotRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pivot_link_carries_relation_id()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();

        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $linked = $documentation->techniques()->first();
        $this->assertSame($relation->id, $linked->pivot->relation_id);
    }

    public function test_duplicate_edge_with_same_relation_id_is_rejected()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();

        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $this->expectException(QueryException::class);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
    }

    public function test_same_pair_can_carry_two_different_relation_edges()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relationA = Relation::factory()->create();
        $relationB = Relation::factory()->create();

        $documentation->techniques()->attach($technique->id, ['relation_id' => $relationA->id]);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relationB->id]);

        $this->assertCount(2, $documentation->techniques()->get());
    }

    public function test_entity_relation_requires_valid_entity_type()
    {
        $this->expectException(InvalidArgumentException::class);

        EntityRelation::factory()->create(['entity_type' => 'not-a-real-type']);
    }

    public function test_entity_relation_requires_subject_to_exist_in_entity_type_table()
    {
        $this->expectException(InvalidArgumentException::class);

        EntityRelation::factory()->create([
            'entity_type' => 'technique',
            'subject_id' => 999999,
        ]);
    }

    public function test_entity_relation_between_two_techniques_succeeds()
    {
        $entityRelation = EntityRelation::factory()->create();

        $this->assertDatabaseHas('entity_relations', ['id' => $entityRelation->id]);
    }

    public function test_relation_with_no_links_can_be_freely_updated()
    {
        $relation = Relation::factory()->create();

        $relation->update(['name' => 'renamed']);

        $this->assertSame('renamed', $relation->fresh()->name);
    }

    public function test_relation_referenced_by_a_link_locks_identity_fields()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $this->expectException(RelationLockedException::class);
        $relation->update(['name' => 'renamed']);
    }

    public function test_relation_referenced_by_a_link_locks_subject_and_object()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $otherScope = \App\Models\Scope::factory()->create();

        $this->expectException(RelationLockedException::class);
        $relation->update(['subject_id' => $otherScope->id]);
    }

    public function test_relation_referenced_by_a_link_still_allows_note_edits()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $relation->update(['note' => 'updated note']);

        $this->assertSame('updated note', $relation->fresh()->note);
    }

    public function test_update_relation_endpoint_returns_422_when_locked_field_changed()
    {
        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create();
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $response = $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => $relation->subject_id,
            'object_id' => $relation->object_id,
            'name' => 'renamed',
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['locked']]);
    }

    public function test_relation_link_resolves_relation_after_soft_delete()
    {
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create();
        $technique->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);

        $relation->delete();

        $link = TechniqueImplementationLink::where('technique_id', $technique->id)
            ->where('implementation_id', $implementation->id)
            ->first();

        $this->assertNotNull($link->relation);
        $this->assertSame($relation->id, $link->relation->id);
    }
}
