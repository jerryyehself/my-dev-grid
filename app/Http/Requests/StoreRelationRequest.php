<?php

namespace App\Http\Requests;

use App\Rules\ForeignId;
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
            'subject_id' => ['required', ...ForeignId::rules('scopes')],
            'object_id' => ['required', ...ForeignId::rules('scopes')],
            'class_number' => 'required|numeric',
            // controller 靠 call_number 判斷是不是頂層述詞（'00'）、要不要接父層，所以一定要有這個
            // 欄位。present 只要求欄位有送，值可以是 null（空字串會被框架轉成 null）；
            // 少了 present，欄位沒送也算通過驗證，validated() 裡就沒有這個 key。
            /** 子類號。一定要送出這個欄位：`00` 是頂層述詞，其他值會接到同一個類號的頂層述詞底下；留空（null）時不指定子類號，同樣接到頂層述詞底下。 */
            'call_number' => 'present|nullable|numeric',
            'name' => 'required|unique:relations',
            'note' => '',
            // reverse_id 必須雙向,而且只能指向還沒配對的關係——見 App\Rules\ReverseIsAvailable
            // 與 Relation::syncReverse() 的註解。建立一對的做法是:先建 A(reverse 留空),
            // 再建 B 並把 reverse_id 指向 A,雙向會自動補齊。
            /** 反向述詞的 id，可留空。必須是尚未配對的述詞；建立後雙方會互相指向。 */
            'reverse_id' => ['nullable', ...ForeignId::rules('relations'), new ReverseIsAvailable, new ReverseIsSwapped],
        ];
    }
}
