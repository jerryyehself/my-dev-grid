<?php

namespace App\Http\Requests;

use App\Models\Relation;
use App\Rules\ForeignId;
use App\Rules\ReplacementIsCompatible;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeprecateRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
        return true;
    }

    /**
     * 取消棄用時 `replaced_by_id` 一定不能帶：「改用」只在棄用狀態下存在，取消棄用會一起清掉。
     *
     * 同步與文章匯入依名稱取用的述詞（`Relation::SYSTEM_PREDICATES`，以及它們目前的改用對象）棄用時，
     * 程式會改用改用對象寫新的邊，所以一定要指定；沒有改用對象的話，程式就沒有述詞可用。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $relation = $this->route('relation');

        return [
            /** true 標為棄用，false 取消棄用。 */
            'deprecated' => 'required|boolean',
            /** 棄用後改用的述詞 id，可留空。主詞與受詞的分類要跟這條述詞相同，有沒有反向、是不是對稱也要相同，而且不能是已棄用的述詞。同步或匯入文章會自動使用的述詞必填。取消棄用時不能帶。 */
            'replaced_by_id' => [
                Rule::requiredIf(fn () => $this->boolean('deprecated') && $relation instanceof Relation && $relation->isUsedByProgram()),
                Rule::prohibitedIf(fn () => ! $this->boolean('deprecated')),
                'nullable',
                ...ForeignId::rules('relations'),
                new ReplacementIsCompatible($relation),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'replaced_by_id.required' => '這條述詞（或它的反向）會被同步或文章匯入自動使用，棄用時必須指定改用的述詞。',
            'replaced_by_id.prohibited' => '取消棄用時不能指定改用的述詞。',
        ];
    }
}
