<?php

namespace Tests\Feature;

use App\Models\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class relationCRUDTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_relation()
    {
        $this->seed();

        $response = $this->postJson('/api/relations', [
            'subject_id' => '14',
            'object_id' => '01',
            'name' => 'Test',
            'class_number' => '99',
            'call_number' => '99',
            'comment' => 'unit test comment',
            'reverse_id' => 1
        ]);
        // $response->dump();
        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Test']);

        $this->assertDatabaseHas('relations', ['name' => 'Test']);
    }

    public function test_view_relation()
    {
        $this->seed();

        $relation = Relation::inRandomOrder()->first();

        $response = $this->getJson("/api/relations/{$relation->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $relation->id]);
    }

    public function test_update_relation()
    {
        $this->seed();

        $relation = Relation::inRandomOrder()->first();

        $response = $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => '1',
            'object_id' => '14',
            'name' => 'Updated name',
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
            'comment' => 'Updated comment',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated name']);

        $this->assertDatabaseHas('relations', ['id' => $relation->id, 'name' => 'Updated name']);
    }

    public function test_delete_relation()
    {
        $this->seed();

        $relation = Relation::first();

        $response = $this->deleteJson("/api/relations/{$relation->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('relations', ['id' => $relation->id]);
    }

    public function test_list_all_relations()
    {
        $this->seed();

        $response = $this->getJson('api/relations');
        $response->assertOk();

        // $this->assertCount(3, $response->json('data'));

        $response->assertJsonFragment([
            'name' => 'specs'
        ]);
    }
}
