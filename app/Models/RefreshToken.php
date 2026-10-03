<?php

namespace App\Models;

use D076\SanctumRefreshTokens\Models\PersonalRefreshToken;
use Illuminate\Support\Carbon;

/**
 * personal_refresh_tokens 的 model，多了家族欄位的型別轉換
 * （見 2026_10_03_120000 那支 migration 跟 App\Service\RefreshTokenFamilies）。
 *
 * 跟套件的 PersonalRefreshToken 同一張表。刻意只用 query builder 的批次
 * update／delete，不呼叫單筆 model 的 delete()——套件掛在 PersonalRefreshToken
 * 上的 observer 不會對子類別觸發，撤銷 access token 由 RefreshTokenFamilies 自己做。
 *
 * @property string|null $family_id
 * @property Carbon|null $family_started_at
 * @property Carbon|null $used_at
 */
class RefreshToken extends PersonalRefreshToken
{
    protected $casts = [
        'abilities' => 'array',
        'expires_at' => 'datetime',
        'family_started_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
