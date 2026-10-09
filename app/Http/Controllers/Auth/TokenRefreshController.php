<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\RefreshTokenRejectedException;
use App\Http\Controllers\Auth\Concerns\IssuesFrontendTokens;
use App\Http\Controllers\Controller;
use App\Http\RefreshTokenCookie;
use App\Service\RefreshTokenFamilies;
use App\Service\RefreshTokenOutcome;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * my-dev-grid-front 的 refresh token 端點（「重新整理後維持登入」）。
 *
 * - refresh：用 cookie 裡的 refresh token 換一組新的 access＋refresh token。
 * - session：OAuth 登入完成後，把回呼帶回來的短效 token 換成一組正式的
 *   access＋refresh token，並在這次回應設定 refresh cookie。
 *
 * 為什麼 OAuth 回呼不直接設 cookie、要多一支 session（CHIPS 的陷阱）：
 * refresh cookie 是 Partitioned，存進哪個分區由「設定當下的頂層網站」決定。
 * OAuth 回呼（/auth/token/{provider}/callback）是使用者被 Google／LINE 導回
 * run.app 的「頂層導覽」，那時頂層網站是 run.app，cookie 會存進 run.app 自己
 * 的分區；之後在 jerrylib.com 頁面裡發的 fetch 讀的是 jerrylib.com 分區，
 * 永遠拿不到它。所以回呼照舊用 `#token=` 把短效 token 交給前端，由前端在
 * jerrylib.com 頁面裡 fetch 這支 session，cookie 才會落在正確的分區。
 */
class TokenRefreshController extends Controller
{
    use IssuesFrontendTokens;

    /**
     * 不帶 Authorization header，只看 refresh cookie。換發、重放偵測、90 天上限都在
     * App\Service\RefreshTokenFamilies::rotate()，這裡只把結果對應成回應：
     *
     * - 換發成功 → 200＋新 cookie（包含寬限秒數內、從剛換出來的那支接著換發的情況）。
     * - 同一支剛被用掉、它換出來的那支也用掉了（寬限秒數內）→ 409，不動 cookie：
     *   瀏覽器裡的 cookie 多半已經是別的請求換出來的新值，清掉會把它也登出。
     *   前端收到 409 會稍等再用（新的）cookie 重試一次。
     * - 其他（無效、過期、重放→家族已撤銷、超過 90 天上限）→ 401＋清 cookie。
     */
    #[Response(200, '換發成功：`data` 是使用者，`token` 是 access token，`expires_in` 是它還剩幾秒；refresh token 另外以 cookie 設定。', type: 'array{data: \\App\\Models\\User, token: string, expires_in: int|null}')]
    #[Response(401, 'refresh token 無效、過期、已被撤銷，或超過 90 天上限；同時清除 refresh cookie。')]
    public function refresh(Request $request, RefreshTokenFamilies $families): JsonResponse
    {
        $value = RefreshTokenCookie::read($request);

        if ($value === null) {
            $this->rejectRefresh();
        }

        [$outcome, $tokens] = $families->rotate($value);

        return match ($outcome) {
            RefreshTokenOutcome::Rotated => $this->tokenPairResponse($tokens),
            RefreshTokenOutcome::ConcurrentReplay => abort(409, '登入狀態剛更新過，請重試。'),
            default => $this->rejectRefresh(),
        };
    }

    /**
     * 只接受 OAuth 回呼發的短效 token（名稱是 TokenSocialAuthController::CALLBACK_TOKEN_NAME），
     * 一般 access token 不能拿來換 refresh token——不然偷到一支 15 分鐘的 access
     * token，就能用 curl 自己填 Origin 打這支，換成 30 天的 refresh token。
     * 換發時把回呼那支 token 撤銷，回呼 token 只能用一次。
     */
    #[Response(200, '換發成功：`data` 是使用者，`token` 是 access token，`expires_in` 是它還剩幾秒；refresh token 另外以 cookie 設定。', type: 'array{data: \\App\\Models\\User, token: string, expires_in: int|null}')]
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        if (! $current instanceof PersonalAccessToken
            || $current->name !== TokenSocialAuthController::CALLBACK_TOKEN_NAME) {
            abort(403, '這個 token 不能用來建立登入狀態。');
        }

        // 「單次使用」要用刪除的筆數判斷，不能只看 auth:sanctum 驗證時 token 還在：
        // 兩個同時送來的請求都會先通過驗證，套件的 deleteCurrentTokens() 不看刪了幾筆，
        // 兩邊都會各換出一組（pgsql 實測同一支回呼 token 並發 6 次換出 4 組）。
        // 在 transaction 裡直接 DELETE 這一列：pgsql 上後到的 DELETE 會等先到的 commit，
        // 之後刪到 0 筆 → 這裡當成 token 已經用掉，回 401，不發新的一組。
        $tokens = DB::transaction(function () use ($user, $current) {
            $claimed = PersonalAccessToken::query()->whereKey($current->getKey())->delete();

            if ($claimed !== 1) {
                return null;
            }

            return $this->issueTokenPair($user);
        });

        if ($tokens === null) {
            throw new AuthenticationException;
        }

        return $this->tokenPairResponse($tokens);
    }

    private function rejectRefresh(): never
    {
        throw new RefreshTokenRejectedException;
    }
}
