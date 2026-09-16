<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required|exists:scopes,id',
            'title' => 'required|string|max:255',
            'url' => 'nullable|url',
            'uri' => 'nullable|string',
            // 內文是 Markdown 原文,不設長度上限:Postgres 的 text 沒有上限,
            // 而一篇文章要多長是寫作的事,不該由驗證規則替作者決定
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
