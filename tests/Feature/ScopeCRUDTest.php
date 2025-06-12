<?php

namespace Tests\Feature;

use App\Models\Scope;
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
        $this->seed();
        $response = $this->getJson('api/scopes');

        $response->assertOk();


        // seed裡面有14+10筆資料
        $this->assertCount(24, $response->json('data'));

        $response->assertJsonFragment([
            'name' => 'Documentation'
        ]);
    }
}
