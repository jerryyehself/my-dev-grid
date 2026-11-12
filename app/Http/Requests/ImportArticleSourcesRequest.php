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
            /** 狀態是 `conflict`（站上改過內文）時，要設成 true 才會用來源的內容覆蓋。 */
            'items.*.overwrite_site_edits' => ['sometimes', 'boolean'],
        ];
    }
}
