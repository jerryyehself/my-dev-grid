<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_19_000000_add_manually_set_at_to_technique_implementation_table：站主指定述詞的時間。
 *
 * 只加一個可 null 的欄位、不回填；down() 只拿掉這一欄，邊的其他欄位不變。SQLite 與 PostgreSQL 都要成立。
 */
class AddManuallySetAtToTechniqueImplementationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_19_000000_add_manually_set_at_to_technique_implementation_table.php';

    /** down() 不能動到的既有欄位。 */
    private const ORIGINAL_COLUMNS = ['id', 'implementation_id', 'technique_id', 'relation_id', 'last_seen_at', 'created_at', 'updated_at'];

    #[TestDox('欄位存在、可以是 NULL；新寫入的邊預設是 NULL')]
    public function test_column_exists_is_nullable_and_defaults_to_null(): void
    {
        $column = collect(Schema::getColumns('technique_implementation'))->keyBy('name')->get('manually_set_at');

        $this->assertNotNull($column, '少了 manually_set_at');
        $this->assertTrue($column['nullable']);

        $this->insertEdge();
        $this->assertNull(DB::table('technique_implementation')->value('manually_set_at'));
    }

    #[TestDox('down() 只拿掉這一欄，邊與其他欄位不變；再跑 up() 欄位回來、不回填')]
    public function test_down_drops_only_the_column(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $this->insertEdge(manuallySetAt: '2026-11-19 03:00:00');
        $this->insertEdge();
        $before = $this->originalColumnsSnapshot();
        $this->assertCount(2, $before);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('technique_implementation', 'manually_set_at'));
        $this->assertTrue(Schema::hasColumns('technique_implementation', self::ORIGINAL_COLUMNS));
        $this->assertEquals($before, $this->originalColumnsSnapshot());

        $migration->up();

        $this->assertTrue(Schema::hasColumn('technique_implementation', 'manually_set_at'));
        $this->assertEquals($before, $this->originalColumnsSnapshot());
        $this->assertSame(0, DB::table('technique_implementation')->whereNotNull('manually_set_at')->count(), 'up() 不回填，重加的欄位都是 NULL');
    }

    private function insertEdge(?string $manuallySetAt = null): void
    {
        $row = [
            'implementation_id' => Implementation::factory()->create()->id,
            'technique_id' => Technique::factory()->create()->id,
            'relation_id' => Relation::factory()->create()->id,
            'last_seen_at' => '2026-11-18 03:17:00',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('technique_implementation')->insert($manuallySetAt === null ? $row : $row + ['manually_set_at' => $manuallySetAt]);
    }

    /** @return list<array<string, mixed>> */
    private function originalColumnsSnapshot(): array
    {
        return DB::table('technique_implementation')
            ->orderBy('id')
            ->get(self::ORIGINAL_COLUMNS)
            ->map(fn (object $row) => (array) $row)
            ->all();
    }
}
