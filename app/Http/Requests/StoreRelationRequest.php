<?php

namespace App\Http\Requests;

use App\Rules\ReverseIsAvailable;
use App\Rules\ReverseIsSwapped;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRelationRequest extends FormRequest
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
            'subject_id' => 'required|exists:scopes,id',
            'object_id' => 'required|exists:scopes,id',
            'class_number' => 'required|numeric',
            'call_number' => 'nullable|numeric',
            'name' => 'required|unique:relations',
            'note' => '',
            // reverse_id 必須雙向,而且只能指向還沒配對的關係——見 App\Rules\ReverseIsAvailable
            // 與 Relation::syncReverse() 的註解。建立一對的做法是:先建 A(reverse 留空),
            // 再建 B 並把 reverse_id 指向 A,雙向會自動補齊。
            'reverse_id' => ['nullable', 'exists:relations,id', new ReverseIsAvailable, new ReverseIsSwapped],
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
