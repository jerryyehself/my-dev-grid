<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when trying to change a locked field on a Relation that is already
 * referenced by an existing pivot link (documentation_implementation,
 * documentation_technique, technique_implementation, entity_relations).
 *
 * 這個類別自己負責變成 HTTP 回應（見下方 render()），呼叫端不需要、也不應該
 * 各自 try/catch 一次。原本的形狀是「例外什麼都不做，由 RelationController@update
 * 自己攔下來組 422」——那是把一個共用的失敗語意綁在單一呼叫點上：
 *
 * - `bootstrap/app.php` 的 `withExceptions` 是空的，所以**任何沒有自己攔的路徑**
 *   （其他 controller、未來的批次更新、artisan 指令）都會讓它變成未處理的 500。
 * - 而 `updating` 這個 model event 是全域的，不是 controller 專屬的——只要有人
 *   呼叫 `$relation->update()` 就會觸發。
 *
 * 所以正確的位置是例外本身，一次處理完所有路徑（engineering-principles 的
 * 「fix bugs at the shared root cause so every symptom resolves together」）。
 */
class RelationLockedException extends RuntimeException
{
    public array $lockedFields;

    public function __construct(array $lockedFields)
    {
        $this->lockedFields = $lockedFields;

        parent::__construct(
            'This relation is already in use by existing links; only "note" can still be edited. Locked fields: '.implode(', ', $lockedFields)
        );
    }

    /**
     * 不要寫進 error log：這是呼叫端送了一筆不該送的修改，屬於用戶端錯誤，
     * 跟伺服器故障是兩回事。留在 log 裡只會稀釋真正需要注意的例外。
     */
    public function report(): bool
    {
        return false;
    }

    /**
     * 422，而且 `errors` 要**以被鎖住的欄位名當 key**。
     *
     * 這不是形狀上的偏好，是這個修正的重點。Triple 的錯誤顯示是這樣串起來的：
     * `useFetchAPI.js` 把 422 body 的 `errors` 丟給 `useErrorsStore.setErrors()`，
     * 它把 `{欄位: [訊息]}` 攤平成 `{欄位: 訊息}`，`AppInputField.vue` 再用
     * `errors[inputKey]` 把訊息渲染在**那個欄位底下**。
     *
     * 原本 controller 回的是 `errors: { locked: [...] }`——`locked` 對不上任何一個
     * 輸入欄位的 key，所以那則訊息渲染不到任何地方：使用者按了儲存，畫面毫無反應，
     * 也沒有任何錯誤提示。**回應碼一直是對的，訊息卻一直看不到。**
     *
     * 改成以欄位名當 key 之後，同一份 payload 不需要前端改任何一行，訊息就會出現在
     * 正確的欄位旁邊，跟一般的驗證錯誤走同一條顯示路徑。
     *
     * `locked_fields` 另外原樣附上，給不想解析 `errors` 的呼叫端（例如要把整組欄位
     * 設成唯讀的畫面）直接用——`RelationResource` 也吐同名欄位，兩邊一致。
     */
    public function render(Request $request): JsonResponse
    {
        $message = '這條關係已經被既有的邊引用，'
            .implode('、', $this->lockedFields)
            .' 不能再修改，只有「註釋」還可以改。';

        return response()->json([
            'message' => $message,
            'errors' => collect($this->lockedFields)
                ->mapWithKeys(fn (string $field) => [$field => [$message]])
                ->all(),
            'locked_fields' => $this->lockedFields,
        ], 422);
    }
}
