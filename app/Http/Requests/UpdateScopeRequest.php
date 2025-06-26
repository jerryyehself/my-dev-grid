<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScopeRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        return [
            'class_number' => 'bail|required|numeric',
            'call_number' => [
                'nullable',
                'numeric',
                Rule::unique('scopes')
                    ->ignore($this->scope->id)
                    ->where(fn($query) => $query->where('class_number', $this->class_number)),
            ],
            'name' => [
                'bail',
                'required',
                Rule::unique('scopes', 'name')->ignore($this->scope->id),
            ],
            'comment' => 'max:100',
            'note' => 'max:255'
        ];
    }
}
