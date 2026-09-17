<?php

namespace App\Http\Requests;

use App\Models\Scope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScopeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
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
            'parent_class' => [
                'required',
                Rule::exists('scopes', 'id')->whereNull('parent_class'),
            ],
            'call_number' => [
                'nullable',
                'numeric',
                // 同一個分類號底下 call_number 不可重複。分類號現在從父層取,
                // 不再讀呼叫端傳來的 class_number。
                Rule::unique('scopes')
                    ->ignore($this->scope->id)
                    ->where(fn ($query) => $query->where(
                        'class_number',
                        Scope::find($this->parent_class)?->class_number
                    )),
            ],
            'name' => [
                'bail',
                'required',
                Rule::unique('scopes', 'name')->ignore($this->scope->id),
            ],
            'comment' => 'max:100',
            'note' => 'max:255',
        ];
    }
}
