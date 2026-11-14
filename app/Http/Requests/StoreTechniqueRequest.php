<?php

namespace App\Http\Requests;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Rules\ForeignId;
use App\Rules\RelationFitsLinkedScopes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTechniqueRequest extends FormRequest
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
            'type' => ['required', ...ForeignId::rules('scopes')],
            'title' => 'required|string|max:255',
            'version' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
            'documentations' => 'sometimes|array',
            'documentations.*.id' => ['required', ...ForeignId::rules('documentations')],
            // 連結表 documentation_technique 的主詞是文件、受詞是技術，所以這裡技術是受詞端
            /** 連結用的關係 id。兩端實體的分類須符合該關係規定的主詞與受詞分類（子分類也算符合）。 */
            'documentations.*.relation_id' => [
                'required', ...ForeignId::rules('relations'),
                new RelationFitsLinkedScopes(RelationFitsLinkedScopes::OWN_IS_OBJECT, Documentation::class),
            ],
            'implementations' => 'sometimes|array',
            'implementations.*.id' => ['required', ...ForeignId::rules('implementations')],
            // 連結表 technique_implementation 的主詞是技術、受詞是實作，所以這裡技術是主詞端
            /** 連結用的關係 id。兩端實體的分類須符合該關係規定的主詞與受詞分類（子分類也算符合）。 */
            'implementations.*.relation_id' => [
                'required', ...ForeignId::rules('relations'),
                new RelationFitsLinkedScopes(RelationFitsLinkedScopes::OWN_IS_SUBJECT, Implementation::class),
            ],
        ];
    }
}
