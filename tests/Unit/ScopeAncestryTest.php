<?php

namespace Tests\Unit;

use App\Services\ScopeAncestry;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 沿 `parent_class` 往上找的規則本身，不碰資料庫：傳進去的就是「id => 父類 id」。
 *
 * 樹長這樣：1 是頂層，2、3 是 1 的子分類，4 是 2 的子分類；5 是另一棵樹的頂層。
 */
class ScopeAncestryTest extends TestCase
{
    private function tree(): ScopeAncestry
    {
        return new ScopeAncestry([1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => null]);
    }

    #[TestDox('分類就是要求的分類時算符合')]
    public function test_scope_matches_itself(): void
    {
        $this->assertTrue($this->tree()->matches(2, 2));
        $this->assertTrue($this->tree()->matches(1, 1));
    }

    #[TestDox('子分類與更深層的子分類都算符合上層')]
    public function test_descendants_match_their_ancestors(): void
    {
        $this->assertTrue($this->tree()->matches(2, 1));
        $this->assertTrue($this->tree()->matches(4, 2));
        $this->assertTrue($this->tree()->matches(4, 1));
    }

    #[TestDox('父類不算符合子分類，平輩與別棵樹也不算')]
    public function test_ancestors_siblings_and_other_trees_do_not_match(): void
    {
        $this->assertFalse($this->tree()->matches(1, 2));
        $this->assertFalse($this->tree()->matches(2, 4));
        $this->assertFalse($this->tree()->matches(2, 3));
        $this->assertFalse($this->tree()->matches(4, 5));
        $this->assertFalse($this->tree()->matches(5, 1));
    }

    #[TestDox('沒有分類時不符合任何要求')]
    public function test_null_scope_matches_nothing(): void
    {
        $this->assertFalse($this->tree()->matches(null, 1));
    }

    #[TestDox('父類不在表裡時停在斷鏈處，不當作符合')]
    public function test_a_missing_parent_ends_the_walk(): void
    {
        $ancestry = new ScopeAncestry([1 => null, 2 => 99]);

        $this->assertFalse($ancestry->matches(2, 1));
        $this->assertTrue($ancestry->matches(2, 99));
        // 查的分類自己不在表裡：只有它本身算符合
        $this->assertFalse($ancestry->matches(42, 1));
        $this->assertTrue($ancestry->matches(42, 42));
    }

    #[TestDox('父類繞成環時會停下來，不會無限迴圈')]
    public function test_a_cycle_ends_the_walk(): void
    {
        // 1 -> 2 -> 3 -> 1，沒有頂層
        $cycle = new ScopeAncestry([1 => 2, 2 => 3, 3 => 1, 7 => null]);

        $this->assertFalse($cycle->matches(1, 7));
        // 環裡的每一個都能走到環裡的其他節點
        $this->assertTrue($cycle->matches(1, 3));
        $this->assertTrue($cycle->matches(3, 2));
    }

    #[TestDox('自己是自己的父類時會停下來')]
    public function test_a_self_parent_ends_the_walk(): void
    {
        $selfParent = new ScopeAncestry([1 => 1, 2 => null]);

        $this->assertFalse($selfParent->matches(1, 2));
        $this->assertTrue($selfParent->matches(1, 1));
    }
}
