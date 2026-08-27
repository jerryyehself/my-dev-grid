<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTechniqueRequest extends FormRequest
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
            'type' => 'required|exists:scopes,id',
            'title' => 'required|string|max:255',
            'version' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
            'documentations' => 'sometimes|array',
            'documentations.*.id' => 'required|integer|exists:documentations,id',
            'documentations.*.relation_id' => 'required|integer|exists:relations,id',
            'implementations' => 'sometimes|array',
            'implementations.*.id' => 'required|integer|exists:implementations,id',
            'implementations.*.relation_id' => 'required|integer|exists:relations,id',
        ];
    }
}
