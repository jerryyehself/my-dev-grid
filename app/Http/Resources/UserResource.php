<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 登入相關回應（登入、換發 token、建立登入狀態、取得目前使用者）裡的使用者。
 *
 * 欄位明確列在這裡，不直接回傳 `User` model：直接回傳時只靠 `User::$hidden`
 * 擋掉密碼，`users` 表之後新增的欄位會自動出現在回應裡。要對外多給一個欄位，
 * 就在這裡加，並更新對應的測試。
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
