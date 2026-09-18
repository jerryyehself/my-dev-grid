<?php

namespace Tests\Feature;

use App\Exceptions\RelationLockedException;
use App\Models\Documentation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 改一條已被引用的 Relation 的鎖定欄位時，回應必須是「前端真的用得上」的 422。
 *
 * 規格（本體論編輯規格 · B2）原本把這件事寫成「會回 500」。**那個描述不準確**，
 * 查證後的實情更精確，也更難發現：`RelationController@update` 本來就有一段 try/catch
 * 會回 422，所以狀態碼一直是對的——**但那個 422 的內容在 Triple 上渲染不到任何地方。**
 *
 * 串起來是這樣的：`useFetchAPI.js` 把 422 body 的 `errors` 丟給
 * `useErrorsStore.setErrors()`，它把 `{欄位: [訊息]}` 攤平成 `{欄位: 訊息}`，
 * `AppInputField.vue` 再用 `errors[inputKey]` 把訊息渲染在**那個欄位底下**。
 * 而 controller 回的 key 是 `locked`，對不上任何一個輸入欄位——於是使用者按下儲存，
 * 畫面毫無反應、沒有任何錯誤提示，資料也沒存進去。**靜默失敗，不是 500。**
 *
 * 另外 500 那個描述在**其他路徑上**是成立的：`updating` 是全域的 model event，
 * 任何 `$relation->update()` 都會觸發，而 `bootstrap/app.php` 的 `withExceptions`
 * 是空的，所以只要不是走那個 controller，它就是未處理的 500。修法因此放在例外本身
 * （`render()`），不是再加一個 try/catch。
 */
class RelationLockedResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 造一條「已經被真實的邊引用」的關係出來。
     *
     * 跟 RelationReferenceLockTest 一樣自己造邊，不依賴 seeder——DatabaseSeeder 只跑
     * ScopeSeeder/RelationSeeder，不產生任何連結。
     */
    private function lockedRelation(): Relation
    {
        $this->seed();

        $relation = Relation::all()->first(
            fn (Relation $r) => ! is_null($r->reverse_id) && $r->reverse_id !== $r->id
        );
        $this->assertNotNull($relation, 'seeder 應該種出至少一對非對稱的關係。');

        Documentation::factory()
            ->create()
            ->techniques()
            ->attach(Technique::factory()->create()->id, ['relation_id' => $relation->id]);

        $relation = $relation->fresh();
        $this->assertTrue($relation->isReferenced(), '前置條件：這一條必須已經被引用。');

        return $relation;
    }

    private function payloadRenaming(Relation $relation): array
    {
        return [
            'subject_id' => $relation->subject_id,
            'object_id' => $relation->object_id,
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
            'name' => $relation->name.'Renamed',
            'note' => $relation->note,
            'reverse_id' => $relation->reverse_id,
        ];
    }

    public function test_changing_a_locked_field_returns_422_and_does_not_persist()
    {
        $this->actingAsOwner();
        $relation = $this->lockedRelation();
        $originalName = $relation->name;

        $this->putJson("/api/relations/{$relation->id}", $this->payloadRenaming($relation))
            ->assertStatus(422);

        $this->assertSame(
            $originalName,
            $relation->fresh()->name,
            '被擋下來之後，名稱不能被改掉。'
        );
    }

    /**
     * **這支測試就是這個修正的重點。**
     *
     * `errors` 的 key 必須是被鎖住的欄位名（這裡是 `name`），不是一個像 `locked`
     * 這種對不上任何輸入欄位的通用 key——否則 Triple 的 `errors[inputKey]` 查不到，
     * 訊息不會出現在畫面上，使用者只會看到「按了沒反應」。
     */
    public function test_the_422_keys_its_errors_by_the_locked_field_names()
    {
        $this->actingAsOwner();
        $relation = $this->lockedRelation();

        $body = $this->putJson("/api/relations/{$relation->id}", $this->payloadRenaming($relation))
            ->assertStatus(422)
            ->json();

        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey(
            'name',
            $body['errors'],
            'errors 要以被鎖住的欄位名當 key，Triple 才顯示得出來。'
        );
        $this->assertArrayNotHasKey(
            'locked',
            $body['errors'],
            '`locked` 這個 key 對不上任何輸入欄位，是這次要移除的舊形狀。'
        );

        // 機器可讀的那一份，給要把整組欄位設成唯讀的畫面直接用。
        $this->assertContains('name', $body['locked_fields']);
        $this->assertNotEmpty($body['message']);
    }

    /**
     * 只改 note 仍然要成功——鎖定的是身分欄位，不是整筆資料。
     */
    public function test_note_is_still_editable_on_a_locked_relation()
    {
        $this->actingAsOwner();
        $relation = $this->lockedRelation();

        $payload = $this->payloadRenaming($relation);
        $payload['name'] = $relation->name;   // 不動鎖定欄位
        $payload['note'] = '改註釋應該要過';

        $this->putJson("/api/relations/{$relation->id}", $payload)->assertOk();

        $this->assertSame('改註釋應該要過', $relation->fresh()->note);
    }

    /**
     * 例外自己就會變成 422，不靠任何 controller 攔截。
     *
     * 這支測試守的是「修法放在共用源頭」這個決定本身：只要有人在別的地方
     * （其他 controller、批次更新、artisan 指令）呼叫 `$relation->update()`，
     * 回應形狀都該一致，而不是變成未處理的 500。
     */
    public function test_the_exception_renders_itself_without_any_controller()
    {
        $exception = new RelationLockedException(['name', 'subject_id']);

        $response = $exception->render(Request::create('/api/relations/1', 'PUT'));

        $this->assertSame(422, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertSame(['name', 'subject_id'], $payload['locked_fields']);
        $this->assertArrayHasKey('name', $payload['errors']);
        $this->assertArrayHasKey('subject_id', $payload['errors']);
    }

    /**
     * 不要進 error log——這是呼叫端送錯，不是伺服器故障。
     */
    public function test_the_exception_is_not_reported()
    {
        $this->assertFalse((new RelationLockedException(['name']))->report());
    }
}
