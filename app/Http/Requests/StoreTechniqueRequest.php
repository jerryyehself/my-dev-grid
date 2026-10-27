<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTechniqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
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
