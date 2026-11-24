<?php

namespace Tests\Feature;

use App\Models\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_24_000003_remap_accompanies_to_rda_manifestation_property：accompanies 的外部詞彙
 * 從 RDA P10103（Work）改成 P30027（Manifestation），只改 source_term 與 note，其他列與欄位不動。
 */
class RemapAccompaniesToRdaManifestationPropertyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->migration = require database_path('migrations/2026_11_24_000003_remap_accompanies_to_rda_manifestation_property.php');
    }

    /** accompanies 目前的 source_vocabulary／source_term／note。 */
    private function current(): array
    {
        return Relation::where('name', 'accompanies')->first()->only(['source_vocabulary', 'source_term', 'note']);
    }

    #[TestDox('新資料庫跑 seeder 後 accompanies 就是新值，跟 migration 的 NEW 一致')]
    public function test_fresh_seed_matches_migration_new(): void
    {
        $this->assertSame($this->migration::NEW, $this->current());
    }

    #[TestDox('舊資料庫（P10103）跑 migration 後改成 P30027，再回滾還原成 P10103')]
    public function test_up_then_down_on_old_database(): void
    {
        $this->migration->down();
        $this->assertSame($this->migration::OLD, $this->current(), '前置條件：還原成 P10103');
        $this->assertSame('P10103', Relation::where('name', 'accompanies')->value('source_term'));

        $before = Relation::orderBy('id')->get()->map->only(['id', 'name', 'class_number', 'call_number', 'subject_id', 'object_id', 'reverse_id'])->all();
        $othersBefore = Relation::where('name', '!=', 'accompanies')->orderBy('id')->get()->map->getAttributes()->all();

        $this->migration->up();

        $this->assertSame($this->migration::NEW, $this->current());
        $this->assertSame('P30027', Relation::where('name', 'accompanies')->value('source_term'));
        $this->assertEquals($before, Relation::orderBy('id')->get()->map->only(['id', 'name', 'class_number', 'call_number', 'subject_id', 'object_id', 'reverse_id'])->all());
        $this->assertEquals($othersBefore, Relation::where('name', '!=', 'accompanies')->orderBy('id')->get()->map->getAttributes()->all(), '其他述詞（含 precedes／succeeds）一筆都不能動');

        $accompanies = Relation::where('name', 'accompanies')->first();
        $this->assertSame($accompanies->id, $accompanies->reverse_id, 'accompanies 仍然對稱');

        $this->migration->down();

        $this->assertSame($this->migration::OLD, $this->current());
    }

    #[TestDox('不是 P10103 的列（後台改過）跑 up 不被蓋掉')]
    public function test_up_leaves_other_values_alone(): void
    {
        DB::table('relations')->where('name', 'accompanies')->update(['source_vocabulary' => 'custom', 'source_term' => 'x', 'note' => 'edited']);

        $this->migration->up();

        $this->assertSame(['source_vocabulary' => 'custom', 'source_term' => 'x', 'note' => 'edited'], $this->current());
    }

    #[TestDox('同樣是 rda 但 source_term 是別的值（近似的詞）跑 up 不被蓋掉')]
    public function test_up_leaves_near_miss_alone(): void
    {
        DB::table('relations')->where('name', 'accompanies')->update(['source_term' => 'P10104']);

        $this->migration->up();

        $this->assertSame('P10104', Relation::where('name', 'accompanies')->value('source_term'));
    }

    #[TestDox('不在 P30027 的列跑 down 不被蓋掉')]
    public function test_down_leaves_other_values_alone(): void
    {
        DB::table('relations')->where('name', 'accompanies')->update(['source_vocabulary' => 'custom', 'source_term' => 'x', 'note' => 'edited']);

        $this->migration->down();

        $this->assertSame(['source_vocabulary' => 'custom', 'source_term' => 'x', 'note' => 'edited'], $this->current());
    }

    #[TestDox('只改 accompanies：別的述詞即使剛好是 rda／P10103 也不動')]
    public function test_only_touches_accompanies(): void
    {
        DB::table('relations')->where('name', 'precedes')->update(['source_vocabulary' => 'rda', 'source_term' => 'P10103']);
        $this->migration->down();

        $this->migration->up();

        $this->assertSame('P10103', Relation::where('name', 'precedes')->value('source_term'));
        $this->assertSame('P30027', Relation::where('name', 'accompanies')->value('source_term'));
    }

    #[TestDox('已經是新值（新資料庫）時重複執行不改動')]
    public function test_up_on_new_values_is_noop(): void
    {
        $updatedAt = Relation::where('name', 'accompanies')->value('updated_at');

        $this->migration->up();
        $this->migration->up();

        $this->assertSame($this->migration::NEW, $this->current());
        $this->assertEquals($updatedAt, Relation::where('name', 'accompanies')->value('updated_at'));
    }

    #[TestDox('空的 relations 表跑 up／down 不報錯')]
    public function test_runs_on_empty_table(): void
    {
        DB::table('relations')->delete();

        $this->migration->up();
        $this->migration->down();

        $this->assertSame(0, DB::table('relations')->count());
    }
}
