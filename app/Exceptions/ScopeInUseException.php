<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * 刪除一個分類（Scope）時，連帶要刪的述詞仍被引用，丟出。
 *
 * 刪分類會連帶軟刪除主體或客體是它的述詞，而軟刪除不會處理引用那些述詞的資料：
 * 邊（四張連結表）會變成指著一條查不到的述詞，分類以外的述詞的 reverse_id、replaced_by_id 會指著已刪除的那條。
 * 判斷規則跟 RelationInUseException 同一套（刪單一述詞時用的），所以訊息與回應形狀也對齊，
 * 差別只在對象是「這個分類底下的述詞合計」，並且同一分類內互為反向的述詞不算引用。
 *
 * 跟 RelationInUseException 一樣由例外自己 render()，並且 report() 回 false，
 * 因為這是用戶端送了不能做的請求，不是伺服器的錯。
 */
class ScopeInUseException extends RuntimeException
{
    public function __construct(
        public readonly string $scopeName,
        public readonly int $linkCount,
        public readonly int $inverseCount,
        public readonly int $replacementCount,
    ) {
        parent::__construct($this->describe());
    }

    /** 給使用者看的訊息：只列出數量大於 0 的那幾種引用。 */
    private function describe(): string
    {
        $parts = array_filter([
            $this->linkCount > 0 ? "被 {$this->linkCount} 筆邊使用" : null,
            $this->inverseCount > 0 ? "被 {$this->inverseCount} 條分類以外的述詞設為反向關係" : null,
            $this->replacementCount > 0 ? "被 {$this->replacementCount} 條分類以外已棄用的述詞設為改用對象" : null,
        ]);

        return "分類「{$this->scopeName}」底下的述詞仍被引用，不能刪除：".implode('、', $parts).'。請先移除這些引用。';
    }

    public function report(): bool
    {
        return false;
    }

    /**
     * 422。刪除沒有輸入欄位可以掛錯誤，所以 `errors` 用 `scope` 當 key，
     * 跟一般驗證錯誤同一種形狀；各種數量另外附在 `reference_counts`，
     * 給不想解析訊息文字的呼叫端。
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['scope' => [$this->getMessage()]],
            'reference_counts' => [
                'links' => $this->linkCount,
                'inverse_of' => $this->inverseCount,
                'replacement_of' => $this->replacementCount,
            ],
        ], 422);
    }
}
