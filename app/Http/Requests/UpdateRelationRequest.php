<?php

namespace App\Http\Requests;

use App\Rules\ForeignId;
use App\Rules\ReverseIsAvailable;
use App\Rules\ReverseIsSwapped;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
        return true;
    }

    /**
     * 文字欄位的長度與型別規則對應 `relations` 資料表的欄位，擋掉會讓資料庫寫入出錯的值。
     *
     * - `name`（varchar(50)）、`class_number`、`call_number`（各兩個字元）：超過長度的值 SQLite 照樣寫入，
     *   PostgreSQL 會在寫入時丟錯、變成 500。類號與子類號有 `numeric`，`max:2` 比的會是數值大小，
     *   位數要用 `max_digits`。
     * - `name`、`note` 的 `string` 擋的是陣列：沒有它，陣列會一路送到查詢或寫入才出錯。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject_id' => ['required', ...ForeignId::rules('scopes')],
            'object_id' => ['required', ...ForeignId::rules('scopes')],
            /** 類號，最多兩位數字。 */
            'class_number' => 'required|numeric|max_digits:2',
            /** 子類號，最多兩位數字，可留空。 */
            'call_number' => 'nullable|numeric|max_digits:2',
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

    /**
     * 已棄用的述詞不能改 reverse_id（改成別條或清空都不行）：成對的兩邊棄用狀態與改用對象要一致
     * （見 `Relation::deprecate()`），換了反向，新的那一邊就會跟它不一致。要換反向，先取消棄用。
     *
     * 寫在 after() 而不是欄位規則裡：`nullable` 的欄位送 null 時，其他規則都不會跑，擋不到清空。
     * 請求沒帶 `reverse_id` 時不會改到，不擋；重送同樣的值也不擋，舊版前端送整份表單照常可以存。
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $relation = $this->route('relation');
                if (! $relation?->isDeprecated() || ! $this->has('reverse_id')) {
                    return;
                }

                $value = $this->input('reverse_id');
                $requested = $value === null ? null : filter_var($value, FILTER_VALIDATE_INT);
                $current = $relation->reverse_id === null ? null : (int) $relation->reverse_id;

                if ($requested !== $current) {
                    $validator->errors()->add('reverse_id', "述詞「{$relation->name}」已棄用，不能更換反向述詞；請先取消棄用。");
                }
            },
        ];
    }
}
