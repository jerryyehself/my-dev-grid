<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * 刪除一條仍被引用的述詞（Relation）時丟出。
 *
 * 軟刪除不會連帶處理引用它的資料：邊（四張連結表）會變成指著一條查不到的述詞，
 * 另一條述詞的 reverse_id 會指著已刪除的那條。所以有任何一種引用就拒絕刪除，
 * 兩種引用的數量分開報，讓呼叫端知道要先清掉什麼。
 *
 * 跟 RelationLockedException 一樣由例外自己 render()，並且 report() 回 false，
 * 因為這是用戶端送了不能做的請求，不是伺服器的錯。
 */
class RelationInUseException extends RuntimeException
{
    public function __construct(
        public readonly string $relationName,
        public readonly int $linkCount,
        public readonly int $inverseCount,
    ) {
        parent::__construct($this->describe());
    }

    /** 給使用者看的訊息：只列出數量大於 0 的那幾種引用。 */
    private function describe(): string
    {
        $parts = array_filter([
            $this->linkCount > 0 ? "被 {$this->linkCount} 筆邊使用" : null,
            $this->inverseCount > 0 ? "被 {$this->inverseCount} 條述詞設為反向關係" : null,
        ]);

        return "述詞「{$this->relationName}」仍被引用，不能刪除：".implode('、', $parts).'。請先移除這些引用。';
    }

    public function report(): bool
    {
        return false;
    }

    /**
     * 422。刪除沒有輸入欄位可以掛錯誤，所以 `errors` 用 `relation` 當 key，
     * 跟一般驗證錯誤同一種形狀；兩種數量另外附在 `reference_counts`，
     * 給不想解析訊息文字的呼叫端。
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['relation' => [$this->getMessage()]],
            'reference_counts' => [
                'links' => $this->linkCount,
                'inverse_of' => $this->inverseCount,
            ],
        ], 422);
    }
}
