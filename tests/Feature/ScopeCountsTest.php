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
 * Scope 詳情／一覽要的那一排計數（規格「本體論編輯規格」B4）。
 *
 * 在此之前 `/api/scopes/{id}` 回的是 `children` / `siblings` / `subject_of` / `object_of`
 * 的**完整集合**，沒有任何實體計數——Triple 的「記錄數量」之所以寫死成 0
 * （`AppTripleDetailStatic.vue` 的 `computed(() => 0)`），就是因為後端根本拿不到這個數字。
 *
 * 這裡刻意不用 seeder 造資料，自己建：seeder 的內容會變，而這幾支測的是
 * 「數出來對不對」，需要一組自己說得準的資料。
 */
class ScopeCountsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 造一棵一層的樹：1 個頂層 + 3 個子類，回傳 [頂層, 子類們]。
     */
    private function tree(): array
    {
        $top = Scope::factory()->create([
            'class_number' => '90',
            'call_number' => '00',
            'parent_class' => null,
        ]);

        $children = collect(['10', '20', '30'])->map(fn ($callNumber) => Scope::factory()->create([
            'class_number' => '90',
            'call_number' => $callNumber,
            'parent_class' => $top->id,
        ]));

        return [$top, $children];
    }

    public function test_entity_counts_are_returned_per_family()
    {
        [, $children] = $this->tree();
        $target = $children->first();

        Documentation::factory()->count(5)->create(['type' => $target->id]);
        Technique::factory()->count(2)->create(['type' => $target->id]);
        // 別族的實體掛在別的 scope 上,不該被算進來。
        Implementation::factory()->count(4)->create(['type' => $children->last()->id]);

        $body = $this->getJson("/api/scopes/{$target->id}")->assertOk()->json();

        $this->assertSame(5, $body['documentations_count']);
        $this->assertSame(2, $body['techniques_count']);
        $this->assertSame(0, $body['implementations_count']);
        $this->assertSame(7, $body['entities_count'], 'entities_count 是三族的總和。');
    }

    /**
     * 軟刪除的實體不算——三個實體模型都有 SoftDeletes，計數必須跟著它們的全域 scope 走。
     */
    public function test_soft_deleted_entities_are_not_counted()
    {
        [, $children] = $this->tree();
        $target = $children->first();

        $docs = Documentation::factory()->count(3)->create(['type' => $target->id]);
        $docs->first()->delete();

        $body = $this->getJson("/api/scopes/{$target->id}")->assertOk()->json();

        $this->assertSame(2, $body['documentations_count']);
    }

    /**
     * **這支測的是一個很容易默默出錯的地方。**
     *
     * `siblings` 這個關聯**包含自己**——那是 `SetCURIEAttribute::siblings()` 刻意的設計，
     * 因為 eager loading 時 Eloquent 用沒有 key 的 `newInstance()` 做 introspection，
     * 寫在關聯定義裡的「排除自己」會退化成 `id IS NOT NULL`（等於沒濾）。既有做法是
     * 由呼叫端載入後自己排除，但**計數沒有「載入後」這一步**，所以 `withCount` 會多算一筆。
     *
     * 3 個子類裡任挑一個，它的兄弟應該是 2，不是 3。
     */
    public function test_siblings_count_excludes_the_scope_itself()
    {
        [, $children] = $this->tree();
        $target = $children->first();

        $body = $this->getJson("/api/scopes/{$target->id}")->assertOk()->json();

        $this->assertSame(2, $body['siblings_count'], '3 個子類,自己不算,兄弟是 2。');
        $this->assertCount(
            2,
            $body['siblings'],
            '計數要跟既有的 siblings 清單一致——清單本來就排除了自己。'
        );
    }

    /**
     * 頂層 scope 依定義沒有兄弟，而且不該因為「扣掉自己」變成 -1。
     */
    public function test_a_top_level_scope_has_no_siblings()
    {
        [$top] = $this->tree();

        $body = $this->getJson("/api/scopes/{$top->id}")->assertOk()->json();

        $this->assertSame(0, $body['siblings_count']);
        $this->assertSame(3, $body['children_count']);
    }

    /**
     * 述詞定義的計數（作為主詞 / 作為受詞），對應詳情頁頂層那一排。
     */
    public function test_predicate_definition_counts()
    {
        [$top, $children] = $this->tree();
        $other = $children->first();

        Relation::factory()->count(2)->create(['subject_id' => $top->id, 'object_id' => $other->id]);
        Relation::factory()->create(['subject_id' => $other->id, 'object_id' => $top->id]);

        $body = $this->getJson("/api/scopes/{$top->id}")->assertOk()->json();

        $this->assertSame(2, $body['subject_of_count']);
        $this->assertSame(1, $body['object_of_count']);
    }

    /**
     * 清單頁不能因為加計數就 N+1。
     *
     * 這支測試的由來是 PR #60：在 `RelationResource` 裡天真地加計數，`/api/relations`
     * 從 11 次查詢變成 344 次。`withCount()` 是把相關子查詢加進同一句 SELECT，
     * 所以**筆數增加不該讓查詢數增加**——這裡直接用「加到 20 筆 scope 之後，
     * 查詢數不能比空的時候多」來守，而不是寫死一個會隨實作漂移的魔術數字。
     */
    public function test_scope_index_does_not_n_plus_one()
    {
        $this->tree();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/scopes')->assertOk();
        $baseline = count(DB::getQueryLog());

        // 再加一批,總數從 4 變成 24。
        $top = Scope::factory()->create([
            'class_number' => '91',
            'call_number' => '00',
            'parent_class' => null,
        ]);
        collect(range(1, 19))->each(fn ($n) => Scope::factory()->create([
            'class_number' => '91',
            'call_number' => str_pad($n, 2, '0', STR_PAD_LEFT),
            'parent_class' => $top->id,
        ]));

        DB::flushQueryLog();
        $this->getJson('/api/scopes')->assertOk();
        $afterGrowth = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $baseline,
            $afterGrowth,
            "查詢數隨資料筆數成長就是 N+1：4 筆時 {$baseline} 次,24 筆時 {$afterGrowth} 次。"
        );
    }
}
