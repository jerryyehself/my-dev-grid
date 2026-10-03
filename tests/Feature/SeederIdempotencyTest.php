<?php

namespace Tests\Feature;

use App\Models\Relation;
use App\Models\Scope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ScopeSeeder／RelationSeeder 要可以重複執行（2026-10-02）：原本全部用 create()，
 * 正式環境跑第二次就整份重複一次。改成以 name 為鍵「沒有才建」之後，重跑不能
 * 重複、不能蓋掉後台改過的內容、也不能把後台刪掉的建回來。
 */
class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_twice_creates_no_duplicates()
    {
        $this->seed(DatabaseSeeder::class);
        $scopeCount = Scope::count();
        $relationCount = Relation::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(16, $scopeCount);
        $this->assertSame(17, $relationCount); // 15 筆＋技術版本的 isVersionOf／hasVersion
        $this->assertSame($scopeCount, Scope::count());
        $this->assertSame($relationCount, Relation::count());
        $this->assertSame($scopeCount, Scope::distinct()->count('name'));
        $this->assertSame($relationCount, Relation::distinct()->count('name'));
    }

    public function test_running_twice_keeps_every_reverse_pair_mutual()
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        foreach (Relation::all() as $relation) {
            $this->assertNotNull($relation->reverse_id, "{$relation->name} 沒有反向關係");
            $this->assertSame(
                $relation->id,
                Relation::find($relation->reverse_id)->reverse_id,
                "{$relation->name} 的反向關係沒有指回來"
            );
        }
    }

    public function test_rerun_does_not_overwrite_edits_made_in_the_admin()
    {
        $this->seed(DatabaseSeeder::class);
        Scope::where('name', 'post')->first()->update(['comment' => '後台改過的說明']);
        Relation::where('name', 'uses')->first()->update(['note' => '後台改過的述詞說明']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('後台改過的說明', Scope::where('name', 'post')->value('comment'));
        $this->assertSame('後台改過的述詞說明', Relation::where('name', 'uses')->value('note'));
    }

    public function test_rerun_does_not_recreate_rows_deleted_in_the_admin()
    {
        $this->seed(DatabaseSeeder::class);
        Scope::where('name', 'picture')->first()->delete();
        Relation::where('name', 'precedes')->first()->delete();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Scope::where('name', 'picture')->count());
        $this->assertSame(1, Scope::withTrashed()->where('name', 'picture')->count());
        $this->assertSame(0, Relation::where('name', 'precedes')->count());
        $this->assertSame(1, Relation::withTrashed()->where('name', 'precedes')->count());
    }
}
