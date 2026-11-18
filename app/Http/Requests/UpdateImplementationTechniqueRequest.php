<?php

namespace App\Http\Requests;

use App\Models\Technique;
use App\Rules\ForeignId;
use App\Rules\RelationFitsLinkedScopes;
use App\Rules\RelationIsNotDeprecated;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateImplementationTechniqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $implementation = $this->route('implementation');
        $technique = $this->route('technique');
        $techniqueId = $technique instanceof Technique ? $technique->id : null;

        return [
            // 規則跟 UpdateImplementationRequest 的 techniques.*.relation_id 一樣；技術 id 在路由上，所以另外傳給規則。
            // 連結表 technique_implementation 的主詞是技術、受詞是實作，所以這裡實作是受詞端
            /** 這條邊要改用的關係 id。兩端實體的分類須符合該關係規定的主詞與受詞分類（子分類也算符合）。不能改成已棄用的關係，這一對原本就是這個關係的除外。 */
            'relation_id' => [
                'required', ...ForeignId::rules('relations'),
                new RelationFitsLinkedScopes(RelationFitsLinkedScopes::OWN_IS_OBJECT, Technique::class, $implementation?->type, $techniqueId),
                new RelationIsNotDeprecated($implementation, 'techniques', $techniqueId),
            ],
        ];
    }
}
