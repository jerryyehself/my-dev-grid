<?php

namespace App\Rules;

use App\Models\Relation;
use App\Models\Scope;
use App\Services\ScopeAncestry;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * 連結用的述詞，兩端實體的分類（Scope）要符合述詞規定的主詞與受詞。
 *
 * 掛在 `<欄位>.*.relation_id` 上。述詞的 `subject_id`／`object_id` 都指向 Scope，實體則以
 * `type` 欄位連到 Scope；沒有這道檢查的話，只要述詞存在，就能拿一條「A 類 → B 類」的述詞把
 * 兩個不屬於 A、B 的實體連起來，資料庫照樣寫入。
 *
 * 分類相符的標準：實體的分類就是述詞規定的分類，或沿 `scopes.parent_class` 往上找得到它
 * （子分類算符合，見 `ScopeAncestry`）。
 *
 * 哪一端是主詞、哪一端是受詞，由連結表決定，不看是從哪個實體的請求寫進來的：
 * `documentation_technique` 與 `documentation_implementation` 的主詞是文件，
 * `technique_implementation` 的主詞是技術（圖譜與 `RelationEdgeQuery` 都這樣讀）。
 * 所以呼叫端要說明「請求自己的實體」站在哪一端，另一端就是欄位連到的實體。
 *
 * 兩端都不符時會各報一則；不存在的述詞或實體由 `exists` 規則負責回報，這裡不重複報。
 */
class RelationFitsLinkedScopes implements DataAwareRule, ValidationRule
{
    /** 請求自己的實體是主詞端，欄位連到的實體是受詞端。 */
    public const OWN_IS_SUBJECT = 'subject';

    /** 請求自己的實體是受詞端，欄位連到的實體是主詞端。 */
    public const OWN_IS_OBJECT = 'object';

    /** @var array<string, mixed> */
    protected array $data = [];

    private ?ScopeAncestry $ancestry = null;

    /**
     * @param  self::OWN_IS_SUBJECT|self::OWN_IS_OBJECT  $ownEnd  請求自己的實體站在哪一端
     * @param  class-string<Model>  $linkedModel  欄位連到的實體 model
     * @param  int|null  $currentOwnType  更新時，路由綁定之實體目前的 type；請求沒帶 `type` 時才用
     */
    public function __construct(
        private readonly string $ownEnd,
        private readonly string $linkedModel,
        private readonly ?int $currentOwnType = null,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $relation = is_numeric($value) ? Relation::withTrashed()->find($value) : null;

        // 連到已軟刪除的述詞，`exists:relations,id` 目前會放行，這裡照樣檢查它的兩端。
        if (! $relation) {
            return;
        }

        $item = Str::beforeLast($attribute, '.relation_id');
        $linkedId = data_get($this->data, $item.'.id');
        $linked = is_numeric($linkedId) ? $this->linkedModel::withTrashed()->find($linkedId) : null;
        $ownType = $this->ownType();

        if (! $linked || $ownType === null) {
            return;
        }

        $ownIsSubject = $this->ownEnd === self::OWN_IS_SUBJECT;

        // 端 => [述詞規定的分類, 這一端實體的分類, 這一端是不是請求自己的實體]
        $ends = [
            '主詞' => [$relation->subject_id, $ownIsSubject ? $ownType : $linked->type, $ownIsSubject],
            '受詞' => [$relation->object_id, $ownIsSubject ? $linked->type : $ownType, ! $ownIsSubject],
        ];

        $ancestry = $this->ancestry ??= ScopeAncestry::fromDatabase();
        $names = null;

        foreach ($ends as $end => [$requiredId, $actualId, $isOwn]) {
            if ($ancestry->matches($actualId === null ? null : (int) $actualId, (int) $requiredId)) {
                continue;
            }

            $names ??= Scope::withTrashed()->pluck('name', 'id');
            $who = $isOwn ? '這筆資料' : '所連結的 '.class_basename($linked)."（id {$linked->id}）";
            $required = $names[$requiredId] ?? "#{$requiredId}";
            $actual = $actualId === null ? '未分類' : ($names[$actualId] ?? "#{$actualId}");

            $fail("{$item}：關係「{$relation->name}」的{$end}端必須屬於分類「{$required}」（含其子分類），但{$who}的分類是「{$actual}」。");
        }
    }

    /**
     * 請求自己的實體在這次寫入之後的分類。
     *
     * 請求帶了 `type` 就以它為準（更新時分類也可能一起改）；沒帶才退回路由綁定之實體目前的值。
     * `type` 不是數字時回 null，讓 `type` 自己的規則去報錯。
     */
    private function ownType(): ?int
    {
        if (array_key_exists('type', $this->data)) {
            return is_numeric($this->data['type']) ? (int) $this->data['type'] : null;
        }

        return $this->currentOwnType;
    }
}
