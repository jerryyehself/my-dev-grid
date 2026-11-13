<?php

namespace Tests\Unit;

use App\Services\NomenResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `NomenResolver::normalize()`：比對用的鍵。同一個名稱的不同寫法要得到同一個鍵，
 * 不同的名稱（C、C++、C#）不能被合成同一個鍵。
 */
class NomenNormalizeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function spellings(): iterable
    {
        yield '空白分隔的版本' => ['Vue 3', 'vue3'];
        yield '連字號分隔的版本' => ['vue-3', 'vue3'];
        yield '黏在一起的版本' => ['Vue3', 'vue3'];
        yield '點' => ['Next.js', 'nextjs'];
        yield '全形英數與全形空白' => ['Ｖｕｅ　３', 'vue3'];
        yield '頭尾空白' => ['  Laravel  ', 'laravel'];
        yield '中英混寫' => ['SPA 深層網址 fallback', 'spa深層網址fallback'];
        yield '套件 scope 的符號' => ['@nuxtjs/i18n', 'nuxtjsi18n'];
        yield 'C' => ['C', 'c'];
        yield 'C++' => ['C++', 'c++'];
        yield 'C#' => ['C#', 'c#'];
        yield '全形的加號' => ['Ｃ＋＋', 'c++'];
        yield '只有標點' => ['——', ''];
        yield '不合法的 UTF-8' => ["\xff", ''];
    }

    #[DataProvider('spellings')]
    #[TestDox('正規化後的比對鍵')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, NomenResolver::normalize($input));
    }
}
