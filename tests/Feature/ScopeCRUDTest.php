<?php

namespace Tests\Feature;

use App\Models\Scope;
use Database\Seeders\ScopeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ScopeCRUDTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_scope()
    {
        $response = $this->postJson('/api/scopes', [
            'name' => 'Test',
            'class_number' => '99',
            'call_number' => '99',
            'comment' => 'unit test comment',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Test']);

        $this->assertDatabaseHas('scopes', ['name' => 'Test']);
    }

    public function test_view_scope()
    {
        $scope = Scope::factory()->create();

        $response = $this->getJson("/api/scopes/{$scope->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $scope->id]);
    }

    public function test_update_scope()
    {
        $scope = Scope::factory()->create();

        $response = $this->putJson("/api/scopes/{$scope->id}", [
            'name' => 'Updated Name',
            'class_number' => $scope->class_number,
            'call_number' => $scope->call_number,
            'comment' => 'Updated comment',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name']);

        $this->assertDatabaseHas('scopes', ['id' => $scope->id, 'name' => 'Updated Name']);
    }

    public function test_delete_scope()
    {
        $scope = Scope::factory()->create();

        $response = $this->deleteJson("/api/scopes/{$scope->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('scopes', ['id' => $scope->id]);
    }

    public function test_list_all_scopes()
    {
        $this->seed(ScopeSeeder::class);
        $response = $this->getJson('api/scopes');

        $response->assertOk();
        $this->assertCount(14, $response->json('data'), '錯誤');

        $response->assertJsonFragment([
            'name' => 'Documentation'
        ]);
    }
}
