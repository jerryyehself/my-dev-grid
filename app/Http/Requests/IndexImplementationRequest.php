<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexImplementationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 清單公開，不需要登入；草稿與不公開的實作由 controller 的 visibleToViewer() 濾掉。
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // 這個值會拿去跟 bigint 欄位比較，所以要先確認是整數，不能讓不合格的值進到查詢。
            // 空字串（?type=）會被轉成 null，當作沒指定。
            /** 依分類篩選：分類的 id，必須是整數。留空表示不篩選。 */
            'type' => 'nullable|integer',
        ];
    }
}
