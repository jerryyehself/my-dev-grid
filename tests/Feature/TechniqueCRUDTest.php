<?php

namespace Tests\Feature;

use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechniqueCRUDTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_technique()
    {
        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/techniques', [
            'type' => $scope->id,
            'title' => 'Laravel',
            'version' => '13.0',
            'note' => 'unit test note',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['title' => 'Laravel']);

        $this->assertDatabaseHas('techniques', ['title' => 'Laravel', 'type' => $scope->id]);
    }

    public function test_create_technique_rejects_unknown_scope()
    {
        $response = $this->postJson('/api/techniques', [
            'type' => 99999,
            'title' => 'Laravel',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_view_technique()
    {
        $technique = Technique::factory()->create();

        $response = $this->getJson("/api/techniques/{$technique->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $technique->id]);
    }

    public function test_update_technique()
    {
        $technique = Technique::factory()->create();

        $response = $this->putJson("/api/techniques/{$technique->id}", [
            'type' => $technique->type,
            'title' => 'Updated title',
            'version' => $technique->version,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Updated title']);

        $this->assertDatabaseHas('techniques', ['id' => $technique->id, 'title' => 'Updated title']);
    }

    public function test_delete_technique()
    {
        $technique = Technique::factory()->create();

        $response = $this->deleteJson("/api/techniques/{$technique->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => "{$technique->title} was deleted."]);

        $this->assertSoftDeleted('techniques', ['id' => $technique->id]);
    }

    public function test_list_all_techniques()
    {
        Technique::factory()->create(['title' => 'Vue']);

        $response = $this->getJson('/api/techniques');

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Vue']);
    }
}
