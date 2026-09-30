<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_09_30_180000_fix_uses_relation_direction：舊資料庫裡 `uses` 是 Technique → Implementation、
 * `used` 是反向，改名成 `usedBy`／`uses`，id 跟邊都不動。
 */
class FixUsesRelationDirectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->migration = require database_path('migrations/2026_09_30_180000_fix_uses_relation_direction.php');
    }

    /** 把 seeder 種出來的新定義退回改名前的樣子，模擬「還沒跑這支 migration 的舊資料庫」。 */
    private function rollBackToOldNames(): void
    {
        $this->migration->down();
        $this->assertSame('12', Relation::where('name', 'uses')->value('class_number'), '前置條件：舊定義的 uses 是 Technique 當主詞');
        $this->assertTrue(Relation::where('name', 'used')->exists());
    }

    public function test_renames_without_touching_ids_or_edges(): void
    {
        $this->rollBackToOldNames();

        $techToImplId = Relation::where('name', 'uses')->value('id');
        $implToTechId = Relation::where('name', 'used')->value('id');

        $project = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id')]);
        $technique = Technique::factory()->create();
        $project->techniques()->attach($technique->id, ['relation_id' => $techToImplId]);
        $edgesBefore = DB::table('technique_implementation')->orderBy('technique_id')->get()->toArray();

        $this->migration->up();

        $this->assertSame('usedBy', Relation::find($techToImplId)->name);
        $this->assertSame('uses', Relation::find($implToTechId)->name);
        $this->assertFalse(Relation::where('name', 'used')->exists());

        // 改名後讀起來是「實作 uses 技術」
        $uses = Relation::with(['subject', 'object'])->find($implToTechId);
        $this->assertSame('Implementation', $uses->subject->name);
        $this->assertSame('Technique', $uses->object->name);
        $this->assertSame('DEPENDS_ON', $uses->source_term);
        $this->assertSame('DEPENDENCY_OF', Relation::find($techToImplId)->source_term);
        $this->assertSame('usesTool', Relation::where('name', 'assisted-by')->value('source_term'));

        // 反向配對還是互指
        $this->assertSame($techToImplId, Relation::find($implToTechId)->reverse_id);
        $this->assertSame($implToTechId, Relation::find($techToImplId)->reverse_id);

        // 邊一筆都沒動
        $this->assertEquals($edgesBefore, DB::table('technique_implementation')->orderBy('technique_id')->get()->toArray());
    }

    public function test_running_twice_does_not_flip_back(): void
    {
        $this->rollBackToOldNames();

        $this->migration->up();
        $this->migration->up();

        $this->assertSame('21', Relation::where('name', 'uses')->value('class_number'));
        $this->assertSame('12', Relation::where('name', 'usedBy')->value('class_number'));
    }

    public function test_fresh_seed_already_matches_migrated_state(): void
    {
        // 新資料庫不經過改名，直接由 seeder 種出來——結果要跟舊資料庫改名後一模一樣
        $this->migration->up();

        $this->assertSame('21', Relation::where('name', 'uses')->value('class_number'));
        $this->assertSame('12', Relation::where('name', 'usedBy')->value('class_number'));
        $this->assertFalse(Relation::where('name', 'used')->exists());
    }

    public function test_down_restores_old_names(): void
    {
        $this->rollBackToOldNames();
        $this->migration->up();
        $this->migration->down();

        $this->assertSame('12', Relation::where('name', 'uses')->value('class_number'));
        $this->assertSame('21', Relation::where('name', 'used')->value('class_number'));
        $this->assertSame('dependsOn', Relation::where('name', 'assisted-by')->value('source_term'));
    }
}
