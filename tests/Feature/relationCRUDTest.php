<?php

namespace Tests\Feature;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class relationCRUDTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_relation()
    {
        $this->actingAsOwner();
        $this->seed();

        // 用實際 seed 出來的 id,不是寫死的數字字面值——Postgres 的
        // sequence 不是交易性的,RefreshDatabase 每個測試回滾交易也不會讓它
        // 倒退,所以整個測試套件跑下來,scopes/relations 的 id 早就不是從
        // 1 開始,寫死的 id 在 SQLite 底下矇混得過去,換到 Postgres 就會踩雷。
        [$subjectId, $objectId] = Scope::orderBy('id')->take(2)->pluck('id');
        $reverseId = Relation::first()->id;

        $response = $this->postJson('/api/relations', [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Test',
            'class_number' => '99',
            'call_number' => '99',
            'reverse_id' => $reverseId,
        ]);
        // $response->dump();
        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Test']);

        $this->assertDatabaseHas('relations', ['name' => 'Test']);
    }

    public function test_create_relation_rejects_unauthenticated_request()
    {
        $this->seed();

        [$subjectId, $objectId] = Scope::orderBy('id')->take(2)->pluck('id');
        $reverseId = Relation::first()->id;

        $response = $this->postJson('/api/relations', [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Test',
            'class_number' => '99',
            'call_number' => '99',
            'reverse_id' => $reverseId,
        ]);

        $response->assertUnauthorized();
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
        $this->actingAsOwner();
        $this->seed();

        $relation = Relation::inRandomOrder()->first();
        [$subjectId, $objectId] = Scope::orderBy('id')->take(2)->pluck('id');

        $response = $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Updated name',
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated name']);

        $this->assertDatabaseHas('relations', ['id' => $relation->id, 'name' => 'Updated name']);
    }

    public function test_update_relation_rejects_unauthenticated_request()
    {
        $this->seed();

        $relation = Relation::inRandomOrder()->first();
        [$subjectId, $objectId] = Scope::orderBy('id')->take(2)->pluck('id');

        $response = $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Updated name',
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
        ]);

        $response->assertUnauthorized();
    }

    public function test_update_relation_name_unique_check_is_scoped_to_relations_table()
    {
        $this->actingAsOwner();
        $this->seed();

        // A Scope happens to share this name; updating a Relation to the same
        // name must not be blocked by a uniqueness check against the wrong table.
        $scopeName = Scope::first()->name;
        $relation = Relation::inRandomOrder()->first();

        $response = $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => $relation->subject_id,
            'object_id' => $relation->object_id,
            'name' => $scopeName,
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => $scopeName]);
    }

    public function test_delete_relation()
    {
        $this->actingAsOwner();
        $this->seed();

        $relation = Relation::first();

        $response = $this->deleteJson("/api/relations/{$relation->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => "{$relation->name} was deleted."]);

        $this->assertSoftDeleted('relations', ['id' => $relation->id]);
    }

    public function test_delete_relation_rejects_unauthenticated_request()
    {
        $this->seed();

        $relation = Relation::first();

        $response = $this->deleteJson("/api/relations/{$relation->id}");

        $response->assertUnauthorized();
    }

    public function test_list_all_relations()
    {
        $this->seed();

        $response = $this->getJson('api/relations');
        $response->assertOk();

        // $this->assertCount(3, $response->json('data'));

        $response->assertJsonFragment([
            'name' => 'specs',
        ]);
    }

    public function test_seeder_creates_requires_and_is_required_by_as_reciprocal_pair()
    {
        $this->seed();

        $requires = Relation::where('name', 'requires')->firstOrFail();
        $isRequiredBy = Relation::where('name', 'isRequiredBy')->firstOrFail();

        $this->assertSame($isRequiredBy->id, $requires->reverse_id);
        $this->assertSame($requires->id, $isRequiredBy->reverse_id);
    }
}
