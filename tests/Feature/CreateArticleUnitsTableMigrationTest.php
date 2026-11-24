<?php

namespace Tests\Feature;

use App\Models\Documentation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_23_000001_create_article_units_table：`(article_id, unit_id)` 在 SQLite 與 PostgreSQL 上都要擋住
 * 同一篇文章的重複 ID、放過還沒有 ID 的單位；外鍵的刪除行為；down() 只拿掉這張新表。
 */
class CreateArticleUnitsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_23_000001_create_article_units_table.php';

    /** @return array<string, mixed> */
    private static function row(int $articleId, array $overrides = []): array
    {
        return $overrides + [
            'article_id' => $articleId,
            'unit_id' => 'a',
            'parent_id' => null,
            'role' => 'body',
            'level' => 2,
            'heading' => '標題',
            'content' => '內容',
            'content_hash' => str_repeat('0', 64),
            'removed_at' => null,
            'created_at' => '2026-11-23 00:00:00',
            'updated_at' => '2026-11-23 00:00:00',
        ];
    }

    #[TestDox('同一篇文章的同一個 unit_id 插入第二列會被唯一索引擋下')]
    public function test_duplicate_unit_id_in_one_article_is_rejected(): void
    {
        $article = Documentation::factory()->create();
        DB::table('article_units')->insert(self::row($article->id));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('article_units')->insert(self::row($article->id, ['heading' => '另一個標題']));
    }

    #[TestDox('不同文章可以用同一個 unit_id，同一篇文章可以有好幾個還沒有 ID 的單位')]
    public function test_same_id_in_another_article_and_several_null_ids_coexist(): void
    {
        $first = Documentation::factory()->create();
        $second = Documentation::factory()->create();

        DB::table('article_units')->insert(self::row($first->id));
        DB::table('article_units')->insert(self::row($second->id));
        DB::table('article_units')->insert(self::row($first->id, ['unit_id' => null]));
        DB::table('article_units')->insert(self::row($first->id, ['unit_id' => null]));

        $this->assertSame(4, DB::table('article_units')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function requiredColumns(): iterable
    {
        foreach (['article_id', 'role', 'content', 'content_hash'] as $column) {
            yield $column => [$column];
        }
    }

    #[DataProvider('requiredColumns')]
    #[TestDox('必填欄位不能是 NULL')]
    public function test_required_columns_reject_null(string $column): void
    {
        $article = Documentation::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('article_units')->insert(self::row($article->id, [$column => null]));
    }

    #[TestDox('文章真的刪掉（force delete）時單位跟著刪；軟刪除不影響；父單位刪掉時子單位的指標變成空值')]
    public function test_foreign_keys(): void
    {
        $article = Documentation::factory()->create();
        $parent = DB::table('article_units')->insertGetId(self::row($article->id, ['unit_id' => 'p']));
        $child = DB::table('article_units')->insertGetId(self::row($article->id, ['unit_id' => 'c', 'parent_id' => $parent, 'level' => 3]));

        $article->delete();
        $this->assertSame(2, DB::table('article_units')->count());

        DB::table('article_units')->where('id', $parent)->delete();
        $this->assertNull(DB::table('article_units')->where('id', $child)->value('parent_id'));

        $article->forceDelete();
        $this->assertSame(0, DB::table('article_units')->count());
    }

    #[TestDox('指向不存在的文章會被外鍵擋下')]
    public function test_unknown_article_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('article_units')->insert(self::row(999999));
    }

    #[TestDox('down() 只刪掉 article_units，既有的表都還在；up() 可以再建回來')]
    public function test_down_drops_only_the_article_units_table(): void
    {
        $migration = require database_path(self::MIGRATION);
        $tables = fn () => collect(Schema::getTableListing())->map(fn ($t) => last(explode('.', $t)))->sort()->values();
        $before = $tables();
        $this->assertContains('article_units', $before->all());

        $migration->down();

        $this->assertFalse(Schema::hasTable('article_units'));
        $this->assertEquals($before->reject(fn ($t) => $t === 'article_units')->values(), $tables());

        $migration->up();
        $this->assertTrue(Schema::hasTable('article_units'));
        $this->assertSame(
            ['article_id', 'content', 'content_hash', 'created_at', 'heading', 'id', 'level', 'parent_id', 'removed_at', 'role', 'unit_id', 'updated_at'],
            collect(Schema::getColumnListing('article_units'))->sort()->values()->all(),
        );
    }
}
