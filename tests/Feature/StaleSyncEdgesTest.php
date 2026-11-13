<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\TechniqueImplementationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * GitHub 同步只新增邊、不刪邊，程式碼拿掉的技術、Linguist 誤判的語言會一直留在圖上。
 * 以所有同步邊最新的 last_seen_at 為基準，往前超過 14 天沒被看到的邊算過期（stale）：
 * 圖還是照樣回傳（前端淡化顯示，保留「以前用過」），但最短路徑不走過期的邊。
 * last_seen_at 是 null（開始記錄前就有、或手動建立）永遠不算過期。
 */
class StaleSyncEdgesTest extends TestCase
{
    use RefreshDatabase;

    private Relation $usedBy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usedBy = Relation::factory()->create(['name' => 'usedBy']);
    }

    /** 建一條「技術 usedBy 專案」的邊，last_seen_at 直接寫進去（null 代表從沒被同步看到過）。 */
    private function link(Technique $technique, Implementation $implementation, ?string $lastSeenAt): void
    {
        $technique->implementations()->attach($implementation->id, ['relation_id' => $this->usedBy->id]);
        DB::table('technique_implementation')
            ->where(['technique_id' => $technique->id, 'implementation_id' => $implementation->id])
            ->update(['last_seen_at' => $lastSeenAt]);
    }

    private function edgeOf(TestResponse $response, Technique $technique, Implementation $implementation): array
    {
        $edge = collect($response->json('edges'))->first(
            fn (array $e) => $e['source'] === "technique-{$technique->id}" && $e['target'] === "implementation-{$implementation->id}"
        );
        $this->assertNotNull($edge, '過期的邊也要回傳，不能從圖裡消失');

        return $edge;
    }

    #[TestDox('比最新同步早超過 14 天沒看到的邊 stale 為 true，其餘為 false')]
    public function test_graph_flags_edges_not_seen_for_more_than_the_window_before_the_latest_sync()
    {
        $project = Implementation::factory()->create();
        [$latest, $fresh, $stale, $unseen] = Technique::factory()->count(4)->create()->all();
        $this->link($latest, $project, '2026-11-10 12:00:00');   // 基準：最新一次同步
        $this->link($fresh, $project, '2026-11-05 00:00:00');    // 5 天前
        $this->link($stale, $project, '2026-10-01 00:00:00');    // 40 天前
        $this->link($unseen, $project, null);                    // 開始記錄前就有

        $response = $this->getJson('/api/graph')->assertOk();

        $this->assertFalse($this->edgeOf($response, $latest, $project)['stale']);
        $this->assertFalse($this->edgeOf($response, $fresh, $project)['stale']);
        $this->assertTrue($this->edgeOf($response, $stale, $project)['stale']);
        $this->assertFalse($this->edgeOf($response, $unseen, $project)['stale']);
    }

    #[TestDox('剛好 14 天不算過期，差一秒才算')]
    public function test_the_window_boundary_is_exclusive()
    {
        $this->assertSame(14, TechniqueImplementationLink::STALE_AFTER_DAYS);
        $project = Implementation::factory()->create();
        [$latest, $onEdge, $justOver] = Technique::factory()->count(3)->create()->all();
        $this->link($latest, $project, '2026-11-14 00:00:00');
        $this->link($onEdge, $project, '2026-10-31 00:00:00');   // 剛好 14 天
        $this->link($justOver, $project, '2026-10-30 23:59:59'); // 14 天又 1 秒

        $response = $this->getJson('/api/graph')->assertOk();

        $this->assertFalse($this->edgeOf($response, $onEdge, $project)['stale']);
        $this->assertTrue($this->edgeOf($response, $justOver, $project)['stale']);
    }

    #[TestDox('同步停擺（所有邊都很舊）時，沒有邊被判為過期')]
    public function test_a_stalled_sync_hides_nothing()
    {
        // 基準是最新的 last_seen_at 而不是現在：整張表一起變舊，彼此還是相差不到 14 天
        $this->travelTo('2026-11-13 00:00:00');
        $project = Implementation::factory()->create();
        [$a, $b] = Technique::factory()->count(2)->create()->all();
        $this->link($a, $project, '2025-01-01 00:00:00');
        $this->link($b, $project, '2025-01-05 00:00:00');

        $response = $this->getJson('/api/graph')->assertOk();

        $this->assertFalse($this->edgeOf($response, $a, $project)['stale']);
        $this->assertFalse($this->edgeOf($response, $b, $project)['stale']);
    }

    #[TestDox('沒有任何同步過的邊時，沒有邊過期，也不會出錯')]
    public function test_no_edge_is_stale_when_nothing_was_ever_synced()
    {
        $this->assertNull(TechniqueImplementationLink::staleCutoff());
        $project = Implementation::factory()->create();
        $technique = Technique::factory()->create();
        $this->link($technique, $project, null);

        $this->assertFalse($this->edgeOf($this->getJson('/api/graph')->assertOk(), $technique, $project)['stale']);
    }

    #[TestDox('其他種類的邊 stale 一律為 false')]
    public function test_other_edge_kinds_are_never_stale()
    {
        $relation = Relation::factory()->create();
        $documentation = Documentation::factory()->create();
        [$technique, $other] = Technique::factory()->count(2)->create()->all();
        $implementation = Implementation::factory()->create();
        $this->link($technique, $implementation, '2026-11-10 00:00:00');
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $documentation->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);
        EntityRelation::create(['entity_type' => 'technique', 'subject_id' => $technique->id, 'object_id' => $other->id, 'relation_id' => $relation->id]);
        // 其他表的列再舊也不影響：只有 technique_implementation 看 last_seen_at
        DB::table('documentation_technique')->update(['created_at' => '2020-01-01 00:00:00']);

        $edges = collect($this->getJson('/api/graph')->assertOk()->json('edges'));

        $this->assertCount(4, $edges);
        $this->assertSame([false, false, false, false], $edges->pluck('stale')->all());
    }

    #[TestDox('最短路徑不經過過期的邊，改走較長的新鮮路線')]
    public function test_path_skips_stale_edges_and_takes_a_longer_fresh_route()
    {
        [$start, $end, $middle] = Implementation::factory()->count(3)->create()->all();
        [$shortcut, $t1, $t2] = Technique::factory()->count(3)->create()->all();
        $this->link($shortcut, $start, '2026-11-10 00:00:00');
        $this->link($shortcut, $end, '2026-09-01 00:00:00');   // 過期：走這條只要 2 步
        $this->link($t1, $start, '2026-11-10 00:00:00');
        $this->link($t1, $middle, '2026-11-10 00:00:00');
        $this->link($t2, $middle, '2026-11-10 00:00:00');
        $this->link($t2, $end, '2026-11-10 00:00:00');         // 新鮮的路線 4 步

        $response = $this->getJson("/api/graph/path?start=implementation-{$start->id}&end=implementation-{$end->id}")->assertOk();

        $response->assertJsonPath('found', true);
        $response->assertJsonCount(4, 'edges');
        $this->assertNotContains("technique-{$shortcut->id}", array_column($response->json('nodes'), 'id'));
    }

    #[TestDox('只剩過期的邊相連時，路徑回報找不到')]
    public function test_path_is_not_found_when_only_a_stale_edge_connects_the_nodes()
    {
        [$start, $end] = Implementation::factory()->count(2)->create()->all();
        $technique = Technique::factory()->create();
        $this->link($technique, $start, '2026-11-10 00:00:00');
        $this->link($technique, $end, '2026-09-01 00:00:00');

        $this->getJson("/api/graph/path?start=implementation-{$start->id}&end=implementation-{$end->id}")
            ->assertOk()
            ->assertExactJson(['found' => false, 'nodes' => [], 'edges' => []]);

        // 邊本身還在圖上
        $this->assertTrue($this->edgeOf($this->getJson('/api/graph')->assertOk(), $technique, $end)['stale']);
    }

    #[TestDox('last_seen_at 是 null 的邊，路徑照常走')]
    public function test_path_still_uses_edges_without_last_seen_at()
    {
        [$start, $end] = Implementation::factory()->count(2)->create()->all();
        $technique = Technique::factory()->create();
        $this->link($technique, $start, '2026-11-10 00:00:00');
        $this->link($technique, $end, null);

        $this->getJson("/api/graph/path?start=implementation-{$start->id}&end=implementation-{$end->id}")
            ->assertOk()
            ->assertJsonPath('found', true);
    }
}
