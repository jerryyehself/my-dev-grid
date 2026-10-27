<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateImplementationRequest extends FormRequest
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
            'type' => 'required|exists:scopes,id',
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
            'documentations.*.id' => 'required|integer|exists:documentations,id',
            'documentations.*.relation_id' => 'required|integer|exists:relations,id',
            'techniques' => 'sometimes|array',
            'techniques.*.id' => 'required|integer|exists:techniques,id',
            'techniques.*.relation_id' => 'required|integer|exists:relations,id',
        ];
    }
}
