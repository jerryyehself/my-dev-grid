<?php

namespace App\Http;

use DateTimeInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * my-dev-grid-front 的 refresh token cookie——產生、清除、讀取集中在這裡，
 * 屬性只寫一次，發 cookie 跟清 cookie 不會各寫一份再慢慢漂移（清除時屬性
 * 必須跟設定時一致，尤其是 Partitioned，不然瀏覽器會當成另一顆 cookie）。
 *
 * 屬性與理由：
 * - `__Host-` 前綴：瀏覽器強制要求 Secure、Path=/、不能帶 Domain，
 *   也就是只屬於這個 API 主機本身，子網域不能覆寫它。
 * - HttpOnly：前端 JS（含 XSS 注入的 script）讀不到。
 * - SameSite=None＋Secure：前端（jerrylib.com）跟 API（run.app）是不同 site，
 *   跨 site 的 fetch 要帶得到這顆 cookie 只能用 None。
 * - Partitioned（CHIPS）：cookie 存在「頂層網站＝jerrylib.com」的分區裡，
 *   只有在 jerrylib.com 頁面裡發出的請求才會帶上它。瀏覽器逐步封鎖第三方
 *   cookie 之後，沒分區的跨 site cookie 會被擋；分區 cookie 仍然可用。
 *   副作用：在其他網站（例如攻擊者的頁面）發出的請求不會帶這顆 cookie，
 *   傳統的 CSRF 本來就打不到它。
 *
 * 注意：Laravel 這個版本的 cookie()／CookieJar::make() 沒有 partitioned 參數，
 * 所以直接用 Symfony 的 Cookie::create()，再 withCookie() 掛到回應上。
 * 這顆 cookie 不加密（見 bootstrap/app.php 的 encryptCookies except），
 * 值本身就是套件發的隨機 token，資料庫只存它的 SHA-256。
 */
class RefreshTokenCookie
{
    public const NAME = '__Host-mdg_refresh';

    public static function make(string $value, DateTimeInterface $expiresAt): Cookie
    {
        return self::build($value, $expiresAt);
    }

    /**
     * 讓瀏覽器刪掉這顆 cookie：值清空、過期時間設在過去，其他屬性跟設定時一模一樣。
     */
    public static function forget(): Cookie
    {
        return self::build('', 1);
    }

    /**
     * 讀 cookie，格式不對就當成沒有（回 null）。
     *
     * 套件發的 refresh token 一定是 `{id}|{token}`，id 是資料表的整數主鍵。
     * 這裡先擋格式，是因為套件的 PersonalRefreshToken::findToken() 會把 `|` 前面
     * 那段原封不動丟進 `find($id)`：sqlite 不在意，pgsql 遇到非數字（例如 `abc|x`
     * 或 `|x`）會丟「invalid input syntax for type bigint」變成 500，而這是
     * 任何人都能亂填的 cookie。在本機用 pgsql 跑測試時抓到的。
     */
    public static function read(Request $request): ?string
    {
        $value = $request->cookies->get(self::NAME);

        if (! is_string($value) || preg_match('/^[1-9]\d{0,17}\|[^|\s]{1,255}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * 已經通過 read() 格式檢查的值，取出 `|` 前面的資料列 id。
     */
    public static function idOf(string $value): int
    {
        return (int) strstr($value, '|', true);
    }

    private static function build(string $value, int|DateTimeInterface $expire): Cookie
    {
        return Cookie::create(
            name: self::NAME,
            value: $value,
            expire: $expire,
            path: '/',
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_NONE,
            partitioned: true,
        );
    }
}
