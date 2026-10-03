<?php

namespace Tests\Unit;

use App\Models\Technique;
use PHPUnit\Framework\TestCase;

/**
 * 技術顯示名稱的規則本身。前端 my-dev-grid-front 的 `src/api/techniqueLabel.ts` 是同一條規則，
 * 兩邊的案例刻意一樣。
 */
class TechniqueLabelTest extends TestCase
{
    public function test_version_is_appended_after_a_space(): void
    {
        $this->assertSame('Vue 3', Technique::labelFrom('Vue', '3'));
    }

    public function test_empty_version_leaves_the_title_alone(): void
    {
        $this->assertSame('Vue', Technique::labelFrom('Vue', null));
        $this->assertSame('Vue', Technique::labelFrom('Vue', ''));
        // 只有空白也算沒填（Laravel 的 filled()），不會變成「Vue   」
        $this->assertSame('Vue', Technique::labelFrom('Vue', '  '));
    }

    public function test_accessor_uses_the_same_rule(): void
    {
        $this->assertSame('Nuxt 3', (new Technique(['title' => 'Nuxt', 'version' => '3']))->label);
        $this->assertSame('Nuxt', (new Technique(['title' => 'Nuxt']))->label);
    }
}
