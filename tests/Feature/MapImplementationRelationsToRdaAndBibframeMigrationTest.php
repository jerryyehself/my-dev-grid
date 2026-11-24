<?php

namespace Tests\Feature;

use App\Models\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_24_000002_map_implementation_relations_to_rda_and_bibframe：accompanies、precedes、
 * succeeds 的外部詞彙從 Tillett (1987) 分類名改成 RDA／BIBFRAME 屬性，只改三欄，id 與配對不動。
 */
class MapImplementationRelationsToRdaAndBibframeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->migration = require database_path('migrations/2026_11_24_000002_map_implementation_relations_to_rda_and_bibframe.php');
    }

    /** 這三筆目前的 source_vocabulary／source_term／note，key 是述詞名稱。 */
    private function current(): array
    {
        return Relation::whereIn('name', array_keys($this->migration::NEW))
            ->get()
            ->mapWithKeys(fn (Relation $r) => [$r->name => [
                'source_vocabulary' => $r->source_vocabulary,
                'source_term' => $r->source_term,
                'note' => $r->note,
            ]])
            ->all();
    }

    /**
     * 新資料庫跑 seeder 後三列的值：這支 migration 的 NEW，疊上之後 2026_11_24_000003 把
     * accompanies 從 P10103 改成 P30027 的結果（seeder 種的是最終值）。
     */
    private function seeded(): array
    {
        $amendment = require database_path('migrations/2026_11_24_000003_remap_accompanies_to_rda_manifestation_property.php');

        return ['accompanies' => $amendment::NEW] + $this->migration::NEW;
    }

    #[TestDox('新資料庫跑 seeder 後三列就是新值')]
    public function test_fresh_seed_has_new_values(): void
    {
        $this->assertEquals($this->seeded(), $this->current());
    }

    #[TestDox('舊資料庫（Tillett 舊值）跑 migration 後改成新值，id 與反向配對不動')]
    public function test_up_converts_old_database(): void
    {
        $this->migration->down();
        $this->assertEquals($this->migration::OLD, $this->current(), '前置條件：還原成 Tillett 舊值');

        $before = Relation::orderBy('id')->get()->map->only(['id', 'name', 'class_number', 'call_number', 'subject_id', 'object_id', 'reverse_id'])->all();
        $othersBefore = Relation::whereNotIn('name', array_keys($this->migration::NEW))->orderBy('id')->get()->map->getAttributes()->all();

        $this->migration->up();

        $this->assertEquals($this->migration::NEW, $this->current());
        $this->assertEquals($before, Relation::orderBy('id')->get()->map->only(['id', 'name', 'class_number', 'call_number', 'subject_id', 'object_id', 'reverse_id'])->all());
        $this->assertEquals($othersBefore, Relation::whereNotIn('name', array_keys($this->migration::NEW))->orderBy('id')->get()->map->getAttributes()->all(), '其他述詞一筆都不能動');

        $accompanies = Relation::where('name', 'accompanies')->first();
        $this->assertSame($accompanies->id, $accompanies->reverse_id, 'accompanies 仍然對稱');
        $this->assertSame(Relation::where('name', 'succeeds')->value('id'), Relation::where('name', 'precedes')->value('reverse_id'));
    }

    #[TestDox('回滾還原成 Tillett 舊值')]
    public function test_down_restores_tillett_values(): void
    {
        $this->migration->down();

        $this->assertEquals($this->migration::OLD, $this->current());
        foreach (Relation::whereIn('name', ['accompanies', 'precedes', 'succeeds'])->get() as $relation) {
            $this->assertSame('tillett1987', $relation->source_vocabulary);
        }
        $this->assertSame('Accompanying', Relation::where('name', 'accompanies')->value('source_term'));
        $this->assertSame('Sequential', Relation::where('name', 'precedes')->value('source_term'));
        $this->assertSame('Sequential', Relation::where('name', 'succeeds')->value('source_term'));
        $this->assertSame('Reverse of precedes.', Relation::where('name', 'succeeds')->value('note'));
    }

    #[TestDox('已經是新值（新資料庫）時重複執行不改動')]
    public function test_up_on_new_values_is_noop(): void
    {
        $updatedAt = Relation::whereIn('name', array_keys($this->migration::NEW))->pluck('updated_at', 'name')->all();

        $this->migration->up();
        $this->migration->up();

        $this->assertEquals($this->seeded(), $this->current());
        $this->assertEquals($updatedAt, Relation::whereIn('name', array_keys($this->migration::NEW))->pluck('updated_at', 'name')->all());
    }

    #[TestDox('後台改過詞彙的列不被蓋掉')]
    public function test_up_leaves_admin_edited_rows_alone(): void
    {
        $this->migration->down();
        DB::table('relations')->where('name', 'precedes')->update(['source_vocabulary' => 'custom', 'source_term' => 'x']);

        $this->migration->up();

        $this->assertSame('custom', Relation::where('name', 'precedes')->value('source_vocabulary'));
        $this->assertSame('x', Relation::where('name', 'precedes')->value('source_term'));
        $this->assertSame('rda', Relation::where('name', 'accompanies')->value('source_vocabulary'));
        $this->assertSame('bibframe', Relation::where('name', 'succeeds')->value('source_vocabulary'));
    }

    #[TestDox('空的 relations 表跑 up／down 不報錯')]
    public function test_runs_on_empty_table(): void
    {
        DB::table('relations')->delete();

        $this->migration->up();
        $this->migration->down();

        $this->assertSame(0, DB::table('relations')->count());
    }

    #[TestDox('name 與詞彙都對得上但是已軟刪除的列也一併改（資料一致）')]
    public function test_soft_deleted_rows_follow(): void
    {
        $this->migration->down();
        Relation::where('name', 'succeeds')->first()->delete();

        $this->migration->up();

        $this->assertSame('precededBy', DB::table('relations')->where('name', 'succeeds')->value('source_term'));
    }
}
