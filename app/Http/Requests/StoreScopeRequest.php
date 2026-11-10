<?php

namespace App\Http\Requests;

use App\Rules\ForeignId;
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

    /** @return array<string, ValidationRule|array<mixed>|string> */
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
            // 子類號的欄位是 char(2)。長度用 max_digits，不能用 max：有 numeric 時 max 比的是數值大小。
            /** 子類號，最多兩位數字，可留空。 */
            'call_number' => 'nullable|numeric|max_digits:2',
            /** 分類名稱，最多 50 字。 */
            'name' => ['required', 'string', 'max:50', Rule::unique('scopes')->whereNull('deleted_at')],
            'comment' => 'required|max:100',
            'note' => 'nullable|string|max:255',
        ];
    }
}
