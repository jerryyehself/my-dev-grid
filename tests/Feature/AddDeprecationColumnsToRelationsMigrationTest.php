<?php

namespace Tests\Feature;

use App\Models\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_18_000000_add_deprecation_columns_to_relations_table：述詞的棄用時間與改用對象。
 *
 * 只加兩個可 null 的欄位、不回填；replaced_by_id 有外鍵（SQLite 靠 `foreign_key_constraints`）。
 * down() 只拿掉這兩欄，述詞列與其他欄位不變。SQLite 與 PostgreSQL 都要成立（CI 兩種都跑）。
 */
class AddDeprecationColumnsToRelationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_18_000000_add_deprecation_columns_to_relations_table.php';

    /** down() 不能動到的既有欄位。 */
    private const ORIGINAL_COLUMNS = [
        'id', 'subject_id', 'object_id', 'parent_class', 'class_number', 'call_number', 'name', 'note',
        'source_vocabulary', 'source_term', 'reverse_id', 'deleted_at', 'created_at', 'updated_at',
    ];

    #[TestDox('兩欄都存在、可以是 NULL；新建的述詞兩欄都是 NULL')]
    public function test_columns_exist_are_nullable_and_default_to_null(): void
    {
        $columns = collect(Schema::getColumns('relations'))->keyBy('name');

        foreach (['deprecated_at', 'replaced_by_id'] as $name) {
            $this->assertTrue($columns->has($name), "少了 {$name}");
            $this->assertTrue($columns[$name]['nullable'], "{$name} 應該可以是 NULL");
        }

        $relation = Relation::factory()->create()->fresh();
        $this->assertNull($relation->deprecated_at);
        $this->assertNull($relation->replaced_by_id);
    }

    #[TestDox('replaced_by_id 指到不存在的述詞時寫入失敗（外鍵）')]
    public function test_replaced_by_id_has_a_foreign_key(): void
    {
        $relation = Relation::factory()->create();

        $this->assertTrue(
            collect(Schema::getForeignKeys('relations'))->contains(fn (array $fk) => $fk['columns'] === ['replaced_by_id'] && $fk['foreign_table'] === 'relations'),
            'replaced_by_id 應該有指向 relations 的外鍵',
        );

        try {
            // 包在巢狀 transaction（savepoint）裡：PostgreSQL 上一句失敗會讓整個 transaction 失效
            DB::transaction(fn () => DB::table('relations')->where('id', $relation->id)->update(['replaced_by_id' => 999999]));
            $this->fail('指到不存在的述詞應該被外鍵擋下');
        } catch (QueryException) {
            $this->assertNull($relation->fresh()->replaced_by_id);
        }
    }

    #[TestDox('被設為改用對象的述詞不能被硬刪除（外鍵不設 onDelete，預設擋刪）')]
    public function test_hard_deleting_a_replacement_is_blocked(): void
    {
        $old = Relation::factory()->create();
        $replacement = Relation::factory()->create();
        $old->forceFill(['deprecated_at' => now(), 'replaced_by_id' => $replacement->id])->save();

        try {
            DB::transaction(fn () => DB::table('relations')->where('id', $replacement->id)->delete());
            $this->fail('被改用的述詞被硬刪除，改用對象會默默消失');
        } catch (QueryException) {
            $this->assertSame($replacement->id, $old->fresh()->replaced_by_id);
        }
    }

    #[TestDox('down() 只拿掉兩欄，述詞列與其他欄位不變；再跑 up() 也一樣')]
    public function test_down_drops_only_the_two_columns(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);

        [$a, $b] = Relation::factory()->count(2)->create()->all();
        $b->update(['reverse_id' => $a->id]);
        Relation::factory()->create()->delete();
        $deprecated = Relation::factory()->create();
        $deprecated->forceFill(['deprecated_at' => now(), 'replaced_by_id' => $a->id])->save();
        $before = $this->originalColumnsSnapshot();
        $this->assertCount(4, $before);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('relations', 'deprecated_at'));
        $this->assertFalse(Schema::hasColumn('relations', 'replaced_by_id'));
        $this->assertTrue(Schema::hasColumns('relations', self::ORIGINAL_COLUMNS));
        $this->assertEquals($before, $this->originalColumnsSnapshot());
        $this->assertFalse(
            collect(Schema::getForeignKeys('relations'))->contains(fn (array $fk) => $fk['columns'] === ['replaced_by_id']),
            '外鍵要一起拿掉',
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumns('relations', ['deprecated_at', 'replaced_by_id']));
        $this->assertEquals($before, $this->originalColumnsSnapshot());
        $this->assertSame(0, DB::table('relations')->whereNotNull('deprecated_at')->count(), 'up() 不回填，重加的欄位都是 NULL');
    }

    /** @return list<array<string, mixed>> */
    private function originalColumnsSnapshot(): array
    {
        return DB::table('relations')
            ->orderBy('id')
            ->get(self::ORIGINAL_COLUMNS)
            ->map(fn (object $row) => (array) $row)
            ->all();
    }
}
