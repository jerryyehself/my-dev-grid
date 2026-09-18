<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Service\RelationEdgeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 「使用這個述詞的邊」——計數與分頁清單（規格「本體論編輯規格」B5）。
 *
 * 在此之前 `/api/relations/{id}` 只有 `is_referenced`（布林）跟 `referenced_via`，
 * 問不到 84 這個數字，也拿不到那些邊本身。Triple 的「關聯數量」算的則是
 * `object_of + subject_of` 的長度——那是**述詞定義**的數量，不是邊的數量，
 * 是另一個東西。
 */
class RelationEdgesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 讓同一條述詞在四張連結表各留下一筆邊。
     */
    private function relationWithOneEdgePerTable(): Relation
    {
        $relation = Relation::factory()->create();

        Documentation::factory()->create()
            ->implementations()->attach(Implementation::factory()->create()->id, ['relation_id' => $relation->id]);

        Documentation::factory()->create()
            ->techniques()->attach(Technique::factory()->create()->id, ['relation_id' => $relation->id]);

        Technique::factory()->create()
            ->implementations()->attach(Implementation::factory()->create()->id, ['relation_id' => $relation->id]);

        EntityRelation::factory()->create([
            'entity_type' => 'technique',
            'subject_id' => Technique::factory()->create()->id,
            'object_id' => Technique::factory()->create()->id,
            'relation_id' => $relation->id,
        ]);

        return $relation->fresh();
    }

    public function test_edges_endpoint_returns_rows_from_every_link_table()
    {
        $relation = $this->relationWithOneEdgePerTable();

        $body = $this->getJson("/api/relations/{$relation->id}/edges")->assertOk()->json();

        $this->assertSame(4, $body['total'], '四張連結表各一筆。');

        $sources = collect($body['data'])->pluck('source')->sort()->values()->all();
        $this->assertSame([
            'documentation_implementation',
            'documentation_technique',
            'entity_relations',
            'technique_implementation',
        ], $sources);

        // 正規化後每一列的形狀都一樣,不管來自哪張表。
        foreach ($body['data'] as $edge) {
            $this->assertArrayHasKey('subject_type', $edge);
            $this->assertArrayHasKey('subject_id', $edge);
            $this->assertArrayHasKey('subject_title', $edge);
            $this->assertArrayHasKey('object_type', $edge);
            $this->assertArrayHasKey('object_id', $edge);
            $this->assertArrayHasKey('object_title', $edge);
            $this->assertNotNull($edge['subject_title']);
            $this->assertNotNull($edge['object_title']);
        }
    }

    public function test_edges_of_another_relation_are_not_included()
    {
        $relation = $this->relationWithOneEdgePerTable();
        $other = Relation::factory()->create();

        Documentation::factory()->create()
            ->techniques()->attach(Technique::factory()->create()->id, ['relation_id' => $other->id]);

        $this->assertSame(
            4,
            $this->getJson("/api/relations/{$relation->id}/edges")->assertOk()->json('total')
        );
        $this->assertSame(
            1,
            $this->getJson("/api/relations/{$other->id}/edges")->assertOk()->json('total')
        );
    }

    /**
     * 實體軟刪除之後,那條邊不該還出現在清單裡。
     *
     * 這支測試守的是一個容易漏掉的地方:`RelationEdgeQuery` 用的是裸的 query builder,
     * **吃不到 Eloquent 的 SoftDeletes 全域 scope**,每個 union 分支都得自己
     * `whereNull('deleted_at')`。漏掉的話不會報錯,只會安靜地多出幾條指向已刪除實體的邊。
     */
    public function test_edges_pointing_at_soft_deleted_entities_are_excluded()
    {
        $relation = Relation::factory()->create();

        $documentation = Documentation::factory()->create();
        $technique = Technique::factory()->create();
        $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $this->assertSame(1, $this->getJson("/api/relations/{$relation->id}/edges")->json('total'));

        $technique->delete();

        $this->assertSame(
            0,
            $this->getJson("/api/relations/{$relation->id}/edges")->json('total'),
            '受詞被軟刪除之後,這條邊不該再出現。'
        );
    }

    public function test_edges_are_paginated_and_per_page_is_clamped()
    {
        $relation = Relation::factory()->create();

        $documentation = Documentation::factory()->create();
        collect(range(1, 30))->each(fn () => $documentation->techniques()
            ->attach(Technique::factory()->create()->id, ['relation_id' => $relation->id]));

        $firstPage = $this->getJson("/api/relations/{$relation->id}/edges?per_page=10")->assertOk()->json();
        $this->assertSame(30, $firstPage['total']);
        $this->assertSame(10, $firstPage['per_page']);
        $this->assertCount(10, $firstPage['data']);

        $secondPage = $this->getJson("/api/relations/{$relation->id}/edges?per_page=10&page=2")->assertOk()->json();
        $this->assertCount(10, $secondPage['data']);

        // 分頁之間不能重疊——沒有穩定排序的話這裡就會抓到。
        $firstIds = collect($firstPage['data'])->pluck('object_id');
        $secondIds = collect($secondPage['data'])->pluck('object_id');
        $this->assertEmpty(
            $firstIds->intersect($secondIds),
            '第一頁跟第二頁不該有重複的邊。'
        );

        // 上限擋住「一次要一萬筆」。
        $clamped = $this->getJson("/api/relations/{$relation->id}/edges?per_page=99999")->assertOk()->json();
        $this->assertSame(RelationEdgeQuery::MAX_PER_PAGE, $clamped['per_page']);
    }

    /**
     * own_edges_count 與 reverse_edges_count 要分得開。
     *
     * 這正是 is_referenced 這個布林回答不了的問題:`uses`(84 筆) 跟它的反向
     * `used`(0 筆) **兩者都 is_referenced=true**,但詳情頁要顯示的清單長度完全不同。
     */
    public function test_own_and_reverse_edge_counts_are_reported_separately()
    {
        $subject = Scope::factory()->create();
        $object = Scope::factory()->create();

        $forward = Relation::factory()->create(['subject_id' => $subject->id, 'object_id' => $object->id]);
        $backward = Relation::factory()->create(['subject_id' => $object->id, 'object_id' => $subject->id]);
        $forward->update(['reverse_id' => $backward->id]);

        // 只在 forward 上掛邊。
        Documentation::factory()->create()
            ->techniques()->attach(Technique::factory()->create()->id, ['relation_id' => $forward->id]);

        $forwardBody = $this->getJson("/api/relations/{$forward->id}")->assertOk()->json();
        $backwardBody = $this->getJson("/api/relations/{$backward->id}")->assertOk()->json();

        $this->assertSame(1, $forwardBody['own_edges_count']);
        $this->assertSame(0, $forwardBody['reverse_edges_count']);

        $this->assertSame(0, $backwardBody['own_edges_count'], '反向那條自己沒有邊。');
        $this->assertSame(1, $backwardBody['reverse_edges_count'], '但它的反向有 1 筆。');

        // 兩條都被鎖住——這就是 is_referenced 回答不了的差別。
        $this->assertTrue($forwardBody['is_referenced']);
        $this->assertTrue($backwardBody['is_referenced']);
    }

    /**
     * 對稱關係（自己就是自己的反向）不能把同一批邊算兩次。
     */
    public function test_a_symmetric_relation_does_not_double_count_its_edges()
    {
        $scope = Scope::factory()->create();
        $relation = Relation::factory()->create(['subject_id' => $scope->id, 'object_id' => $scope->id]);
        $relation->update(['reverse_id' => $relation->id]);

        Documentation::factory()->create()
            ->techniques()->attach(Technique::factory()->create()->id, ['relation_id' => $relation->id]);

        $body = $this->getJson("/api/relations/{$relation->id}")->assertOk()->json();

        $this->assertSame(1, $body['own_edges_count']);
        $this->assertSame(0, $body['reverse_edges_count'], '對稱關係的邊已經算在 own 裡了。');
    }

    /**
     * 加了這兩個計數之後，清單頁仍然不能 N+1。
     *
     * PR #60 就是在 RelationResource 裡天真地加計數，讓 /api/relations 從 11 次查詢
     * 變成 344 次。這裡用「筆數成長，查詢數不准跟著長」來守，而不是寫死一個會隨
     * 實作漂移的魔術數字。
     */
    public function test_relation_index_query_count_does_not_grow_with_rows()
    {
        Relation::factory()->count(3)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/relations')->assertOk();
        $baseline = count(DB::getQueryLog());

        Relation::factory()->count(20)->create();

        DB::flushQueryLog();
        $this->getJson('/api/relations')->assertOk();
        $afterGrowth = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $baseline,
            $afterGrowth,
            "查詢數隨資料筆數成長就是 N+1：3 筆時 {$baseline} 次,23 筆時 {$afterGrowth} 次。"
        );
    }
}
