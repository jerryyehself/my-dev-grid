<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationCRUDTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_documentation()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => 'Laravel Docs',
            'url' => 'https://laravel.com/docs',
            'note' => 'unit test note',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['title' => 'Laravel Docs']);

        $this->assertDatabaseHas('documentations', ['title' => 'Laravel Docs', 'type' => $scope->id]);
    }

    public function test_create_documentation_rejects_unknown_scope()
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/documentations', [
            'type' => 99999,
            'title' => 'Laravel Docs',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_create_documentation_rejects_unauthenticated_request()
    {
        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => 'Laravel Docs',
        ]);

        $response->assertUnauthorized();
    }

    public function test_view_documentation()
    {
        $documentation = Documentation::factory()->create();

        $response = $this->getJson("/api/documentations/{$documentation->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $documentation->id]);
    }

    public function test_update_documentation()
    {
        $this->actingAsOwner();

        $documentation = Documentation::factory()->create();

        $response = $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => 'Updated title',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Updated title']);

        $this->assertDatabaseHas('documentations', ['id' => $documentation->id, 'title' => 'Updated title']);
    }

    public function test_update_documentation_rejects_unauthenticated_request()
    {
        $documentation = Documentation::factory()->create();

        $response = $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => 'Updated title',
        ]);

        $response->assertUnauthorized();
    }

    public function test_delete_documentation()
    {
        $this->actingAsOwner();

        $documentation = Documentation::factory()->create();

        $response = $this->deleteJson("/api/documentations/{$documentation->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => "{$documentation->title} was deleted."]);

        $this->assertSoftDeleted('documentations', ['id' => $documentation->id]);
    }

    public function test_delete_documentation_rejects_unauthenticated_request()
    {
        $documentation = Documentation::factory()->create();

        $response = $this->deleteJson("/api/documentations/{$documentation->id}");

        $response->assertUnauthorized();
    }

    public function test_list_all_documentations()
    {
        Documentation::factory()->create(['title' => 'Vue Docs']);

        $response = $this->getJson('/api/documentations');

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Vue Docs']);
    }
}
