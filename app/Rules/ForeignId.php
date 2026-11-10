<?php

namespace App\Rules;

use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * 外鍵欄位共用的驗證規則：`bail`、`integer`，再加上對應資料表、排除已軟刪除資料的 `exists`。
 *
 * 每個外鍵欄位都照這個順序寫：先確認是整數，不是就不往下跑，`exists` 才不會拿 `abc`、`1.5`
 * 這類值去查 bigint 欄位（PostgreSQL 會丟錯變成 500，SQLite 不會，見 `docs/conventions/security.md`）。
 * 寫在一個地方，新的外鍵欄位才不會漏掉其中一步。
 *
 * 刻意回傳 Laravel 內建規則組成的陣列，而不是寫成一個 `ValidationRule` 類別：Scramble 認得內建的
 * `integer`，API 文件裡這些欄位的型別才會是 integer；換成自訂規則物件，文件會退成 string。
 * 用法是把陣列展開在其他規則之間：`['required', ...ForeignId::rules('scopes')]`。`bail` 不管放在
 * 第幾個都對整個欄位生效。
 */
final class ForeignId
{
    /**
     * @param  string  $table  外鍵指向的資料表，比對它的 `id` 欄位
     * @param  (Closure(Exists): mixed)|null  $constrain  額外的條件，例如 `fn (Exists $rule) => $rule->whereNull('parent_class')`
     * @return list<string|Exists>
     */
    public static function rules(string $table, ?Closure $constrain = null): array
    {
        // 外鍵指向的資料表都用軟刪除。只寫 exists 的話，已刪除的資料列仍算存在，
        // 寫進去之後就連到畫面上看不到的資料，所以一律排除。
        $exists = Rule::exists($table, 'id')->withoutTrashed();

        if ($constrain) {
            $constrain($exists);
        }

        return ['bail', 'integer', $exists];
    }
}
