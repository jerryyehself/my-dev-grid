<?php

namespace App\Http\Requests;

use App\Rules\ReverseIsAvailable;
use App\Rules\ReverseIsSwapped;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRelationRequest extends FormRequest
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
            'subject_id' => 'required|exists:scopes,id',
            'object_id' => 'required|exists:scopes,id',
            'class_number' => 'required|numeric',
            'call_number' => 'nullable|numeric',
            'name' => 'required|unique:relations',
            'note' => '',
            // reverse_id 必須雙向,而且只能指向還沒配對的關係——見 App\Rules\ReverseIsAvailable
            // 與 Relation::syncReverse() 的註解。建立一對的做法是:先建 A(reverse 留空),
            // 再建 B 並把 reverse_id 指向 A,雙向會自動補齊。
            /** 反向述詞的 id，可留空。必須是尚未配對的述詞；建立後雙方會互相指向。 */
            'reverse_id' => ['nullable', 'exists:relations,id', new ReverseIsAvailable, new ReverseIsSwapped],
        ];
    }
}
