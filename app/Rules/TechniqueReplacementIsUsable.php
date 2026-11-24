<?php

namespace App\Rules;

use App\Models\Technique;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 停用技術時指定的改用對象，要能真的接手：不能是自己，也不能是已停用的技術。
 *
 * 只能指向「當下仍在使用」的，這保證「改用」不會繞成環（理由同 `ReplacementIsCompatible`）：
 * 環上最後被設定的那一筆 A→B，設定當下 B 已經有改用對象，也就是已停用，矛盾。
 *
 * 不存在或已軟刪除的 id 由欄位自己的規則（`ForeignId::rules()`）回報，這裡不重複報。
 */
class TechniqueReplacementIsUsable implements ValidationRule
{
    public function __construct(private readonly ?Technique $self) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        $target = $id === false ? null : Technique::find($id);

        if (! $target || ! $this->self) {
            return;
        }

        if ($target->id === $this->self->id) {
            $fail("不能改用「{$this->self->label}」自己。");

            return;
        }

        if ($target->isDeprecated()) {
            $fail("技術「{$target->label}」也已停用，請改用仍在使用的技術。");
        }
    }
}
