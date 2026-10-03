<?php

namespace App\Service;

/**
 * RefreshTokenFamilies::rotate() 的結果。controller 依這個決定回應：
 * Rotated → 200＋新 cookie；ConcurrentReplay → 409、不動 cookie；其他 → 401＋清 cookie。
 */
enum RefreshTokenOutcome
{
    /** 換發成功 */
    case Rotated;

    /** 查無此 token、秘密不符、已過期、使用者不存在 */
    case Invalid;

    /**
     * 剛剛（寬限秒數內）才被用掉的 token 又被送來，而且它換出來的那支也已經用掉了
     * （同一支被三個以上的請求同時送來）：不撤銷家族，也不清 cookie——
     * 瀏覽器裡的 cookie 很可能已經是別的請求換出來的新值。
     */
    case ConcurrentReplay;

    /** 早就用掉的 token 被重放：視為被偷，整個家族已撤銷 */
    case ReuseDetected;

    /** 家族從原始登入算起已超過上限（預設 90 天），整個家族已撤銷 */
    case SessionCapReached;
}
