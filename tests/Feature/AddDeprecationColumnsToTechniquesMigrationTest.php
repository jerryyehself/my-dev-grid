<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_24_000001_add_deprecation_columns_to_techniques_table：技術的停用時間與改用對象。
 *
 * 只加兩個可 null 的欄位、不回填；replaced_by_id 有指回 techniques 的外鍵。down() 只拿掉這兩欄，
 * 技術列與其他欄位不變。SQLite 與 PostgreSQL 都要成立（CI 兩種都跑）。
 *
 * 連到技術的三張連結表也不能被動到。SQLite 加或拿掉外鍵會重建整張表（刪掉舊表再改名），連結表的
 * `technique_id` 是 cascadeOnDelete：在 transaction 裡跑時 `PRAGMA foreign_keys = OFF` 沒有作用，
 * 刪舊表會連帶刪掉連結。實際跑 migration 時 SQLite 不包 transaction（Laravel 的 SQLite grammar
 * 不支援 schema transaction），外鍵真的會關掉，所以另寫一支測試照實際的跑法驗證；PostgreSQL 只加減
 * 欄位、不重建表，在 transaction 裡直接驗證。
 */
class AddDeprecationColumnsToTechniquesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_24_000001_add_deprecation_columns_to_techniques_table.php';

    /** down() 不能動到的既有欄位。 */
    private const ORIGINAL_COLUMNS = ['id', 'type', 'title', 'version', 'note', 'deleted_at', 'created_at', 'updated_at'];

    /** 連到技術的連結表，重建 techniques 時不能被連帶刪掉。 */
    private const LINK_TABLES = ['technique_implementation', 'documentation_technique', 'entity_relations'];

    #[TestDox('兩欄都存在、可以是 NULL；新建的技術兩欄都是 NULL')]
    public function test_columns_exist_are_nullable_and_default_to_null(): void
    {
        $columns = collect(Schema::getColumns('techniques'))->keyBy('name');

        foreach (['deprecated_at', 'replaced_by_id'] as $name) {
            $this->assertTrue($columns->has($name), "少了 {$name}");
            $this->assertTrue($columns[$name]['nullable'], "{$name} 應該可以是 NULL");
        }

        $technique = Technique::factory()->create();
        $row = DB::table('techniques')->where('id', $technique->id)->first();
        $this->assertNull($row->deprecated_at);
        $this->assertNull($row->replaced_by_id);
    }

    #[TestDox('replaced_by_id 指到不存在的技術時寫入失敗（外鍵）')]
    public function test_replaced_by_id_has_a_foreign_key(): void
    {
        $technique = Technique::factory()->create();

        $this->assertTrue(
            collect(Schema::getForeignKeys('techniques'))->contains(fn (array $fk) => $fk['columns'] === ['replaced_by_id'] && $fk['foreign_table'] === 'techniques'),
            'replaced_by_id 應該有指向 techniques 的外鍵',
        );

        try {
            // 包在巢狀 transaction（savepoint）裡：PostgreSQL 上一句失敗會讓整個 transaction 失效
            DB::transaction(fn () => DB::table('techniques')->where('id', $technique->id)->update(['replaced_by_id' => 999999]));
            $this->fail('指到不存在的技術應該被外鍵擋下');
        } catch (QueryException) {
            $this->assertNull(DB::table('techniques')->where('id', $technique->id)->value('replaced_by_id'));
        }
    }

    #[TestDox('被設為改用對象的技術不能被硬刪除（外鍵不設 onDelete，預設擋刪）')]
    public function test_hard_deleting_a_replacement_is_blocked(): void
    {
        $old = Technique::factory()->create();
        $replacement = Technique::factory()->create();
        DB::table('techniques')->where('id', $old->id)->update(['deprecated_at' => now(), 'replaced_by_id' => $replacement->id]);

        try {
            DB::transaction(fn () => DB::table('techniques')->where('id', $replacement->id)->delete());
            $this->fail('被改用的技術被硬刪除，改用對象會默默消失');
        } catch (QueryException) {
            $this->assertSame($replacement->id, (int) DB::table('techniques')->where('id', $old->id)->value('replaced_by_id'));
        }
    }

    #[TestDox('軟刪除被改用的技術不受外鍵影響')]
    public function test_soft_deleting_a_replacement_still_works(): void
    {
        $old = Technique::factory()->create();
        $replacement = Technique::factory()->create();
        DB::table('techniques')->where('id', $old->id)->update(['deprecated_at' => now(), 'replaced_by_id' => $replacement->id]);

        $replacement->delete();

        $this->assertSoftDeleted($replacement);
        $this->assertSame($replacement->id, (int) DB::table('techniques')->where('id', $old->id)->value('replaced_by_id'));
    }

    #[TestDox('down() 只拿掉兩欄，技術列與其他欄位不變；再跑 up() 也一樣')]
    public function test_down_drops_only_the_two_columns(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $this->seedTechniquesWithLinks();

        $before = $this->originalColumnsSnapshot();
        $links = $this->linkSnapshot();
        $this->assertCount(5, $before); // 4 筆＋TechniqueSeeder 的 Claude Code

        $migration->down();

        $this->assertFalse(Schema::hasColumn('techniques', 'deprecated_at'));
        $this->assertFalse(Schema::hasColumn('techniques', 'replaced_by_id'));
        $this->assertTrue(Schema::hasColumns('techniques', self::ORIGINAL_COLUMNS));
        $this->assertEquals($before, $this->originalColumnsSnapshot());
        $this->assertFalse(
            collect(Schema::getForeignKeys('techniques'))->contains(fn (array $fk) => $fk['columns'] === ['replaced_by_id']),
            '外鍵要一起拿掉',
        );
        // SQLite 在 transaction 裡重建表會連帶刪掉連結（見類別說明），由下一支測試照實際跑法驗證
        if (DB::getDriverName() === 'pgsql') {
            $this->assertEquals($links, $this->linkSnapshot(), 'down() 連帶動到了連結表');
        }

        $migration->up();

        $this->assertTrue(Schema::hasColumns('techniques', ['deprecated_at', 'replaced_by_id']));
        $this->assertEquals($before, $this->originalColumnsSnapshot());
        $this->assertSame(0, DB::table('techniques')->whereNotNull('deprecated_at')->count(), 'up() 不回填，重加的欄位都是 NULL');
        if (DB::getDriverName() === 'pgsql') {
            $this->assertEquals($links, $this->linkSnapshot(), 'up() 連帶動到了連結表');
        }
    }

    #[TestDox('SQLite 照 artisan migrate 的跑法退回、再加回，技術列與三張連結表都不變')]
    public function test_sqlite_rebuild_through_artisan_keeps_links(): void
    {
        // 另開一個 SQLite 連線，不在 RefreshDatabase 的 transaction 裡，跟本機、CI 實際跑 migrate 一樣。
        // 不論這次測試的預設資料庫是哪一種都跑：重建表只會發生在 SQLite 上。
        config(['database.connections.sqlite_rebuild_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        $default = DB::getDefaultConnection();

        try {
            $this->artisan('migrate', ['--database' => 'sqlite_rebuild_probe', '--force' => true])->assertSuccessful();

            DB::setDefaultConnection('sqlite_rebuild_probe');
            $this->seedTechniquesWithLinks();
            $before = $this->originalColumnsSnapshot();
            $links = $this->linkSnapshot();
            DB::setDefaultConnection($default);

            $path = 'database/migrations/'.self::MIGRATION;
            $this->artisan('migrate:rollback', ['--database' => 'sqlite_rebuild_probe', '--path' => $path, '--force' => true])->assertSuccessful();

            DB::setDefaultConnection('sqlite_rebuild_probe');
            $this->assertFalse(Schema::hasColumn('techniques', 'replaced_by_id'), 'rollback 沒有跑到這支 migration');
            $this->assertEquals($before, $this->originalColumnsSnapshot());
            $this->assertEquals($links, $this->linkSnapshot(), 'down() 重建表時連帶刪掉了連結');
            DB::setDefaultConnection($default);

            $this->artisan('migrate', ['--database' => 'sqlite_rebuild_probe', '--path' => $path, '--force' => true])->assertSuccessful();

            DB::setDefaultConnection('sqlite_rebuild_probe');
            $this->assertTrue(Schema::hasColumn('techniques', 'replaced_by_id'));
            $this->assertEquals($before, $this->originalColumnsSnapshot());
            $this->assertEquals($links, $this->linkSnapshot(), 'up() 重建表時連帶刪掉了連結');
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('sqlite_rebuild_probe');
        }
    }

    /** 預設資料，加上四筆技術（一筆軟刪除、一筆已停用）與三張連結表各一列。 */
    private function seedTechniquesWithLinks(): void
    {
        $this->seed();

        $vue = Technique::factory()->create(['title' => 'Vue']);
        $vue3 = Technique::factory()->create(['title' => 'Vue', 'version' => '3']);
        Technique::factory()->create()->delete();
        $deprecated = Technique::factory()->create(['title' => 'Procfile']);
        DB::table('techniques')->where('id', $deprecated->id)->update(['deprecated_at' => now(), 'replaced_by_id' => $vue->id]);

        Implementation::factory()->create()->techniques()->attach($vue3->id, ['relation_id' => Relation::where('name', 'uses')->value('id')]);
        Documentation::factory()->create()->techniques()->attach($vue->id, ['relation_id' => Relation::where('name', 'about')->value('id')]);
        EntityRelation::create([
            'entity_type' => 'technique',
            'subject_id' => $vue3->id,
            'object_id' => $vue->id,
            'relation_id' => Relation::where('name', 'isVersionOf')->value('id'),
        ]);

        foreach (self::LINK_TABLES as $table) {
            $this->assertSame(1, DB::table($table)->count(), "{$table} 的前置資料沒建好");
        }
    }

    /** @return list<array<string, mixed>> */
    private function originalColumnsSnapshot(): array
    {
        return DB::table('techniques')
            ->orderBy('id')
            ->get(self::ORIGINAL_COLUMNS)
            ->map(fn (object $row) => (array) $row)
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function linkSnapshot(): array
    {
        return collect(self::LINK_TABLES)
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all()])
            ->all();
    }
}
