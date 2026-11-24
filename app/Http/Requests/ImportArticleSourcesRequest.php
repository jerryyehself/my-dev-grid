<?php

namespace App\Http\Requests;

use App\Services\ArticleSourceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportArticleSourcesRequest extends FormRequest
{
    /** 一次最多匯入幾篇。每篇要向 GitHub 多下載一次檔案。 */
    public const MAX_ITEMS = 50;

    public function authorize(): bool
    {
        // 授權由 controller 的 $this->authorize() 交給 Policy，這裡不重複判斷。
        return true;
    }

    /**
     * `documentation_id` 不用 `ForeignId::rules()`：要連結的文章存不存在、能不能連，
     * 由 `ArticleSourceService` 在 transaction 裡鎖住那一列之後再判斷（已刪除的同樣回 422），
     * 這裡只先確認它是整數，不合法的值不會拿去查資料庫。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** 清單回應裡的 `commit_sha`。匯入的內容固定是這個版本。 */
            'commit_sha' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            /** 清單項目的 `key`（原稿 front matter 的 slug）。 */
            'items.*.key' => ['required', 'string', 'max:255', 'distinct'],
            /** 清單項目的 `blob_sha`。跟這個版本裡的檔案不同時回 409。 */
            'items.*.expected_blob_sha' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/'],
            /** `create` 新增草稿、`update` 更新已連結的文章、`link` 只把來源記到既有文章上（不改內文）。 */
            'items.*.action' => ['required', 'string', Rule::in([
                ArticleSourceService::ACTION_CREATE,
                ArticleSourceService::ACTION_UPDATE,
                ArticleSourceService::ACTION_LINK,
            ])],
            /** 要連結的文章 id，只有 `link` 要帶。 */
            'items.*.documentation_id' => [
                'bail',
                'required_if:items.*.action,'.ArticleSourceService::ACTION_LINK,
                'prohibited_unless:items.*.action,'.ArticleSourceService::ACTION_LINK,
                'integer',
                'min:1',
                'distinct',
            ],
            /** 狀態是 `conflict`（站上改過標題、日期或內文）時，要設成 true 才會用來源的內容覆蓋。 */
            'items.*.overwrite_site_edits' => ['sometimes', 'boolean'],
            // 沒帶就不知道使用者同意覆蓋的是哪一版站上內容，屬於請求格式不對，回 422 而不是 409：
            // 重新整理清單也補不出這個欄位，要改的是用戶端
            /** 清單項目的 `site_version`。`overwrite_site_edits` 為 true 時必填；站上的標題、日期或內文在列出清單之後又改過時回 409。 */
            'items.*.expected_site_version' => ['required_if_accepted:items.*.overwrite_site_edits', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            // 只收 SHA 格式：值要跟站上記的 commit 逐字比對，大寫或縮寫的 SHA 都對不上，不如在這裡就退回
            /** 只有 `update` 可以帶。判斷不出這次的 `commit_sha` 跟站上那篇上次匯入時的 commit 誰新時（來源 repo 的歷史改寫過、或 GitHub 已經查不到那個 commit），匯入回 409 並在 `unverified_commits` 附上站上記的 commit；確認要用這個版本更新，就把那個值原樣放在這裡。 */
            'items.*.confirm_unverified_base' => [
                'bail',
                'prohibited_unless:items.*.action,'.ArticleSourceService::ACTION_UPDATE,
                'nullable',
                'string',
                'regex:/^[0-9a-f]{40}$/',
            ],
        ];
    }
}
