<?php

namespace App\Http\Requests;

use App\Rules\ForeignId;
use App\Rules\ReverseIsAvailable;
use App\Rules\ReverseIsSwapped;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRelationRequest extends FormRequest
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
            // 長度用 max_digits 而不是 max 的理由同 StoreRelationRequest。
            /** 類號，最多兩位數字。 */
            'class_number' => 'required|numeric|max_digits:2',
            /** 子類號，最多兩位數字，可留空。 */
            'call_number' => 'nullable|numeric|max_digits:2',
            /** 述詞名稱，最多 50 字。 */
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('relations')->ignore($this->relation?->id),
            ],
            'note' => 'nullable|string|max:255',
            // 同 StoreRelationRequest。這裡多傳 $this->relation 進去,因為「目標已經指向
            // 正在編輯的這一筆」跟「目標就是自己(對稱關係)」都是合法的,要認得出來。
            /** 反向述詞的 id，可留空。必須是尚未配對的述詞，或已經指向這一條的述詞；對稱的述詞可指向自己。 */
            'reverse_id' => ['nullable', ...ForeignId::rules('relations'), new ReverseIsAvailable($this->relation), new ReverseIsSwapped($this->relation)],
        ];
    }
}
