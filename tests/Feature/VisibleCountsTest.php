<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 公開的計數欄位不能洩漏看不到的東西。
 *
 * 清單與邊的端點沒登入時都已經濾掉不公開的專案（`is_visible = false`）與草稿文件，
 * 但 `implementations_count`、`own_edges_count`、`reverse_edges_count` 原本照數全部，
 * 訪客拿清單長度跟計數一比就知道少了幾筆。這裡守的是：沒登入時計數跟訪客實際看得到的
 * 清單一致（述詞的 `own_edges_count` 等於 `/edges` 的 `total`），登入後數全部。
 *
 * 匿名與登入分成不同的測試方法，免得同一個測試裡前一個請求的 guard 狀態殘留到下一個。
 */
class VisibleCountsTest extends TestCase
{
    use RefreshDatabase;

    private Scope $scope;

    private Relation $relation;

    private Relation $reverse;

    private Implementation $visible;

    private Implementation $hidden;

    private Documentation $published;

    private Documentation $draft;

    private Technique $technique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scope = Scope::factory()->create();
        $this->visible = Implementation::factory()->create(['type' => $this->scope->id, 'is_visible' => true]);
        $this->hidden = Implementation::factory()->create(['type' => $this->scope->id, 'is_visible' => false]);
        $this->published = Documentation::factory()->create(['status' => Documentation::STATUS_PUBLISHED]);
        $this->draft = Documentation::factory()->create(['status' => 0]);
        $this->technique = Technique::factory()->create();

        $this->relation = Relation::factory()->create();
        $this->reverse = Relation::factory()->create();
        $this->relation->update(['reverse_id' => $this->reverse->id]);
        $this->relation->refresh();

        // 四張連結表都有邊。看得到的：4 條（每張表 1 條）。
        $this->link('documentation_implementation', $this->published, $this->visible);
        $this->link('documentation_technique', $this->published, $this->technique);
        $this->link('technique_implementation', $this->technique, $this->visible);
        $this->entityEdge('technique', Technique::factory()->create(), $this->technique);

        // 看不到的：草稿、不公開的專案各自在不同連結表出現，也有兩端都看不到的邊，
        // 以及 entity_relations 裡主體或客體其中一端看不到的邊。
        $this->link('documentation_implementation', $this->draft, $this->visible);
        $this->link('documentation_implementation', $this->published, $this->hidden);
        $this->link('documentation_implementation', $this->draft, $this->hidden);
        $this->link('documentation_technique', $this->draft, $this->technique);
        $this->link('technique_implementation', $this->technique, $this->hidden);
        $this->entityEdge('implementation', $this->hidden, $this->visible);
        $this->entityEdge('implementation', $this->visible, $this->hidden);
        $this->entityEdge('documentation', $this->draft, $this->published);
        $this->entityEdge('documentation', $this->published, $this->draft);

        // 反向那條：1 條看得到、1 條連到不公開的專案。
        $this->link('documentation_implementation', $this->published, $this->visible, $this->reverse);
        $this->link('documentation_implementation', $this->published, $this->hidden, $this->reverse);
    }

    private function link(string $table, object $left, object $right, ?Relation $relation = null): void
    {
        $columns = [
            'documentation_implementation' => ['documentation_id', 'implementation_id'],
            'documentation_technique' => ['documentation_id', 'technique_id'],
            'technique_implementation' => ['technique_id', 'implementation_id'],
        ][$table];

        DB::table($table)->insert([
            $columns[0] => $left->id,
            $columns[1] => $right->id,
            'relation_id' => ($relation ?? $this->relation)->id,
        ]);
    }

    private function entityEdge(string $type, object $subject, object $object): void
    {
        EntityRelation::create([
            'entity_type' => $type,
            'subject_id' => $subject->id,
            'object_id' => $object->id,
            'relation_id' => $this->relation->id,
        ]);
    }

    private function signIn(): void
    {
        $this->withToken(User::factory()->create()->createToken('test')->plainTextToken);
    }

    #[TestDox('訪客的分類詳情不數不公開的專案')]
    public function test_guest_scope_show_counts_only_visible_implementations()
    {
        $body = $this->getJson("/api/scopes/{$this->scope->id}")->assertOk()->json();

        $this->assertSame(1, $body['implementations_count']);
        $this->assertSame(1, $body['entities_count'], '實體總數也不含不公開的專案。');
    }

    #[TestDox('登入後的分類詳情數全部的專案')]
    public function test_signed_in_scope_show_counts_hidden_implementations()
    {
        $this->signIn();

        $body = $this->getJson("/api/scopes/{$this->scope->id}")->assertOk()->json();

        $this->assertSame(2, $body['implementations_count']);
    }

    #[TestDox('訪客的分類列表不數不公開的專案')]
    public function test_guest_scope_index_counts_only_visible_implementations()
    {
        $row = collect($this->getJson('/api/scopes')->assertOk()->json('data'))
            ->firstWhere('id', $this->scope->id);

        $this->assertSame(1, $row['implementations_count']);
    }

    #[TestDox('登入後的分類列表數全部的專案')]
    public function test_signed_in_scope_index_counts_hidden_implementations()
    {
        $this->signIn();

        $row = collect($this->getJson('/api/scopes')->assertOk()->json('data'))
            ->firstWhere('id', $this->scope->id);

        $this->assertSame(2, $row['implementations_count']);
    }

    #[TestDox('訪客的述詞詳情不數牽涉看不到之端點的邊，且等於邊清單的總數')]
    public function test_guest_relation_show_excludes_edges_touching_hidden_endpoints()
    {
        $body = $this->getJson("/api/relations/{$this->relation->id}")->assertOk()->json();
        $total = $this->getJson("/api/relations/{$this->relation->id}/edges")->assertOk()->json('total');

        $this->assertSame(4, $body['own_edges_count']);
        $this->assertSame($total, $body['own_edges_count'], '計數要跟訪客實際看得到的邊清單一致。');
        $this->assertSame(1, $body['reverse_edges_count']);
    }

    #[TestDox('登入後的述詞詳情數全部的邊，且等於邊清單的總數')]
    public function test_signed_in_relation_show_counts_every_edge()
    {
        $this->signIn();

        $body = $this->getJson("/api/relations/{$this->relation->id}")->assertOk()->json();
        $total = $this->getJson("/api/relations/{$this->relation->id}/edges")->assertOk()->json('total');

        $this->assertSame(13, $body['own_edges_count']);
        $this->assertSame($total, $body['own_edges_count']);
        $this->assertSame(2, $body['reverse_edges_count']);
    }

    #[TestDox('訪客的述詞列表只數看得到的邊')]
    public function test_guest_relation_index_excludes_edges_touching_hidden_endpoints()
    {
        $rows = collect($this->getJson('/api/relations')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(4, $rows[$this->relation->id]['own_edges_count']);
        $this->assertSame(1, $rows[$this->relation->id]['reverse_edges_count']);
        $this->assertSame(1, $rows[$this->reverse->id]['own_edges_count']);
        $this->assertSame(4, $rows[$this->reverse->id]['reverse_edges_count']);
    }

    #[TestDox('登入後的述詞列表數全部的邊')]
    public function test_signed_in_relation_index_counts_every_edge()
    {
        $this->signIn();

        $rows = collect($this->getJson('/api/relations')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(13, $rows[$this->relation->id]['own_edges_count']);
        $this->assertSame(2, $rows[$this->relation->id]['reverse_edges_count']);
        $this->assertSame(2, $rows[$this->reverse->id]['own_edges_count']);
        $this->assertSame(13, $rows[$this->reverse->id]['reverse_edges_count']);
    }

    /**
     * 鎖定看的是「有沒有邊用到這個名字」，不是訪客數得到幾筆。
     *
     * 一條只有看不到的邊的述詞，訪客數到 0，但它仍然被引用、欄位仍然鎖住；
     * 若把過濾後的計數拿去判斷，訪客會看到「沒被引用」，編輯畫面就會誤判成可改。
     */
    #[TestDox('只有看不到的邊的述詞，對訪客計數為 0 但仍是已引用')]
    public function test_a_relation_with_only_hidden_edges_stays_referenced_for_guests()
    {
        $only = Relation::factory()->create();
        DB::table('documentation_implementation')->insert([
            'documentation_id' => $this->draft->id,
            'implementation_id' => $this->hidden->id,
            'relation_id' => $only->id,
        ]);

        $show = $this->getJson("/api/relations/{$only->id}")->assertOk()->json();
        $index = collect($this->getJson('/api/relations')->assertOk()->json('data'))->firstWhere('id', $only->id);

        foreach ([$show, $index] as $body) {
            $this->assertSame(0, $body['own_edges_count']);
            $this->assertTrue($body['is_referenced']);
            $this->assertSame('self', $body['referenced_via']);
            $this->assertNotEmpty($body['locked_fields']);
        }
    }

    /**
     * 已軟刪除的草稿仍然是草稿：連到它的邊不能因為它被刪了，就讓計數在訪客面前多出一筆。
     */
    #[TestDox('已軟刪除的草稿和不公開專案，連到它們的邊對訪客仍不計入')]
    public function test_edges_to_soft_deleted_hidden_entities_stay_excluded_for_guests()
    {
        $this->draft->delete();
        $this->hidden->delete();

        $body = $this->getJson("/api/relations/{$this->relation->id}")->assertOk()->json();

        $this->assertSame(4, $body['own_edges_count']);
        $this->assertSame(1, $body['reverse_edges_count']);
    }

    #[TestDox('述詞列表與分類列表的查詢次數不隨筆數成長（訪客）')]
    public function test_guest_lists_do_not_n_plus_one()
    {
        $countQueries = function (string $path): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($path)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // 先有一組有父層的分類，基準線才包含「載入父層」那一次查詢。
        $top = Scope::factory()->create(['class_number' => '91', 'call_number' => '00', 'parent_class' => null]);
        Scope::factory()->create(['class_number' => '91', 'call_number' => '10', 'parent_class' => $top->id]);

        $relationBaseline = $countQueries('/api/relations');
        $scopeBaseline = $countQueries('/api/scopes');

        Relation::factory()->count(15)->create();
        foreach (range(20, 34) as $callNumber) {
            Scope::factory()->create(['class_number' => '91', 'call_number' => (string) $callNumber, 'parent_class' => $top->id]);
        }

        $this->assertSame($relationBaseline, $countQueries('/api/relations'), '述詞列表的查詢數隨筆數成長就是 N+1。');
        $this->assertSame($scopeBaseline, $countQueries('/api/scopes'), '分類列表的查詢數隨筆數成長就是 N+1。');
    }
}
