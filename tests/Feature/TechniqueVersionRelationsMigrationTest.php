<?php

namespace Tests\Feature;

use App\Models\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026_09_30_190100_add_technique_version_relations：舊資料庫（已經種過 15 筆關係、還沒有
 * isVersionOf）補上這一對，結果要跟新資料庫直接由 seeder 種出來的一樣。
 */
class TechniqueVersionRelationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_the_pair_to_an_already_seeded_database_like_the_seeder_does(): void
    {
        $this->seed();
        $seeded = Relation::whereIn('name', ['isVersionOf', 'hasVersion'])->orderBy('name')
            ->get(['name', 'subject_id', 'object_id', 'class_number', 'call_number', 'note', 'source_vocabulary', 'source_term'])
            ->toArray();

        $migration = require database_path('migrations/2026_09_30_190100_add_technique_version_relations.php');
        $migration->down();
        $this->assertFalse(Relation::where('name', 'isVersionOf')->exists());

        $migration->up();
        $migration->up(); // 重跑不會多種一次

        $this->assertSame(1, Relation::where('name', 'isVersionOf')->count());
        $this->assertEquals($seeded, Relation::whereIn('name', ['isVersionOf', 'hasVersion'])->orderBy('name')
            ->get(['name', 'subject_id', 'object_id', 'class_number', 'call_number', 'note', 'source_vocabulary', 'source_term'])
            ->toArray());

        $isVersionOf = Relation::where('name', 'isVersionOf')->first();
        $hasVersion = Relation::where('name', 'hasVersion')->first();
        $this->assertSame($hasVersion->id, $isVersionOf->reverse_id);
        $this->assertSame($isVersionOf->id, $hasVersion->reverse_id);
    }
}
