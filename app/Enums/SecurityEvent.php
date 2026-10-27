<?php

namespace App\Enums;

use Psr\Log\LogLevel;

/**
 * 安全與稽核事件的名稱（issue #103），取自 OWASP Logging Vocabulary Cheat Sheet：
 * https://cheatsheetseries.owasp.org/cheatsheets/Logging_Vocabulary_Cheat_Sheet.html
 *
 * 名稱照抄 vocabulary（小寫底線），日誌等級原則上也照它；跟它不一樣的地方在
 * level() 註明理由。每個事件在什麼時候記、帶哪些欄位，見 docs/observability.md
 * 「安全與稽核事件」一節。
 */
enum SecurityEvent: string
{
    case LoginSuccess = 'authn_login_success';
    case LoginFail = 'authn_login_fail';
    case TokenCreated = 'authn_token_created';
    case TokenRevoked = 'authn_token_revoked';
    case TokenReuse = 'authn_token_reuse';
    case AuthzFail = 'authz_fail';
    case AuthzAdmin = 'authz_admin';
    case RateLimitExceeded = 'excess_rate_limit_exceeded';
    case SessionExpired = 'session_expired';
    case SessionLogout = 'session_logout';

    /**
     * PSR-3 等級。CloudLoggingFormatter 會轉成 Cloud Logging 的 severity。
     */
    public function level(): string
    {
        return match ($this) {
            self::LoginSuccess,
            self::TokenCreated,
            self::TokenRevoked,
            self::SessionExpired,
            self::SessionLogout => LogLevel::INFO,

            self::LoginFail,
            self::AuthzAdmin,
            self::RateLimitExceeded => LogLevel::WARNING,

            // vocabulary 寫 CRITICAL。這裡的 403 絕大多數是 EnsureFrontendOrigin 擋掉
            // 沒帶正確 Origin 的請求（curl、爬蟲、設定錯的前端），不是有人越權；
            // 記成 CRITICAL 會讓之後設的「ERROR 以上就通知」告警一直被誤觸發。
            self::AuthzFail => LogLevel::WARNING,

            // 同 vocabulary：超過寬限秒數的重放代表 refresh token 很可能被偷。
            self::TokenReuse => LogLevel::CRITICAL,
        };
    }
}
