<?php

namespace App\Rules;

use App\Models\Relation;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * 新的連結不能用已棄用的述詞；既有的那條照舊放行。
 *
 * 掛在 `<欄位>.*.relation_id` 上。編輯頁存檔時會把整份連結清單重送、用 `sync()` 寫回
 * （`SyncsPivotRelations`），所以「這一組（請求自己的實體、欄位連到的實體、述詞）在連結表裡
 * 已經存在」就是既有的連結，放行；不放行的話，只要有一條既有連結用了棄用的述詞，那筆資料就
 * 再也存不了。新建實體（路由沒有綁定實體）沒有既有連結，用了棄用的述詞一律擋；既有連結從使用中的
 * 述詞「改成」棄用的述詞，連結表裡沒有這一組，一樣擋。
 *
 * 不併進 `ForeignId::rules()`：那是所有外鍵共用的規則，加棄用條件會連 `reverse_id` 等其他欄位一起擋。
 * 不存在或已軟刪除的述詞由欄位自己的規則回報，這裡不重複報。
 */
class RelationIsNotDeprecated implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /**
     * @param  Model|null  $own  更新時路由綁定的實體；新建時是 null
     * @param  string  $link  $own 上連到這個欄位實體的 belongsToMany 關聯名稱，例如 `techniques`
     */
    public function __construct(
        private readonly ?Model $own,
        private readonly string $link,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $relationId = self::asId($value);
        $relation = $relationId === null ? null : Relation::find($relationId);

        if (! $relation || ! $relation->isDeprecated()) {
            return;
        }

        $item = Str::beforeLast($attribute, '.relation_id');
        if ($this->existingLinkUses($relation, self::asId(data_get($this->data, $item.'.id')))) {
            return;
        }

        // 建議的是沿改用找到仍在使用的那一條：直接的改用對象後來也棄用的話，建議它一樣會被擋
        $replacement = $relation->finalReplacement();
        $fail($replacement
            ? "{$item}：述詞「{$relation->name}」已棄用，請改用「{$replacement->name}」。"
            : "{$item}：述詞「{$relation->name}」已棄用，新的連結不能再用它。");
    }

    private function existingLinkUses(Relation $relation, ?int $linkedId): bool
    {
        if ($this->own === null || $linkedId === null) {
            return false;
        }

        return $this->own->{$this->link}()
            ->whereKey($linkedId)
            ->wherePivot('relation_id', $relation->id)
            ->exists();
    }

    /** 整數（或整數字串）才當 id 用，其餘回 null；理由見 `RelationFitsLinkedScopes::asId()`。 */
    private static function asId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }
}
