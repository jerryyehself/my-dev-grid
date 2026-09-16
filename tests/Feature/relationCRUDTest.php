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

        // 這裡刻意**不**帶 reverse_id。2026-09-16 之前這支測試帶的是
        // `Relation::first()->id`,也就是 seeder 種出來的 `specs`——而 `specs`
        // 早就跟 `specifiedBy` 配成一對了。等於在斷言「可以建一條單向指進已配對
        // 關係的邊」,那正是 ReverseIsAvailable 現在要擋掉的行為。一對的正確建法是
        // 下面 test_creating_a_partner_wires_both_directions 那樣分兩步。
        $response = $this->postJson('/api/relations', [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Test',
            'class_number' => '99',
            'call_number' => '99',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Test']);

        $this->assertDatabaseHas('relations', ['name' => 'Test', 'reverse_id' => null]);
    }

    /**
     * 建立一對關係的正常流程:先建 A(reverse 留空),再建 B 並指向 A。
     * B 存檔時 Relation::syncReverse() 會把 A 的 reverse_id 補回指 B,
     * 呼叫端不需要、也不應該再送第二次請求去補另一邊。
     */
    public function test_creating_a_partner_wires_both_directions()
    {
        $this->actingAsOwner();
        $this->seed();

        [$subjectId, $objectId] = Scope::orderBy('id')->take(2)->pluck('id');

        $this->postJson('/api/relations', [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Forward',
            'class_number' => '98',
            'call_number' => '00',
        ])->assertCreated();

        $forward = Relation::where('name', 'Forward')->firstOrFail();
        $this->assertNull($forward->reverse_id, '第一條建完時不該有反向。');

        // 反向那條:主詞受詞對調,並指向剛才那條。
        $this->postJson('/api/relations', [
            'subject_id' => $objectId,
            'object_id' => $subjectId,
            'name' => 'Backward',
            'class_number' => '89',
            'call_number' => '00',
            'reverse_id' => $forward->id,
        ])->assertCreated();

        $backward = Relation::where('name', 'Backward')->firstOrFail();

        $this->assertSame($forward->id, $backward->reverse_id);
        $this->assertSame(
            $backward->id,
            $forward->fresh()->reverse_id,
            '另一邊要被自動補上,不能留下單向的配對。'
        );
    }

    /**
     * 對稱關係——reverse_id 指向自己——是合法的,而且實際存在:seeder 種的
     * `accompanies` 就是這樣(A accompanies B 等價於 B accompanies A,不需要第二條)。
     */
    public function test_a_relation_may_be_its_own_reverse()
    {
        $this->actingAsOwner();
        $this->seed();

        [$subjectId, $objectId] = Scope::orderBy('id')->take(2)->pluck('id');

        $this->postJson('/api/relations', [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Symmetric',
            'class_number' => '97',
            'call_number' => '00',
        ])->assertCreated();

        $symmetric = Relation::where('name', 'Symmetric')->firstOrFail();

        $this->putJson("/api/relations/{$symmetric->id}", [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Symmetric',
            'class_number' => '97',
            'call_number' => '00',
            'reverse_id' => $symmetric->id,
        ])->assertOk();

        $this->assertSame($symmetric->id, $symmetric->fresh()->reverse_id);
    }

    /**
     * 指向一條「已經跟別人配對」的關係要被擋下來。不擋的話只有兩種結果,兩種都不對:
     * 不同步就留下單向的壞配對,同步就會默默把對方原本的伴侶踢掉變成孤兒。
     */
    public function test_reverse_id_cannot_point_at_an_already_paired_relation()
    {
        $this->actingAsOwner();
        $this->seed();

        [$subjectId, $objectId] = Scope::orderBy('id')->take(2)->pluck('id');

        // seeder 種出來的都已經成對,隨便挑一條都符合「已配對」。
        $alreadyPaired = Relation::whereNotNull('reverse_id')->firstOrFail();
        $itsPartnerId = $alreadyPaired->reverse_id;

        $this->postJson('/api/relations', [
            'subject_id' => $subjectId,
            'object_id' => $objectId,
            'name' => 'Intruder',
            'class_number' => '96',
            'call_number' => '00',
            'reverse_id' => $alreadyPaired->id,
        ])->assertStatus(422)->assertJsonValidationErrors('reverse_id');

        $this->assertDatabaseMissing('relations', ['name' => 'Intruder']);
        $this->assertSame(
            $itsPartnerId,
            $alreadyPaired->fresh()->reverse_id,
            '被擋下來的請求不該動到既有的配對。'
        );
    }

    /**
     * 全表不變量:每一條關係的 reverse_id 要嘛是 null,要嘛指向一條回指自己的關係。
     *
     * 在此之前只有 `requires`/`isRequiredBy` 這一對被斷言過(見檔尾那支),
     * 其餘 6 對加上 `accompanies` 的自指完全沒有覆蓋——而 15 條全部成對,靠的
     * 純粹是 RelationSeeder 剛好寫對了,沒有任何機制在維護。
     */
    public function test_every_seeded_relation_is_reciprocally_paired()
    {
        $this->seed();

        $relations = Relation::all()->keyBy('id');

        $this->assertCount(15, $relations, 'seeder 的關係數量變了,這支測試的預期要一起更新。');

        foreach ($relations as $relation) {
            if (is_null($relation->reverse_id)) {
                continue;
            }

            $reverse = $relations->get($relation->reverse_id);

            $this->assertNotNull($reverse, "關係「{$relation->name}」的 reverse_id 指向一筆不存在的資料。");
            $this->assertSame(
                $relation->id,
                $reverse->reverse_id,
                "關係「{$relation->name}」指向「{$reverse->name}」,但對方沒有回指——單向的配對。"
            );
        }

        // 自指(對稱關係)確實存在,不是理論上的可能性而已。
        $this->assertTrue(
            $relations->contains(fn (Relation $r) => $r->reverse_id === $r->id),
            'seeder 應該至少種出一條對稱關係(accompanies)。'
        );
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
