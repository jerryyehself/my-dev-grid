<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 從文章來源 repo 列清單或匯入時失敗。`reason` 是給前端分辨情況的固定代號，`message` 是中文說明。
 *
 * - 503：沒設定（`not_configured`、`misconfigured`）、找不到文章分類（`post_scope_missing`）、
 *   被 GitHub 限流（`github_rate_limited`，帶 `Retry-After`）。
 * - 502：GitHub 回錯誤或回應不對（`github_unauthorized`、`github_forbidden`、`github_not_found`、
 *   `github_unreachable`、`github_error`、`github_bad_response`、`github_tree_truncated`）。
 * - 409：清單過期或站上狀態變了（`stale`、`conflict`），`errors` 以請求欄位（`items.0.action`）為 key。
 *
 * GitHub 的 401／403 不原樣轉給前端：前端把 401 當成自己的登入過期去換 token，
 * 403 會被記成 `authz_fail`；兩種都會讓人以為是這個網站的登入出了問題。
 *
 * 自己負責 render()，呼叫端不用 try/catch。409 是用戶端要重新整理的正常情況，不寫日誌；
 * 5xx 寫一行 warning（代號與 GitHub 的狀態碼，不含 token 與回應內容），
 * 不走預設的 error 回報：token 過期、沒設定這類情況要人去處理設定，不是程式錯誤。
 */
class ArticleSourceException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $reason,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        public readonly ?int $upstreamStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('尚未設定文章來源 repo 或它的 GitHub token。', 503, 'not_configured');
    }

    public static function misconfigured(string $setting): self
    {
        return new self("文章來源的 {$setting} 設定格式不正確。", 503, 'misconfigured');
    }

    public static function postScopeMissing(): self
    {
        return new self('找不到名稱為 post 的分類，無法建立文章。', 503, 'post_scope_missing');
    }

    public static function unreachable(): self
    {
        return new self('連不上 GitHub，請稍後再試。', 502, 'github_unreachable');
    }

    public static function badResponse(): self
    {
        return new self('GitHub 的回應格式不符預期。', 502, 'github_bad_response');
    }

    public static function treeTruncated(): self
    {
        return new self('文章來源 repo 的檔案太多，GitHub 只回傳了部分清單；清單不完整，不列出結果。', 502, 'github_tree_truncated');
    }

    /**
     * GitHub 回了非 2xx。限流依 GitHub 文件的判斷方式：429，或 403 且 `x-ratelimit-remaining` 是 0
     * （主要限制），或 403 帶 `retry-after`（次要限制）。
     */
    public static function fromGitHub(ClientResponse $response): self
    {
        $status = $response->status();
        $remaining = $response->header('x-ratelimit-remaining');
        $retryAfter = $response->header('retry-after');

        if ($status === 429 || ($status === 403 && ($remaining === '0' || $retryAfter !== ''))) {
            $reset = $response->header('x-ratelimit-reset');
            $seconds = match (true) {
                is_numeric($retryAfter) => (int) $retryAfter,
                is_numeric($reset) => max(0, (int) $reset - now()->getTimestamp()),
                default => 60,
            };

            return new self('GitHub API 的請求次數已達上限，請稍後再試。', 503, 'github_rate_limited', retryAfter: $seconds, upstreamStatus: $status);
        }

        return match ($status) {
            401 => new self('文章來源的 GitHub token 無效或已過期。', 502, 'github_unauthorized', upstreamStatus: $status),
            403 => new self('文章來源的 GitHub token 沒有讀取這個 repo 的權限。', 502, 'github_forbidden', upstreamStatus: $status),
            404 => new self('找不到文章來源 repo 或分支，或 token 沒有這個 repo 的權限。', 502, 'github_not_found', upstreamStatus: $status),
            default => new self("GitHub 回應錯誤（HTTP {$status}）。", 502, 'github_error', upstreamStatus: $status),
        };
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function stale(array $errors): self
    {
        return new self('文章來源的內容跟清單上的版本不同，請重新整理清單後再匯入。', 409, 'stale', $errors);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function conflict(array $errors): self
    {
        return new self('部分文章在站上的狀態已經改變，無法照這次的選擇匯入，請重新整理清單。', 409, 'conflict', $errors);
    }

    public function report(): bool
    {
        if ($this->status >= 500) {
            Log::warning("文章來源讀取失敗：{$this->reason}", array_filter([
                'reason' => $this->reason,
                'upstream_status' => $this->upstreamStatus,
            ], fn ($value) => $value !== null));
        }

        // 回 true：Laravel 只在 report() 回 false 時改走預設的回報（寫成 error 並附 stack trace）
        return true;
    }

    public function render(Request $request): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'reason' => $this->reason];
        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        $headers = $this->retryAfter === null ? [] : ['Retry-After' => (string) $this->retryAfter];

        return response()->json($body, $this->status, $headers);
    }
}
