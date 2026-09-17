<?php

namespace App\Rules;

use App\Models\Relation;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 一對反向關係的主詞受詞必須是對調的。
 *
 * 也就是 `reverse.subject_id === self.object_id` 且 `reverse.object_id === self.subject_id`。
 * 現有 15 條全部滿足：`specs` Doc→Tech 配 `specifiedBy` Tech→Doc、`uses` Tech→Impl 配
 * `used` Impl→Tech；同族的 `requires` Tech→Tech 對調是恆等，`accompanies` 自指也是恆等，
 * 所以同一條規則對三種情況一體適用，不用開特例。
 *
 * 這不只是「比較整齊」——**Scope 的連鎖刪除默默依賴這條規則**。`Scope::booted()` 在軟刪除
 * 時會一併刪掉 subjectOf/objectOf 的關係；一對關係若主詞受詞對調，就必然共用同一組 Scope，
 * 因此一定整對被刪。實測軟刪除 `Technique` 會讓 15 條剩 7 條、懸空 0 條。
 *
 * 反過來造一對「互指但主詞受詞不相干」的關係（在這條規則之前是建得出來的）：
 *
 *     BadFwd(Documentation→sourcesite) <-> BadRev(document→post)
 *
 * 軟刪除 Scope `Documentation` 之後 BadFwd 被刪、BadRev 存活，而 BadRev 的 reverse_id
 * 就懸空了。所以少了這條規則，連鎖刪除的正確性只是碰巧成立。
 */
class ReverseIsSwapped implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function __construct(private ?Relation $self = null) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $target = Relation::find($value);

        // 不存在的 id 由 exists 規則負責回報。
        if (! $target) {
            return;
        }

        // 送進來的主詞受詞優先；更新時若請求沒帶，才退回現有值。
        $subjectId = (int) ($this->data['subject_id'] ?? $this->self?->subject_id);
        $objectId = (int) ($this->data['object_id'] ?? $this->self?->object_id);

        // 對稱關係（自己就是自己的反向）只有在主詞受詞相同時才說得通——
        // A accompanies B 等價於 B accompanies A，前提是兩端是同一個 Scope。
        if ($this->self && $target->id === $this->self->id) {
            if ($subjectId !== $objectId) {
                $fail('只有主詞與受詞相同的關係才能把自己當作反向；這一筆的主詞與受詞不同。');
            }

            return;
        }

        if ($target->subject_id !== $objectId || $target->object_id !== $subjectId) {
            $fail("反向關係的主詞受詞必須跟這一筆對調。「{$target->name}」不是對調的那一條，不能當作反向。");
        }
    }
}
