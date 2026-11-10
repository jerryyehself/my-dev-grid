<?php

namespace App\Http\Requests;

use App\Models\Scope;
use App\Rules\ForeignId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class UpdateScopeRequest extends FormRequest
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
     * - `comment` 欄位不允許 NULL，所以這裡不加 `nullable`：有送但留空（null）時回 422，不讓它送到
     *   資料庫才失敗。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        return [
            // 跟 StoreScopeRequest 一致:收父 Scope 的 id,class_number 由 controller
            // 推導。原本這裡收的是字面分類號且完全沒有驗證它跟 parent_class 對不對得上,
            // 所以可以把一個父層是 Documentation(class 00) 的 scope 改成 class_number
            // = 99,製造出實際資料裡一筆都不存在的不一致(實測 16 筆全部一致)。
            /** 父分類的 id，必須是頂層分類。類號會依父分類自動帶入，不用傳。 */
            'parent_class' => [
                'required',
                ...ForeignId::rules('scopes', fn (Exists $rule) => $rule->whereNull('parent_class')),
            ],
            /** 子類號，最多兩位數字，可留空。同一個類號底下不能重複。 */
            'call_number' => [
                'nullable',
                'numeric',
                'max_digits:2',
                // 同一個分類號底下 call_number 不可重複。分類號現在從父層取,
                // 不再讀呼叫端傳來的 class_number。
                Rule::unique('scopes')
                    ->ignore($this->scope?->id)
                    ->where(fn ($query) => $query->where(
                        'class_number',
                        // parent_class 不是整數時不能拿去查（PostgreSQL 會丟 bigint 的語法錯誤）；
                        // 它自己的規則會回 422。
                        $this->parentClassNumber()
                    )),
            ],
            'name' => [
                'bail',
                'required',
                'string',
                'max:50',
                Rule::unique('scopes', 'name')->ignore($this->scope?->id),
            ],
            'comment' => 'string|max:100',
            'note' => 'nullable|string|max:255',
        ];
    }

    /** 請求指定的父分類的類號；`parent_class` 不是整數、或找不到父分類時是 null。 */
    private function parentClassNumber(): ?string
    {
        $parentId = filter_var($this->input('parent_class'), FILTER_VALIDATE_INT);

        return $parentId === false ? null : Scope::find($parentId)?->class_number;
    }
}
