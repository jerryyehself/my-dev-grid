<?php

namespace Tests\Feature;

use App\Models\Documentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_23_000002_add_footnotes_and_chunks_to_article_units_table：容器標記、腳註、子片段三欄。
 *
 * 舊版程式寫入時不帶這三欄，要拿到預設值；down() 只拿掉這三欄，單位的其他欄位不變。SQLite 與 PostgreSQL 都要成立。
 */
class AddFootnotesAndChunksToArticleUnitsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_23_000002_add_footnotes_and_chunks_to_article_units_table.php';

    private const NEW_COLUMNS = ['is_container', 'footnotes', 'chunks'];

    /** down() 不能動到的既有欄位。 */
    private const ORIGINAL_COLUMNS = ['id', 'article_id', 'unit_id', 'parent_id', 'role', 'level', 'heading', 'content', 'content_hash', 'position', 'removed_at', 'created_at', 'updated_at'];

    #[TestDox('不帶新欄位寫入（舊版程式的寫法）：容器標記是 false，腳註與子片段是 NULL')]
    public function test_rows_without_the_new_columns_get_defaults(): void
    {
        $this->insertUnit(Documentation::factory()->create()->id);

        $row = DB::table('article_units')->first();
        $this->assertFalse((bool) $row->is_container);
        $this->assertNull($row->footnotes);
        $this->assertNull($row->chunks);

        $columns = collect(Schema::getColumns('article_units'))->keyBy('name');
        $this->assertFalse($columns['is_container']['nullable']);
        $this->assertTrue($columns['footnotes']['nullable']);
        $this->assertTrue($columns['chunks']['nullable']);
    }

    #[TestDox('JSON 欄位原樣存回：key 順序與中文都不變')]
    public function test_json_round_trip_keeps_key_order(): void
    {
        $json = '{"label":"1","content":"[^1]: 中文","defined_in":3}';
        $this->insertUnit(Documentation::factory()->create()->id, ['footnotes' => $json]);

        $this->assertSame(json_decode($json, true), json_decode((string) DB::table('article_units')->value('footnotes'), true));
        $this->assertSame(['label', 'content', 'defined_in'], array_keys(json_decode((string) DB::table('article_units')->value('footnotes'), true)));
    }

    #[TestDox('down() 只拿掉三個新欄位，單位與其他欄位不變；再跑 up() 欄位回來、不回填')]
    public function test_down_drops_only_the_new_columns(): void
    {
        $migration = require database_path(self::MIGRATION);
        $article = Documentation::factory()->create()->id;
        $this->insertUnit($article, ['is_container' => true, 'footnotes' => '[]', 'chunks' => '[]']);
        $this->insertUnit($article, ['unit_id' => 'b']);
        $before = $this->originalColumnsSnapshot();
        $this->assertCount(2, $before);

        $migration->down();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('article_units', $column), "{$column} 應該被拿掉");
        }
        $this->assertTrue(Schema::hasColumns('article_units', self::ORIGINAL_COLUMNS));
        $this->assertEquals($before, $this->originalColumnsSnapshot());

        $migration->up();

        $this->assertTrue(Schema::hasColumns('article_units', self::NEW_COLUMNS));
        $this->assertEquals($before, $this->originalColumnsSnapshot());
        $this->assertSame(0, DB::table('article_units')->where('is_container', true)->count(), 'up() 不回填');
        $this->assertSame(0, DB::table('article_units')->whereNotNull('footnotes')->orWhereNotNull('chunks')->count(), 'up() 不回填');
    }

    private function insertUnit(int $articleId, array $overrides = []): void
    {
        DB::table('article_units')->insert($overrides + [
            'article_id' => $articleId,
            'unit_id' => 'a',
            'role' => 'body',
            'level' => 2,
            'heading' => '標題',
            'content' => '內容',
            'content_hash' => str_repeat('0', 64),
            'position' => 1,
            'created_at' => '2026-11-23 00:00:00',
            'updated_at' => '2026-11-23 00:00:00',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function originalColumnsSnapshot(): array
    {
        return DB::table('article_units')->orderBy('id')->get(self::ORIGINAL_COLUMNS)->map(fn ($row) => (array) $row)->all();
    }
}
