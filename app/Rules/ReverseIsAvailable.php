<?php

namespace App\Rules;

use App\Models\Relation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * reverse_id 只能指向「還沒跟別人配對」的關係。
 *
 * 沒有這道檢查的話，把 reverse_id 指向一條已經成對的關係會有兩種結果，兩種都不對：
 * 不同步就留下單向的壞配對，同步就會默默把對方原本的伴侶踢掉變成孤兒。所以寧可
 * 在這裡明確擋下來，要求先解除既有配對，不要靜默改動使用者沒有指名的那一筆。
 *
 * 允許的三種情況：
 * - 目標的 reverse_id 是 null（還沒配對）
 * - 目標的 reverse_id 已經指向正在編輯的這一筆（本來就是一對，重送同樣的值）
 * - 目標就是自己（對稱關係，例如 `accompanies`）
 */
class ReverseIsAvailable implements ValidationRule
{
    public function __construct(private ?Relation $self = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $target = Relation::find($value);

        // 不存在的 id 由 exists 規則負責回報，這裡不重複報一次。
        if (! $target) {
            return;
        }

        if ($this->self && $target->id === $this->self->id) {
            return;
        }

        if (is_null($target->reverse_id)) {
            return;
        }

        if ($this->self && $target->reverse_id === $this->self->id) {
            return;
        }

        $fail("關係「{$target->name}」已經跟另一條關係配對，不能同時當這一筆的反向。請先解除既有配對。");
    }
}
