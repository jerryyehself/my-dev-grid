<?php

namespace App\Http\Requests;

use App\Models\Documentation;
use App\Models\Implementation;
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
            'type' => 'bail|required|integer|exists:scopes,id',
            'title' => 'required|string|max:255',
            'version' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
            'documentations' => 'sometimes|array',
            'documentations.*.id' => 'required|integer|exists:documentations,id',
            // 連結表 documentation_technique 的主詞是文件、受詞是技術，所以這裡技術是受詞端
            /** 連結用的關係 id。兩端實體的分類須符合該關係規定的主詞與受詞分類（子分類也算符合）。 */
            'documentations.*.relation_id' => [
                'required', 'integer', 'exists:relations,id',
                new RelationFitsLinkedScopes(RelationFitsLinkedScopes::OWN_IS_OBJECT, Documentation::class),
            ],
            'implementations' => 'sometimes|array',
            'implementations.*.id' => 'required|integer|exists:implementations,id',
            // 連結表 technique_implementation 的主詞是技術、受詞是實作，所以這裡技術是主詞端
            /** 連結用的關係 id。兩端實體的分類須符合該關係規定的主詞與受詞分類（子分類也算符合）。 */
            'implementations.*.relation_id' => [
                'required', 'integer', 'exists:relations,id',
                new RelationFitsLinkedScopes(RelationFitsLinkedScopes::OWN_IS_SUBJECT, Implementation::class),
            ],
        ];
    }
}
