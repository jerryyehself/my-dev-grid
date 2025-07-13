<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject_id' => 'required',
            'object_id' => 'required',
            'class_number' => 'required|numeric',
            'call_number' => 'nullable|numeric',
            'name' => 'required|unique:relations',
            'note' => ''
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'subject_id' => $this->input('subject'),
            'object_id' => $this->input('object'),
        ]);
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
