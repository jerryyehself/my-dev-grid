<?php

namespace App\Http\Requests;

use App\Rules\ForeignId;
use App\Rules\ScopeCallNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
        return true;
    }

    /**
     * 文字欄位的長度與型別規則對應 `scopes` 資料表的欄位，擋掉會讓資料庫寫入出錯的值。
     *
     * - `name`（varchar(50)）、`call_number`（兩個字元）：超過長度的值 SQLite 照樣寫入，PostgreSQL 會在
     *   寫入時丟錯、變成 500。子類號有 `numeric`，`max:2` 比的會是數值大小，位數要用 `max_digits`。
     * - `name`、`comment`、`note` 的 `string` 擋的是陣列：沒有它，陣列會一路送到查詢或寫入才出錯。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // 這個欄位收的是**父 Scope 的 id**,不是分類號本身——所以欄位名就叫
            // parent_class,跟資料表欄位一致。class_number 由 controller 從父層推導,
            // 不接受呼叫端傳入。
            //
            // 2026-09-17 之前這裡叫 class_number,但收的是 id,而 UpdateScopeRequest
            // 的同名欄位收的是字面分類號——同一個欄位名、兩種語意,表單不能共用 payload。
            // 當時的後台是用繞的:新增表單用 select 挑父層、編輯表單把同一個欄位換成
            // number 直接編,等於把 API 的不一致往前端推。
            /** 父分類的 id，必須是頂層分類。類號會依父分類自動帶入，不用傳。 */
            'parent_class' => [
                'required',
                ...ForeignId::rules('scopes', fn (Exists $rule) => $rule->whereNull('parent_class')),
            ],
            /** 子類號，最多兩位數字，可留空。同一個類號底下不能重複。 */
            'call_number' => ScopeCallNumber::rules($this->input('parent_class')),
            // 唯一性不算已軟刪除的分類,跟 UpdateScopeRequest 一致。
            /** 分類名稱，最多 50 字；不能跟其他還在的分類重複，已刪除的分類用過的名稱可以再用。 */
            'name' => ['required', 'string', 'max:50', Rule::unique('scopes')->withoutTrashed()],
            'comment' => 'required|string|max:100',
            'note' => 'nullable|string|max:255',
        ];
    }
}
