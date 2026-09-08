<?php

namespace App\Policies;

use App\Models\Technique;
use App\Models\User;

class TechniquePolicy
{
    // ponytail: 這個系統是單一使用者、關閉註冊的封閉系統（v1 範圍只做登入，
    // 見 decision-register.md D-05/D-34）——「有沒有登入」等於「是不是
    // owner 本人」，不需要角色/擁有權那套邏輯，故意先簡化成這樣，等真的
    // 開放多人使用時才需要重新設計。

    /**
     * Determine whether the user can create models.
     */
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(?User $user, Technique $technique): bool
    {
        return $user !== null;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(?User $user, Technique $technique): bool
    {
        return $user !== null;
    }
}
