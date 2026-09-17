<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreScopeRequest extends FormRequest
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
            // 這個欄位收的是**父 Scope 的 id**,不是分類號本身——所以欄位名就叫
            // parent_class,跟資料表欄位一致。class_number 由 controller 從父層推導,
            // 不接受呼叫端傳入。
            //
            // 2026-09-17 之前這裡叫 class_number,但收的是 id,而 UpdateScopeRequest
            // 的同名欄位收的是字面分類號——同一個欄位名、兩種語意,表單不能共用 payload。
            // Triple 當初是用繞的:新增表單用 select 挑父層、編輯表單把同一個欄位換成
            // number 直接編(見 AppTripleEdit.vue 的註解),等於把 API 的不一致往前端推。
            'parent_class' => [
                'required',
                Rule::exists('scopes', 'id')->whereNull('parent_class'),
            ],
            'call_number' => 'nullable|numeric',
            'name' => ['required', Rule::unique('scopes')->whereNull('deleted_at')],
            'comment' => 'required|max:100',
            'note' => 'max:255',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
