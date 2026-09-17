<?php

namespace App\Http\Requests;

use App\Rules\ReverseIsAvailable;
use App\Rules\ReverseIsSwapped;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRelationRequest extends FormRequest
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
            'name' => [
                'required',
                Rule::unique('relations')->ignore($this->relation->id),
            ],
            'note' => 'max:255',
            // 同 StoreRelationRequest。這裡多傳 $this->relation 進去,因為「目標已經指向
            // 正在編輯的這一筆」跟「目標就是自己(對稱關係)」都是合法的,要認得出來。
            'reverse_id' => ['nullable', 'exists:relations,id', new ReverseIsAvailable($this->relation), new ReverseIsSwapped($this->relation)],
        ];
    }
}
