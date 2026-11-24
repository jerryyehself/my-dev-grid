<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_24_000000_create_res_labels_table：唯一鍵 `(res_type, res_id, normalized)` 在 SQLite 與 PostgreSQL 上
 * 都要擋得住同一個 res 存兩次同一個字串，又不能擋到同形異義（`normalized` 本身不唯一）；down() 只拿掉這張新表。
 *
 * 這裡直接寫資料表，驗證的是資料庫這一層；model 的檢查在 `ResLabelTest`。
 */
class CreateResLabelsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_24_000000_create_res_labels_table.php';

    /** @return array<string, mixed> */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'res_type' => 'technique',
            'res_id' => 1,
            'label' => 'token',
            'normalized' => 'token',
            'label_type' => 'alt',
            'locale' => null,
            'note' => null,
        ];
    }

    #[TestDox('欄位齊全；locale、note 可以是 NULL，其他不行')]
    public function test_columns_and_nullability(): void
    {
        $columns = collect(Schema::getColumns('res_labels'))->keyBy('name');

        $nullable = ['locale', 'note', 'created_at', 'updated_at'];
        foreach (['id', 'res_type', 'res_id', 'label', 'normalized', 'label_type', 'locale', 'note', 'created_at', 'updated_at'] as $name) {
            $this->assertTrue($columns->has($name), "少了 {$name}");
            $this->assertSame(in_array($name, $nullable, true), $columns[$name]['nullable'], "{$name} 的 nullable 不對");
        }
    }

    #[TestDox('同一個 res 存第二個正規化後相同的字串會被唯一索引擋下')]
    public function test_the_same_res_cannot_store_the_same_key_twice(): void
    {
        DB::table('res_labels')->insert(self::row(['label' => 'Token']));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('res_labels')->insert(self::row(['label' => 'token', 'label_type' => 'hidden']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rowsThatDifferInOneKeyColumn(): iterable
    {
        yield '同形異義：另一個技術也叫 token' => [['res_id' => 2]];
        yield '種類不同：同 id 的專案' => [['res_type' => 'implementation']];
        yield '字串不同' => [['label' => '存取權杖', 'normalized' => '存取權杖']];
    }

    #[DataProvider('rowsThatDifferInOneKeyColumn')]
    #[TestDox('唯一鍵的三欄有一欄不同就是另一列')]
    public function test_rows_that_differ_in_one_key_column_coexist(array $overrides): void
    {
        DB::table('res_labels')->insert(self::row());
        DB::table('res_labels')->insert(self::row($overrides));

        $this->assertSame(2, DB::table('res_labels')->count());
    }

    #[TestDox('只差 locale 仍算重複：名稱的身分只看 res 與字串')]
    public function test_locale_is_not_part_of_the_key(): void
    {
        DB::table('res_labels')->insert(self::row(['locale' => 'en']));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('res_labels')->insert(self::row(['locale' => 'zh-Hant']));
    }

    #[TestDox('down() 拿掉 res_labels，其他表不受影響；再跑 up() 可以重建')]
    public function test_down_drops_only_the_new_table(): void
    {
        $migration = require database_path(self::MIGRATION);
        $this->seed();
        $techniques = DB::table('techniques')->count();
        $nomens = DB::table('nomens')->count();
        DB::table('res_labels')->insert(self::row());

        $migration->down();

        $this->assertFalse(Schema::hasTable('res_labels'));
        $this->assertSame($techniques, DB::table('techniques')->count());
        $this->assertTrue(Schema::hasTable('nomens'));
        $this->assertSame($nomens, DB::table('nomens')->count());

        $migration->up();

        $this->assertTrue(Schema::hasTable('res_labels'));
        $this->assertSame(0, DB::table('res_labels')->count());
    }
}
