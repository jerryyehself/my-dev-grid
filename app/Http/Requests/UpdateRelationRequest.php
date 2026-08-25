<?php

namespace App\Http\Requests;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
            'reverse_id' => 'nullable|exists:relations,id',
        ];
    }
}
