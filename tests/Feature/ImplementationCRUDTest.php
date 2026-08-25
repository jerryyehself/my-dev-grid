<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImplementationCRUDTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_implementation()
    {
        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/implementations', [
            'type' => $scope->id,
            'title' => 'my-dev-grid',
            'url' => 'https://github.com/jerryyehself/my-dev-grid',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['title' => 'my-dev-grid']);

        $this->assertDatabaseHas('implementations', ['title' => 'my-dev-grid', 'type' => $scope->id]);
    }

    public function test_create_implementation_rejects_unknown_scope()
    {
        $response = $this->postJson('/api/implementations', [
            'type' => 99999,
            'title' => 'my-dev-grid',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_view_implementation()
    {
        $implementation = Implementation::factory()->create();

        $response = $this->getJson("/api/implementations/{$implementation->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $implementation->id]);
    }

    public function test_update_implementation()
    {
        $implementation = Implementation::factory()->create();

        $response = $this->putJson("/api/implementations/{$implementation->id}", [
            'type' => $implementation->type,
            'title' => 'Updated title',
            'is_visible' => false,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Updated title'])
            ->assertJsonFragment(['is_visible' => false]);

        $this->assertDatabaseHas('implementations', ['id' => $implementation->id, 'title' => 'Updated title']);
    }

    public function test_delete_implementation()
    {
        $implementation = Implementation::factory()->create();

        $response = $this->deleteJson("/api/implementations/{$implementation->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => "{$implementation->title} was deleted."]);

        $this->assertSoftDeleted('implementations', ['id' => $implementation->id]);
    }

    public function test_list_all_implementations()
    {
        Implementation::factory()->create(['title' => 'isbn-scanner']);

        $response = $this->getJson('/api/implementations');

        $response->assertOk()
            ->assertJsonFragment(['title' => 'isbn-scanner']);
    }
}
