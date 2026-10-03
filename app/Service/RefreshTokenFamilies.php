<?php

namespace App\Service;

use App\Models\RefreshToken;
use App\Models\User;
use D076\SanctumRefreshTokens\DTOs\TokensDTO;
use D076\SanctumRefreshTokens\Enums\TokenType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * my-dev-grid-front 的 refresh token：發行、換發（輪替）、撤銷，以「家族」為單位。
 *
 * 套件 d076/sanctum-refresh-tokens 只提供資料表跟 model；換發邏輯在這裡自己做，
 * 因為套件的 refresh() 是「刪掉舊的、發新的」，舊值刪掉之後就認不出重放：
 *
 * - 家族：一次登入（帳密或 OAuth 換發）開一個 family_id，之後每次換發都沿用。
 * - 單次使用＋重放偵測：換發時舊的那支標記 used_at（不刪），之後再被送來就是重放。
 *   重放超過寬限秒數 → 視為被偷，整個家族（所有 refresh token 跟它們的 access token）
 *   一起撤銷。秘密不符的請求不會動到任何家族（不然知道 id 就能把別人登出）。
 * - 寬限秒數（預設 10 秒）內的重放：視為同一個瀏覽器兩個分頁同時換發
 *   （前端用 Web Locks 排隊，不支援的瀏覽器才會撞），回 ConcurrentReplay、不撤銷。
 *   取捨：寬限期內偵測不到重放，但寬限期內的重放也拿不到任何 token；
 *   過了寬限期再被送來一樣會觸發撤銷。
 * - 絕對上限：family_started_at 是原始登入時間，換發不延後；超過
 *   sanctum.refresh_token_max_lifetime（預設 90 天）就撤銷整個家族，要重新登入。
 *   新發的 refresh token 到期時間也不會超過這個上限。
 *
 * 併發（pgsql）：先不加鎖讀出 token 拿到 family_id，再把整個家族的資料列
 * 「依 id 排序」SELECT ... FOR UPDATE。換發跟撤銷都用同一個順序加鎖，不會互相 deadlock；
 * 同一支 token 同時換發時，後到的等先到的 commit，再看到 used_at → ConcurrentReplay。
 * 撤銷時的 DELETE 是加鎖之後另一個 statement，看得到等待期間別人 commit 的新資料列。
 * sqlite 會忽略 FOR UPDATE，但寫入本來就整個資料庫序列化（只用在測試）。
 */
class RefreshTokenFamilies
{
    /**
     * 發一組新的 access＋refresh token。沒帶 family 就開一個新家族（登入時）。
     */
    public function issue(User $user, ?string $familyId = null, ?Carbon $familyStartedAt = null): TokensDTO
    {
        $now = now();
        $familyId ??= (string) Str::uuid();
        $familyStartedAt ??= $now->copy();

        $accessExpiresAt = $now->copy()->addMinutes((int) config('sanctum.expiration'));
        $refreshExpiresAt = $now->copy()->addMinutes((int) config('sanctum.refresh_token_expiration'));
        $familyEndsAt = $this->familyEndsAt($familyStartedAt);
        if ($familyEndsAt->lt($refreshExpiresAt)) {
            $refreshExpiresAt = $familyEndsAt;
        }

        $access = $user->createToken(TokenType::AccessToken->value, ['*'], $accessExpiresAt);

        // 跟套件 HasRefreshTokens::createRefreshToken() 同一種格式：40 字元隨機值＋crc32b，
        // 資料庫只存 SHA-256；cookie 裡是 `{id}|{明文}`。
        $plainText = sprintf(
            '%s%s%s',
            config('sanctum.token_prefix', ''),
            $entropy = Str::random(40),
            hash('crc32b', $entropy),
        );

        $refresh = RefreshToken::query()->forceCreate([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'access_token_id' => $access->accessToken->getKey(),
            'token' => hash('sha256', $plainText),
            'abilities' => ['*'],
            'expires_at' => $refreshExpiresAt,
            'family_id' => $familyId,
            'family_started_at' => $familyStartedAt,
        ]);

        return new TokensDTO(
            model: $user->getMorphClass(),
            token_type: 'Bearer',
            access_token: $access->plainTextToken,
            refresh_token: $refresh->getKey().'|'.$plainText,
            access_token_expires_at: $accessExpiresAt,
            refresh_token_expires_at: $refreshExpiresAt,
            user: $user,
        );
    }

    /**
     * 用 cookie 裡的值（已經通過 RefreshTokenCookie::read() 格式檢查）換一組新的。
     *
     * @return array{0: RefreshTokenOutcome, 1: TokensDTO|null}
     */
    public function rotate(string $value): array
    {
        return DB::transaction(function () use ($value) {
            $peek = $this->findMatching($value);
            if ($peek === null) {
                return [RefreshTokenOutcome::Invalid, null];
            }

            // 加鎖後拿到的是最新 commit 的版本；等待期間被刪掉的就不會在裡面
            $token = $this->lockFamily($peek)->firstWhere('id', $peek->getKey());
            if ($token === null) {
                return [RefreshTokenOutcome::Invalid, null];
            }

            if ($token->used_at !== null) {
                if ($token->used_at->gt(now()->subSeconds($this->graceSeconds()))) {
                    return [RefreshTokenOutcome::ConcurrentReplay, null];
                }

                $this->revokeFamilyOf($token);
                Log::warning('refresh token 重放：整個家族已撤銷', [
                    'user_id' => $token->tokenable_id,
                    'family_id' => $token->family_id,
                    'refresh_token_id' => $token->getKey(),
                ]);

                return [RefreshTokenOutcome::ReuseDetected, null];
            }

            // 上限先於單支到期檢查：最後一支的到期時間被截在上限上，過了上限要清掉整個家族
            $startedAt = $token->family_started_at ?? $token->created_at;
            if (! $this->familyEndsAt($startedAt)->isFuture()) {
                $this->revokeFamilyOf($token);

                return [RefreshTokenOutcome::SessionCapReached, null];
            }

            if ($token->expires_at === null || ! $token->expires_at->isFuture()) {
                return [RefreshTokenOutcome::Invalid, null];
            }

            $user = $token->tokenable;
            if (! $user instanceof User) {
                return [RefreshTokenOutcome::Invalid, null];
            }

            // family_id 是 null 的舊資料列（加家族欄位之前發的）：換發時補上，
            // 之後重放這支也能追到新家族
            $familyId = $token->family_id ?? (string) Str::uuid();

            RefreshToken::query()->whereKey($token->getKey())->update([
                'used_at' => now(),
                'family_id' => $familyId,
                'family_started_at' => $startedAt,
            ]);
            if ($token->access_token_id !== null) {
                PersonalAccessToken::query()->whereKey($token->access_token_id)->delete();
            }

            return [RefreshTokenOutcome::Rotated, $this->issue($user, $familyId, $startedAt->copy())];
        });
    }

    /**
     * 登出用：撤銷 cookie 裡那支 refresh token 所屬的家族（不管它是否已用掉、過期），
     * 以及綁定這支 access token 的家族，最後刪掉這支 access token 本身。
     * 秘密不符的 cookie 不會動到任何東西。
     */
    public function revokeForLogout(?string $cookieValue, ?PersonalAccessToken $accessToken): void
    {
        DB::transaction(function () use ($cookieValue, $accessToken) {
            $tokens = collect();

            if ($cookieValue !== null && ($match = $this->findMatching($cookieValue)) !== null) {
                $tokens->push($match);
            }

            if ($accessToken !== null) {
                $tokens = $tokens->merge(
                    RefreshToken::query()->where('access_token_id', $accessToken->getKey())->get(),
                );
            }

            // 一次撤銷多個家族時依 family_id 排序加鎖，跟其他請求的加鎖順序一致
            $tokens->unique(fn (RefreshToken $t) => $t->family_id ?? 'row:'.$t->getKey())
                ->sortBy(fn (RefreshToken $t) => $t->family_id ?? '')
                ->each(function (RefreshToken $t) {
                    $this->lockFamily($t);
                    $this->revokeFamilyOf($t);
                });

            $accessToken?->delete();
        });
    }

    private function findMatching(string $value): ?RefreshToken
    {
        [$id, $secret] = explode('|', $value, 2);

        /** @var RefreshToken|null $row */
        $row = RefreshToken::query()->find((int) $id);

        return $row !== null && hash_equals($row->token, hash('sha256', $secret)) ? $row : null;
    }

    /**
     * @return Collection<int, RefreshToken>
     */
    private function lockFamily(RefreshToken $token): Collection
    {
        $query = $token->family_id !== null
            ? RefreshToken::query()->where('family_id', $token->family_id)
            : RefreshToken::query()->whereKey($token->getKey());

        return $query->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * 撤銷整個家族：先刪它們綁定的 access token，再刪 refresh token。
     * 兩個 DELETE 都是新的 statement，會包含等鎖期間別人 commit 的新資料列。
     */
    private function revokeFamilyOf(RefreshToken $token): void
    {
        $family = $token->family_id !== null
            ? fn () => RefreshToken::query()->where('family_id', $token->family_id)
            : fn () => RefreshToken::query()->whereKey($token->getKey());

        PersonalAccessToken::query()
            ->whereIn('id', $family()->whereNotNull('access_token_id')->select('access_token_id'))
            ->delete();
        $family()->delete();
    }

    private function familyEndsAt(Carbon $startedAt): Carbon
    {
        return $startedAt->copy()->addMinutes((int) config('sanctum.refresh_token_max_lifetime'));
    }

    private function graceSeconds(): int
    {
        return (int) config('sanctum.refresh_token_reuse_grace_seconds');
    }
}
