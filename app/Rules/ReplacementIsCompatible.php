<?php

namespace App\Rules;

use App\Models\Relation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 棄用述詞時指定的改用對象，要能真的拿來取代原述詞。
 *
 * 擋下四種情況：
 * - 改用自己，或改用自己的反向。
 * - 改用一條已棄用的述詞。只能指向「當下仍在使用」的，這也保證「改用」不會繞成環：
 *   假設有環，看環上最後被設定的那一條 A→B，設定當下 B 已經有改用對象，也就是已棄用，矛盾。
 *   所以沿著「改用」往下找（`Relation::resolveByName()`）一定會停。
 * - 主詞或受詞的分類不同。照著「改用」把邊換過去時，兩端的分類才會一樣合法。
 * - 有沒有反向、是不是對稱（反向是自己）不同。棄用時反向那條一起改用改用對象的反向
 *   （`Relation::deprecate()`），形狀不同就找不到對應的那一條。
 *
 * 不存在或已軟刪除的 id 由欄位自己的規則（`ForeignId::rules()`）回報，這裡不重複報。
 */
class ReplacementIsCompatible implements ValidationRule
{
    public function __construct(private readonly ?Relation $self) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        $target = $id === false ? null : Relation::find($id);

        if (! $target || ! $this->self) {
            return;
        }

        $self = $this->self;

        if ($target->id === $self->id || ($self->reverse_id !== null && $target->id === (int) $self->reverse_id)) {
            $fail("不能改用「{$self->name}」自己或它的反向述詞。");

            return;
        }

        if ($target->isDeprecated()) {
            $fail("述詞「{$target->name}」也已棄用，請改用仍在使用的述詞。");

            return;
        }

        if ((int) $target->subject_id !== (int) $self->subject_id || (int) $target->object_id !== (int) $self->object_id) {
            $fail("改用的述詞「{$target->name}」主詞與受詞的分類要跟「{$self->name}」相同。");

            return;
        }

        if (self::shape($target) !== self::shape($self)) {
            $fail("改用的述詞「{$target->name}」要跟「{$self->name}」一樣有反向述詞（或一樣是對稱的、一樣沒有反向），反向那條才能一起改用。");
        }
    }

    /** 反向的形狀：沒有反向、對稱（反向是自己），或有另一條反向。反向已軟刪除時當成沒有。 */
    private static function shape(Relation $relation): string
    {
        if ($relation->reverse_id === null) {
            return 'none';
        }

        if ((int) $relation->reverse_id === $relation->id) {
            return 'symmetric';
        }

        return Relation::whereKey($relation->reverse_id)->exists() ? 'paired' : 'none';
    }
}
