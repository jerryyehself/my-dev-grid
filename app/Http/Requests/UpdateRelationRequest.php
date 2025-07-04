<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdaterelationRequest extends FormRequest
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
            'subject_id' => 'required',
            'object_id' => 'required',
            'class_number' => 'required|numeric',
            'call_number' => 'nullable|numeric',
            'name' => 'required|unique:scopes',
            'comment' => 'max:100',
            'note' => 'max:255'
        ];
    }
}
