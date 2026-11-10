<?php

namespace App\Rules;

use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * 外鍵欄位共用的驗證規則：值是整數，而且指到的那一筆存在。
 *
 * 每個外鍵欄位都要同一組規則，少寫一條就會出事，所以集中在這裡，不在每支 FormRequest 各寫一次。
 * 攤開放進欄位的規則陣列，`required`／`nullable` 與欄位自己的規則由呼叫端決定：
 *
 *     'type' => ['required', ...ForeignId::rules('scopes')],
 *     'reverse_id' => ['nullable', ...ForeignId::rules('relations'), new ReverseIsAvailable],
 *
 * 回傳的是 Laravel 內建的規則，刻意不包成一個自訂的規則物件：Scramble 靠內建的 `integer` 推出
 * 欄位型別，包成物件之後 API 文件上這些欄位的型別會從 integer 變成 string。
 *
 * - `bail`：前一條沒過就不再往下跑，後面的自訂規則因此不會拿到不合格的值。它對整個欄位有效，
 *   放在 `required` 後面也一樣。
 * - `integer`：非整數的值（`abc`、`1.5`、超出 bigint 範圍的數字）拿去查 PostgreSQL 的 bigint 欄位
 *   會直接丟錯、變成 500；SQLite 不會報錯，只有 PostgreSQL 看得到。
 * - `exists`：對 `id` 欄位查。
 */
final class ForeignId
{
    /**
     * @param  string  $table  外鍵指向的資料表
     * @param  (Closure(Exists): mixed)|null  $constrain  要對 `exists` 多加條件時用，例如只收頂層分類：
     *                                                    `fn (Exists $rule) => $rule->whereNull('parent_class')`
     * @return list<string|Exists>
     */
    public static function rules(string $table, ?Closure $constrain = null): array
    {
        $exists = Rule::exists($table, 'id');

        if ($constrain !== null) {
            $constrain($exists);
        }

        return ['bail', 'integer', $exists];
    }
}
