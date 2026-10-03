<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_09_30_190200_merge_duplicate_techniques：PHP／php、Vue／vue 合併，vue3／nuxt3 轉成版本，
 * 而且 down() 能把每一筆資料還原成合併前的樣子。
 */
class MergeDuplicateTechniquesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    private array $t = [];

    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->migration = require database_path('migrations/2026_09_30_190200_merge_duplicate_techniques.php');
        // RefreshDatabase 已經對空資料庫跑過一次 up()（只建了備份表），先退掉再造舊資料
        $this->migration->down();

        $scope = fn (string $name) => Scope::where('name', $name)->value('id');
        foreach ([
            'PHP' => 'language', 'php' => 'packagetool', 'Vue' => 'language', 'vue' => 'framework',
            'vue3' => 'framework', 'nuxt3' => 'packagetool', 'JavaScript' => 'language',
        ] as $title => $scopeName) {
            $this->t[$title] = Technique::create(['type' => $scope($scopeName), 'title' => $title])->id;
        }
        foreach (['a', 'b'] as $name) {
            $this->p[$name] = Implementation::factory()->create(['type' => $scope('project'), 'title' => $name])->id;
        }

        $usedBy = Relation::where('name', 'usedBy')->value('id');
        $link = fn (string $project, string $technique, string $createdAt = '2026-01-01 00:00:00') => DB::table('technique_implementation')->insert([
            'implementation_id' => $this->p[$project], 'technique_id' => $this->t[$technique], 'relation_id' => $usedBy,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        $link('a', 'PHP', '2026-03-01 00:00:00');
        $link('a', 'php', '2025-01-01 00:00:00');   // 跟上一條重複，而且比較早
        $link('b', 'php');
        $link('a', 'Vue');
        $link('a', 'vue');
        $link('b', 'vue3');
        $link('b', 'nuxt3');

        $requires = Relation::where('name', 'requires')->value('id');
        foreach (['vue', 'vue3'] as $framework) {
            DB::table('entity_relations')->insert([
                'entity_type' => 'technique', 'subject_id' => $this->t[$framework], 'object_id' => $this->t['JavaScript'],
                'relation_id' => $requires, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $doc = Documentation::factory()->create();
        DB::table('documentation_technique')->insert([
            'documentation_id' => $doc->id, 'technique_id' => $this->t['vue'],
            'relation_id' => Relation::where('name', 'specs')->value('id'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshot(): array
    {
        return collect(['techniques', 'technique_implementation', 'documentation_technique', 'entity_relations'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])
            ->all();
    }

    /** @return array<int, string> 專案連到的技術，「標題 版本」 */
    private function techniquesOf(string $project): array
    {
        return Implementation::find($this->p[$project])->techniques()->get()
            ->map(fn ($t) => trim("{$t->title} {$t->version}"))->sort()->values()->all();
    }

    public function test_merges_spellings_into_one_record_and_moves_every_edge(): void
    {
        $this->migration->up();

        // PHP：語言那筆留下（類別、寫法都對），php 的邊改接過來，a 的重複那條只剩一條、留較早的時間
        $this->assertSame(['PHP', 'Vue'], $this->techniquesOf('a'));
        $this->assertSoftDeleted('techniques', ['id' => $this->t['php']]);
        $aPhp = DB::table('technique_implementation')->where(['implementation_id' => $this->p['a'], 'technique_id' => $this->t['PHP']])->get();
        $this->assertCount(1, $aPhp);
        $this->assertStringStartsWith('2025-01-01', $aPhp->first()->created_at);

        // Vue：框架那筆（原本的 vue）留下並改名 Vue，語言那筆併進去
        $vue = Technique::find($this->t['vue']);
        $this->assertSame(['Vue', 'framework', null], [$vue->title, $vue->scope->name, $vue->version]);
        $this->assertSoftDeleted('techniques', ['id' => $this->t['Vue']]);
        $this->assertSame(1, DB::table('documentation_technique')->where('technique_id', $vue->id)->count(), '官方文件的 specs 還掛在 Vue 上');
    }

    public function test_versioned_names_become_versions_linked_to_their_base(): void
    {
        $this->migration->up();
        $isVersionOf = Relation::where('name', 'isVersionOf')->value('id');

        $this->assertSame(['Nuxt 3', 'PHP', 'Vue 3'], $this->techniquesOf('b'));

        // vue3 → Vue 3，連到既有的 Vue；它的 requires JavaScript 跟 Vue 的重複，刪掉
        $this->assertSame(['Vue', '3'], [Technique::find($this->t['vue3'])->title, Technique::find($this->t['vue3'])->version]);
        $this->assertTrue(DB::table('entity_relations')->where(['subject_id' => $this->t['vue3'], 'object_id' => $this->t['vue'], 'relation_id' => $isVersionOf])->exists());
        $this->assertFalse(DB::table('entity_relations')->where(['subject_id' => $this->t['vue3'], 'object_id' => $this->t['JavaScript']])->exists());
        $this->assertSame(1, DB::table('entity_relations')->where(['subject_id' => $this->t['vue'], 'object_id' => $this->t['JavaScript']])->count());

        // nuxt3 → Nuxt 3（框架），版本留空的 Nuxt 原本不存在，新建一筆再連過去
        $nuxt3 = Technique::find($this->t['nuxt3']);
        $this->assertSame(['Nuxt', '3', 'framework'], [$nuxt3->title, $nuxt3->version, $nuxt3->scope->name]);
        $nuxt = Technique::where('title', 'Nuxt')->whereNull('version')->firstOrFail();
        $this->assertTrue(DB::table('entity_relations')->where(['subject_id' => $nuxt3->id, 'object_id' => $nuxt->id, 'relation_id' => $isVersionOf])->exists());
    }

    public function test_down_restores_every_row_exactly(): void
    {
        $before = $this->snapshot();

        $this->migration->up();
        $this->assertNotEquals($before, $this->snapshot());

        $this->migration->down();
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_does_nothing_on_an_empty_database(): void
    {
        DB::table('technique_implementation')->delete();
        DB::table('documentation_technique')->delete();
        DB::table('entity_relations')->delete();
        DB::table('techniques')->delete();

        $this->migration->up();

        $this->assertSame(0, DB::table('techniques')->count());
        $this->assertSame(0, DB::table('technique_merge_backups')->count());
    }
}
