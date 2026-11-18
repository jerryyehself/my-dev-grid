<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 技術與實作之間的基本關聯不會過期。GitHub 同步建立的邊就算很久沒被同步看到
 * （last_seen_at 很舊，或是 null），圖譜照樣回傳、最短路徑照樣經過。
 * `stale` 欄位為了相容還在，但一律是 false。
 */
class SyncEdgesNeverExpireTest extends TestCase
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
        $this->assertNotNull($edge, '很久沒被同步看到的邊也要回傳');

        return $edge;
    }

    #[TestDox('不管 last_seen_at 多舊或是 null，邊的 stale 都是 false')]
    public function test_graph_never_flags_sync_edges_as_stale()
    {
        $project = Implementation::factory()->create();
        [$latest, $fresh, $old, $unseen] = Technique::factory()->count(4)->create()->all();
        $this->link($latest, $project, '2026-11-10 12:00:00');
        $this->link($fresh, $project, '2026-11-05 00:00:00');
        $this->link($old, $project, '2026-10-01 00:00:00');   // 超過 40 天沒被看到
        $this->link($unseen, $project, null);

        $response = $this->getJson('/api/graph')->assertOk();

        foreach ([$latest, $fresh, $old, $unseen] as $technique) {
            $this->assertFalse($this->edgeOf($response, $technique, $project)['stale']);
        }
    }

    #[TestDox('其他種類的邊 stale 也是 false')]
    public function test_other_edge_kinds_are_not_stale_either()
    {
        $relation = Relation::factory()->create();
        $documentation = Documentation::factory()->create();
        [$technique, $other] = Technique::factory()->count(2)->create()->all();
        $implementation = Implementation::factory()->create();
        $this->link($technique, $implementation, '2026-11-10 00:00:00');
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $documentation->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);
        EntityRelation::create(['entity_type' => 'technique', 'subject_id' => $technique->id, 'object_id' => $other->id, 'relation_id' => $relation->id]);

        $edges = collect($this->getJson('/api/graph')->assertOk()->json('edges'));

        $this->assertCount(4, $edges);
        $this->assertSame([false, false, false, false], $edges->pluck('stale')->all());
    }

    #[TestDox('最短路徑照常經過很久沒被同步看到的邊')]
    public function test_path_goes_through_edges_not_seen_by_sync_for_a_long_time()
    {
        [$start, $end, $middle] = Implementation::factory()->count(3)->create()->all();
        [$shortcut, $t1, $t2] = Technique::factory()->count(3)->create()->all();
        $this->link($shortcut, $start, '2026-11-10 00:00:00');
        $this->link($shortcut, $end, '2026-09-01 00:00:00');   // 很久沒被看到，但最短
        $this->link($t1, $start, '2026-11-10 00:00:00');
        $this->link($t1, $middle, '2026-11-10 00:00:00');
        $this->link($t2, $middle, '2026-11-10 00:00:00');
        $this->link($t2, $end, '2026-11-10 00:00:00');

        $response = $this->getJson("/api/graph/path?start=implementation-{$start->id}&end=implementation-{$end->id}")->assertOk();

        $response->assertJsonPath('found', true);
        $response->assertJsonCount(2, 'edges');
        $this->assertContains("technique-{$shortcut->id}", array_column($response->json('nodes'), 'id'));
    }

    #[TestDox('只靠很久沒被同步看到的邊相連，路徑也找得到')]
    public function test_path_is_found_when_only_an_old_sync_edge_connects_the_nodes()
    {
        [$start, $end] = Implementation::factory()->count(2)->create()->all();
        $technique = Technique::factory()->create();
        $this->link($technique, $start, '2026-11-10 00:00:00');
        $this->link($technique, $end, '2026-09-01 00:00:00');

        $this->getJson("/api/graph/path?start=implementation-{$start->id}&end=implementation-{$end->id}")
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonCount(2, 'edges');
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
