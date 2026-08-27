<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreImplementationRequest extends FormRequest
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
            'sub_title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'url' => 'nullable|url',
            'git_repo_id' => 'nullable|string|max:255',
            'git_repo_created_at' => 'nullable|date',
            'is_visible' => 'nullable|boolean',
            'maintain_status' => 'nullable|boolean',
            'documentations' => 'sometimes|array',
            'documentations.*.id' => 'required|integer|exists:documentations,id',
            'documentations.*.relation_id' => 'required|integer|exists:relations,id',
            'techniques' => 'sometimes|array',
            'techniques.*.id' => 'required|integer|exists:techniques,id',
            'techniques.*.relation_id' => 'required|integer|exists:relations,id',
        ];
    }
}
