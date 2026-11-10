<?php

namespace App\Http\Requests;

use App\Models\Documentation;
use App\Models\Technique;
use App\Rules\ForeignId;
use App\Rules\RelationFitsLinkedScopes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreImplementationRequest extends FormRequest
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
            'sub_title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            // 只收 http／https：這個欄位會被當成可點的連結輸出，不限 scheme 的話
            // javascript:、data: 這類網址存進來，點下去就可能在瀏覽器裡執行
            /** 外部連結，只接受 http 或 https 網址。 */
            'url' => 'nullable|url:http,https',
            'git_repo_id' => 'nullable|string|max:255',
            'git_repo_created_at' => 'nullable|date',
            'is_visible' => 'nullable|boolean',
            'maintain_status' => 'nullable|boolean',
            'documentations' => 'sometimes|array',
            'documentations.*.id' => ['required', ...ForeignId::rules('documentations')],
            // 連結表 documentation_implementation 的主詞是文件、受詞是實作，所以這裡實作是受詞端
            /** 連結用的關係 id。兩端實體的分類須符合該關係規定的主詞與受詞分類（子分類也算符合）。 */
            'documentations.*.relation_id' => [
                'required', ...ForeignId::rules('relations'),
                new RelationFitsLinkedScopes(RelationFitsLinkedScopes::OWN_IS_OBJECT, Documentation::class),
            ],
            'techniques' => 'sometimes|array',
            'techniques.*.id' => ['required', ...ForeignId::rules('techniques')],
            // 連結表 technique_implementation 的主詞是技術、受詞是實作，所以這裡實作是受詞端
            /** 連結用的關係 id。兩端實體的分類須符合該關係規定的主詞與受詞分類（子分類也算符合）。 */
            'techniques.*.relation_id' => [
                'required', ...ForeignId::rules('relations'),
                new RelationFitsLinkedScopes(RelationFitsLinkedScopes::OWN_IS_OBJECT, Technique::class),
            ],
        ];
    }
}
