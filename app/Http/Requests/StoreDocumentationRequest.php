<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentationRequest extends FormRequest
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
            // 只收 http／https：這個欄位會被當成可點的連結輸出，不限 scheme 的話
            // javascript:、data: 這類網址存進來，點下去就可能在瀏覽器裡執行
            /** 外部連結，只接受 http 或 https 網址。 */
            'url' => 'nullable|url:http,https',
            // 跟 url 一樣只收 http／https；目前沒有資料、前端也沒讀，之後再評估要不要刪掉這個欄位
            /** 外部連結，只接受 http 或 https 網址。 */
            'uri' => 'nullable|url:http,https',
            // 內文是 Markdown 原文,不設長度上限:Postgres 的 text 沒有上限,
            // 而一篇文章要多長是寫作的事,不該由驗證規則替作者決定
            /** 文件內文（Markdown 原文），沒有長度上限。 */
            'body' => 'nullable|string',
            'note' => 'nullable|string',
            'status' => 'nullable|integer',
            'creation_date' => 'nullable|date',
            'techniques' => 'sometimes|array',
            'techniques.*.id' => 'required|integer|exists:techniques,id',
            'techniques.*.relation_id' => 'required|integer|exists:relations,id',
            'implementations' => 'sometimes|array',
            'implementations.*.id' => 'required|integer|exists:implementations,id',
            'implementations.*.relation_id' => 'required|integer|exists:relations,id',
        ];
    }
}
