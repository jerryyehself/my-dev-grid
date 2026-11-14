<?php

namespace App\Rules;

use App\Models\Scope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * 分類的子類號（`call_number`）在新增與更新共用的驗證規則。
 *
 * 兩邊的規則要一樣：新增沒有檢查重複的話，同一個類號底下可以建出兩筆相同的子類號，之後編輯其中一筆、
 * 子類號不改，會因為撞到另一筆而回 422，變成「建得出來、存不回去」。
 *
 *     'call_number' => ScopeCallNumber::rules($this->input('parent_class')),
 *     'call_number' => ScopeCallNumber::rules($this->input('parent_class'), $this->scope?->id),
 *
 * - 型別與長度：可留空、數字、最多兩位（`max_digits` 比位數，`max` 比的是數值大小）。
 * - 重複：同一個類號底下不能有相同的子類號，不同類號底下可以。類號由父分類推導，不讀呼叫端傳來的值。
 *   已軟刪除的分類不算重複（跟名稱的唯一性一致）。更新時用 `$ignoreId` 排除自己。
 */
final class ScopeCallNumber
{
    /**
     * @param  mixed  $parentClass  請求裡的 `parent_class`（父分類的 id）
     * @param  int|null  $ignoreId  更新時，正在編輯的那一筆的 id
     * @return list<string|Unique>
     */
    public static function rules(mixed $parentClass, ?int $ignoreId = null): array
    {
        return [
            'nullable',
            'numeric',
            'max_digits:2',
            Rule::unique('scopes', 'call_number')
                ->ignore($ignoreId)
                ->withoutTrashed()
                ->where(fn ($query) => $query->where('class_number', self::parentClassNumber($parentClass))),
        ];
    }

    /**
     * 父分類的類號；`parent_class` 不是整數、或找不到父分類時是 null。
     *
     * 不是整數的值不能拿去查（PostgreSQL 會丟 bigint 的語法錯誤），`parent_class` 自己的規則會回 422。
     */
    private static function parentClassNumber(mixed $parentClass): ?string
    {
        $parentId = filter_var($parentClass, FILTER_VALIDATE_INT);

        return $parentId === false ? null : Scope::find($parentId)?->class_number;
    }
}
