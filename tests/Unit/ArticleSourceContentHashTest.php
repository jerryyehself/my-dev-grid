<?php

namespace Tests\Unit;

use App\Models\Documentation;
use App\Services\ArticleSourceService;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `ArticleSourceService::importedContentHash()`：判斷站上有沒有改過標題、日期或內文用的雜湊。
 * 經過資料庫的情況（寫入後讀回來還是同一個雜湊）在 `ArticleSourceImportTest`、`ArticleSourceListingTest`。
 */
class ArticleSourceContentHashTest extends TestCase
{
    #[TestDox('欄位之間的邊界不同時，雜湊也不同')]
    public function test_moving_text_between_fields_changes_the_hash(): void
    {
        $this->assertNotSame(
            self::hash(['title' => 'ab', 'body' => 'c']),
            self::hash(['title' => 'a', 'body' => 'bc']),
        );
        $this->assertNotSame(
            self::hash(['title' => "a\n-", 'body' => null]),
            self::hash(['title' => 'a', 'body' => '-']),
        );
    }

    #[TestDox('NULL 與空字串算出不同的雜湊')]
    public function test_null_and_empty_string_differ(): void
    {
        $this->assertNotSame(self::hash(['body' => null]), self::hash(['body' => '']));
        $this->assertNotSame(self::hash(['title' => null]), self::hash(['title' => '']));
    }

    #[TestDox('同一個日期不同寫法算出相同的雜湊，不同時間則不同')]
    public function test_dates_are_compared_by_value(): void
    {
        $this->assertSame(self::hash(['creation_date' => '2026-09-22']), self::hash(['creation_date' => '2026-09-22 00:00:00']));
        $this->assertNotSame(self::hash(['creation_date' => '2026-09-22']), self::hash(['creation_date' => '2026-09-22 00:00:01']));
        $this->assertNotSame(self::hash(['creation_date' => '2026-09-22']), self::hash(['creation_date' => null]));
    }

    #[TestDox('解析不了的日期照原字串算，不會丟例外')]
    public function test_unparseable_date_is_hashed_as_is(): void
    {
        $this->assertSame(self::hash(['creation_date' => 'not a date']), self::hash(['creation_date' => 'not a date']));
        $this->assertNotSame(self::hash(['creation_date' => 'not a date']), self::hash(['creation_date' => 'not a date!']));
    }

    /** @param  array<string, string|null>  $attributes */
    private static function hash(array $attributes): string
    {
        $documentation = new Documentation;
        $documentation->setRawAttributes($attributes + ['title' => '標題', 'creation_date' => '2026-01-01', 'body' => "內文。\n"]);

        return ArticleSourceService::importedContentHash($documentation);
    }
}
