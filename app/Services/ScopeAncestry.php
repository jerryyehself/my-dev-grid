<?php

namespace App\Services;

use App\Models\Scope;

/**
 * 沿 `scopes.parent_class` 往上找，判斷一個 Scope 是不是另一個 Scope 本身或它的子分類。
 *
 * 一次把整張 scopes 的「id => 父類 id」讀進記憶體，之後每次判斷都不再查資料庫：
 * 分類表只有十幾筆，而一個請求可能帶很多筆連結，逐層查會讓查詢數跟著連結數與階層深度成長。
 *
 * 往上走的過程防兩件事：
 * - 環：資料庫只用外鍵約束 `parent_class`，擋不住 A 的父類是 B、B 的父類又是 A。
 *   用走過的 id 集合偵測，繞回走過的節點就停。
 * - 斷鏈：父類 id 不在表裡（或是 null）就停，視為到頂。
 */
final class ScopeAncestry
{
    /** @param array<int, int|null> $parentById  scope id => 父類 id（沒有父類是 null） */
    public function __construct(private readonly array $parentById) {}

    /**
     * 讀整張 scopes（含已軟刪除的）。
     *
     * 含軟刪除是因為實體的 `type` 只有外鍵約束、沒有排除軟刪除，已刪除分類底下的實體仍然存在；
     * 少了中間那一層，往上的路徑就會斷掉。
     */
    public static function fromDatabase(): self
    {
        return new self(
            Scope::withTrashed()->pluck('parent_class', 'id')
                ->map(fn ($parent) => $parent === null ? null : (int) $parent)
                ->all()
        );
    }

    /** $scopeId 是不是 $requiredId 本身，或沿父類往上走得到它。 */
    public function matches(?int $scopeId, int $requiredId): bool
    {
        $visited = [];

        while ($scopeId !== null && ! isset($visited[$scopeId])) {
            if ($scopeId === $requiredId) {
                return true;
            }

            $visited[$scopeId] = true;
            $scopeId = $this->parentById[$scopeId] ?? null;
        }

        return false;
    }
}
