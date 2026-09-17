<?php

namespace Tests\Feature;

use App\Exceptions\RelationLockedException;
use App\Models\Documentation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Relation::isReferenced() 必須把**反向那條**的引用也算進來。
 *
 * 邊只存單向,反向靠 reverse_id 推出來:GraphController 在路徑反著走時回傳的
 * predicate 是反向關係的名字。實測 /api/graph/path?start=technique-1&end=documentation-1
 * 回的是 {"predicate":"specifiedBy","relation_id":3,"storedDirection":"reverse"}。
 *
 * 2026-09-17 之前 isReferenced() 只看自己那四張連結表,於是每一對都剛好一半鎖定、
 * 一半可改——`uses` 有 84 筆邊所以鎖定,而 `used` 是 0 筆所以完全可改,儘管那 84 筆邊
 * 反著讀顯示的正是 `used`。改它就是追溯改變那些邊的意義。
 */
class RelationReferenceLockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 挑一對非對稱的關係,用其中一條掛上一筆真實的 pivot 邊,回傳 [有邊的那條, 它的反向]。
     *
     * DatabaseSeeder 只跑 ScopeSeeder/RelationSeeder,不產生任何連結——本機開發資料庫
     * 裡那 84 筆 technique_implementation 是 github:sync-repos 同步來的,測試環境沒有。
     * 所以這裡自己造,測試才自足。
     */
    private function pairWithOneLinkedSide(): array
    {
        $this->seed();

        $linked = Relation::all()->first(
            fn (Relation $r) => ! is_null($r->reverse_id) && $r->reverse_id !== $r->id
        );
        $this->assertNotNull($linked, 'seeder 應該種出至少一對非對稱的關係。');

        Documentation::factory()
            ->create()
            ->techniques()
            ->attach(Technique::factory()->create()->id, ['relation_id' => $linked->id]);

        return [$linked->fresh(), Relation::findOrFail($linked->reverse_id)];
    }

    /**
     * 自己 0 筆、反向有邊 → 仍然算被引用。
     */
    public function test_is_referenced_counts_the_reverses_links()
    {
        [$linked, $partner] = $this->pairWithOneLinkedSide();

        $this->assertTrue($linked->hasOwnReferences(), '掛了邊的那一條自己要有邊。');
        $this->assertFalse($partner->hasOwnReferences(), '另一半自己不該有邊,否則測不到要測的東西。');
        $this->assertTrue($partner->isReferenced(), '反向那條有邊,這一條就該算被引用。');
    }

    /**
     * 被自己的邊鎖住 vs 被反向的邊鎖住,要分得出來——鎖定欄位的畫面要能說明理由。
     */
    public function test_referenced_via_distinguishes_self_from_reverse()
    {
        $this->pairWithOneLinkedSide();

        $req = $this->getJson('/api/relations')->assertOk()->json('data');

        $bySelf = collect($req)->firstWhere('referenced_via', 'self');
        $byReverse = collect($req)->firstWhere('referenced_via', 'reverse');
        $free = collect($req)->firstWhere('referenced_via', null);

        $this->assertNotNull($bySelf, '應該有被自己的邊鎖住的關係。');
        $this->assertNotNull($byReverse, '應該有被反向的邊鎖住的關係。');
        $this->assertNotNull($free, '應該有兩邊都沒有邊、完全可改的關係。');

        $this->assertTrue($bySelf['is_referenced']);
        $this->assertTrue($byReverse['is_referenced']);
        $this->assertFalse($free['is_referenced']);

        $this->assertSame(Relation::LOCKED_FIELDS, $byReverse['locked_fields']);
        $this->assertSame([], $free['locked_fields']);
    }

    /**
     * 實際的行為後果:只有反向有邊的那一條,現在也不能改鎖定欄位了。
     */
    public function test_a_relation_locked_only_by_its_reverse_cannot_change_locked_fields()
    {
        [, $partner] = $this->pairWithOneLinkedSide();

        $this->expectException(RelationLockedException::class);

        $partner->update(['name' => 'RenamedBehindTheScenes']);
    }

    /**
     * 清單頁不能 N+1。
     *
     * 這支測試存在的理由很具體:剛加上 is_referenced/referenced_via/locked_fields 時,
     * /api/relations 從 11 次查詢暴增到 344 次——每一列各自去查 8 次連結表,而巢狀的
     * reverse 資源又再遞迴算一次自己的引用狀態。修法是 scopeWithReferenceCounts() 預載
     * 計數、reverse 只吐最小形狀、isReferenced() 記憶化,之後回到 12 次。
     */
    public function test_relation_index_does_not_n_plus_one()
    {
        $this->pairWithOneLinkedSide();

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->getJson('/api/relations')->assertOk();

        $this->assertLessThan(
            30,
            $count,
            "/api/relations 用了 {$count} 次查詢。上限刻意訂在 30(實測 12),超過代表每一列又開始各自查連結表了。"
        );
    }
}
