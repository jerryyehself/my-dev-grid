<?php

namespace App\Traits;

/**
 * 路由模型綁定只接受合法的正整數 id，其他值一律當作找不到（404）。
 *
 * 主鍵是 bigint，網址上的值卻可以是任何字串。不是正整數、或超過 bigint 上限
 * （9223372036854775807）的值，不該拿去查資料庫：這種值不可能對到任何一列，
 * 讓資料庫去判斷只會多一次查詢，還取決於資料庫怎麼處理型別不符的比較。
 * 在 model 這一層擋，所有綁定這個 model 的路由（現在和之後新增的）都一起受到保護，
 * 不必逐條路由加 `whereNumber()`，也不依賴參數的名稱。
 *
 * 覆寫 `resolveRouteBindingQuery()` 而不是 `resolveRouteBinding()`：前者是
 * `resolveRouteBinding()`、`resolveSoftDeletableRouteBinding()`（路由加了
 * `withTrashed()` 時）與子綁定共用的唯一出口，覆寫一處就涵蓋全部。
 * 代價是不合法的值會多跑一次永遠為空的查詢（`1 = 0`），值本身不會進到查詢裡。
 *
 * 認證在綁定之前：`auth:sanctum` 的優先順序高於 `SubstituteBindings`，
 * 所以沒登入的寫入請求仍是 401，不會因為 id 不合法變成 404。
 *
 * 套用這個 trait 的 model 有 `GuestApiSweepTest` 守著：帶 id 的路由綁定到沒套用的 model 時，
 * 測試會失敗。
 */
trait ResolvesPositiveIntegerRouteKeys
{
    /**
     * 綁定的是主鍵、而且值不是合法的正整數時，回傳永遠查不到任何列的查詢。
     * 綁定別的欄位（例如之後可能加的 slug）不受影響。
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if ($field === $this->getKeyName() && ! $this->isPositiveBigint($value)) {
            return $query->whereRaw('1 = 0');
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * 十進位、沒有正負號與前導零、不超過 PHP 的整數上限（64 位元系統上就是 bigint 的上限）。
     */
    private function isPositiveBigint(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
}
