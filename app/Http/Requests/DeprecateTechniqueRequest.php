<?php

namespace App\Http\Requests;

use App\Models\Technique;
use App\Rules\ForeignId;
use App\Rules\TechniqueReplacementIsUsable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeprecateTechniqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
        return true;
    }

    /**
     * 取消停用時 `replaced_by_id` 一定不能帶：「改用」只在停用狀態下存在，取消停用會一起清掉。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $technique = $this->route('technique');

        return [
            /** true 標為停用，false 取消停用。 */
            'deprecated' => 'required|boolean',
            /** 停用後改用的技術 id，可留空（只停用、不取代）。有填時把這個技術合併進去：連結搬過去，舊名變成它的隱藏別名。不能是自己或已停用的技術。取消停用時不能帶。 */
            'replaced_by_id' => [
                Rule::prohibitedIf(fn () => ! $this->boolean('deprecated')),
                'nullable',
                ...ForeignId::rules('techniques'),
                new TechniqueReplacementIsUsable($technique instanceof Technique ? $technique : null),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'replaced_by_id.prohibited' => '取消停用時不能指定改用的技術。',
        ];
    }
}
