<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 圖譜與最短路徑上，述詞已棄用的邊照常回傳、名稱照舊，只多 `predicate_deprecated` 與
 * `predicate_replaced_by` 兩個標記；路徑照常經過它們。
 */
class DeprecatedPredicateGraphTest extends TestCase
{
    use RefreshDatabase;

    private Relation $old;

    private Relation $oldRev;

    private Relation $new;

    private Relation $newRev;

    private Documentation $documentation;

    private Technique $technique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->old = Relation::factory()->create(['name' => 'oldSpecs']);
        $this->oldRev = Relation::factory()->create(['name' => 'oldSpecifiedBy', 'subject_id' => $this->old->object_id, 'object_id' => $this->old->subject_id, 'reverse_id' => $this->old->id]);
        $this->new = Relation::factory()->create(['name' => 'newSpecs', 'subject_id' => $this->old->subject_id, 'object_id' => $this->old->object_id]);
        $this->newRev = Relation::factory()->create(['name' => 'newSpecifiedBy', 'subject_id' => $this->old->object_id, 'object_id' => $this->old->subject_id, 'reverse_id' => $this->new->id]);

        $this->documentation = Documentation::factory()->create();
        $this->technique = Technique::factory()->create();
        $this->documentation->techniques()->attach($this->technique->id, ['relation_id' => $this->old->id]);
    }

    private function edge(): array
    {
        $edge = collect($this->getJson('/api/graph')->assertOk()->json('edges'))->first(
            fn (array $e) => $e['source'] === "documentation-{$this->documentation->id}" && $e['target'] === "technique-{$this->technique->id}"
        );
        $this->assertNotNull($edge, '棄用述詞的邊也要回傳。');

        return $edge;
    }

    #[TestDox('使用中的述詞：predicate_deprecated 為 false、predicate_replaced_by 為 null')]
    public function test_live_predicate_is_not_flagged()
    {
        $edge = $this->edge();

        $this->assertFalse($edge['predicate_deprecated']);
        $this->assertNull($edge['predicate_replaced_by']);
    }

    #[TestDox('棄用述詞的邊照常回傳，名稱照舊，標記與改用名稱正確')]
    public function test_deprecated_predicate_edge_is_flagged_and_keeps_its_name()
    {
        $this->old->fresh()->deprecate($this->new->fresh());

        $edge = $this->edge();

        $this->assertSame('oldSpecs', $edge['predicate']);
        $this->assertSame($this->old->id, $edge['relation_id']);
        $this->assertTrue($edge['predicate_deprecated']);
        $this->assertSame('newSpecs', $edge['predicate_replaced_by']);
    }

    #[TestDox('沒有改用對象時 predicate_replaced_by 為 null')]
    public function test_deprecated_without_replacement()
    {
        $this->old->fresh()->deprecate(null);

        $edge = $this->edge();

        $this->assertTrue($edge['predicate_deprecated']);
        $this->assertNull($edge['predicate_replaced_by']);
    }

    #[TestDox('路徑照常經過棄用述詞的邊；正著走用自己的標記，反著走用反向的名稱與標記')]
    public function test_path_goes_through_deprecated_edges_in_both_directions()
    {
        $this->old->fresh()->deprecate($this->new->fresh());
        $doc = "documentation-{$this->documentation->id}";
        $tech = "technique-{$this->technique->id}";

        $forward = $this->getJson("/api/graph/path?start={$doc}&end={$tech}")->assertOk()->assertJsonPath('found', true);
        $forward->assertJsonPath('edges.0.predicate', 'oldSpecs')
            ->assertJsonPath('edges.0.predicate_deprecated', true)
            ->assertJsonPath('edges.0.predicate_replaced_by', 'newSpecs');

        $backward = $this->getJson("/api/graph/path?start={$tech}&end={$doc}")->assertOk()->assertJsonPath('found', true);
        $backward->assertJsonPath('edges.0.predicate', 'oldSpecifiedBy')
            ->assertJsonPath('edges.0.storedDirection', 'reverse')
            ->assertJsonPath('edges.0.predicate_deprecated', true)
            ->assertJsonPath('edges.0.predicate_replaced_by', 'newSpecifiedBy');
    }

    #[TestDox('改用對象後來也棄用時，predicate_replaced_by 給最後仍在使用的那一條；一路都棄用時為 null')]
    public function test_replaced_by_follows_the_chain()
    {
        $newest = Relation::factory()->create(['name' => 'newestSpecs', 'subject_id' => $this->old->subject_id, 'object_id' => $this->old->object_id]);
        Relation::factory()->create(['name' => 'newestSpecifiedBy', 'subject_id' => $this->old->object_id, 'object_id' => $this->old->subject_id, 'reverse_id' => $newest->id]);
        $this->old->fresh()->deprecate($this->new->fresh());
        $this->new->fresh()->deprecate($newest->fresh());

        $this->assertSame('newestSpecs', $this->edge()['predicate_replaced_by']);

        $newest->fresh()->deprecate(null);
        $this->assertNull($this->edge()['predicate_replaced_by']);
        $this->assertTrue($this->edge()['predicate_deprecated']);
    }
}
