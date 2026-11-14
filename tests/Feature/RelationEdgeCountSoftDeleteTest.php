<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\User;
use App\Services\RelationEdgeQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 述詞的邊數不能數到任一端已軟刪除的邊。
 *
 * 邊清單（`/edges`）join 兩端並要求 `deleted_at IS NULL`，已刪除實體的邊不會出現；
 * `own_edges_count`、`reverse_edges_count` 是 `withCount()` 的子查詢，原本直接數連結表的列，
 * 於是實體一刪除，計數就比清單的 `total` 多。這裡守的是兩者永遠一致，訪客與登入後都一樣。
 */
class RelationEdgeCountSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** pivot 連結表與其兩端的實體類別、外鍵欄位。 */
    private const PIVOTS = [
        'documentation_implementation' => [[Documentation::class, 'documentation_id'], [Implementation::class, 'implementation_id']],
        'documentation_technique' => [[Documentation::class, 'documentation_id'], [Technique::class, 'technique_id']],
        'technique_implementation' => [[Technique::class, 'technique_id'], [Implementation::class, 'implementation_id']],
    ];

    /** @return array<string, array{0: string, 1: string}> */
    public static function endpoints(): array
    {
        $cases = [];

        foreach (array_keys(self::PIVOTS) as $table) {
            $cases["{$table} 的主體被刪除"] = [$table, 'subject'];
            $cases["{$table} 的客體被刪除"] = [$table, 'object'];
        }

        foreach (array_keys(EntityRelation::ENTITY_TYPES) as $type) {
            $cases["entity_relations({$type}) 的主體被刪除"] = ['entity_relations:'.$type, 'subject'];
            $cases["entity_relations({$type}) 的客體被刪除"] = ['entity_relations:'.$type, 'object'];
        }

        return $cases;
    }

    private function signIn(): void
    {
        $this->withToken(User::factory()->create()->createToken('test')->plainTextToken);
    }

    /** 建一個「訪客看得到」的實體：已發布的文件、公開的專案。 */
    private function entity(string $class): Model
    {
        return match ($class) {
            Documentation::class => Documentation::factory()->create(['status' => Documentation::STATUS_PUBLISHED]),
            Implementation::class => Implementation::factory()->create(['is_visible' => true]),
            default => $class::factory()->create(),
        };
    }

    /**
     * 在指定的連結表替述詞建一條邊，回傳兩端的實體（subject、object）。
     *
     * @return array{0: Model, 1: Model}
     */
    private function edge(string $table, Relation $relation): array
    {
        if (str_starts_with($table, 'entity_relations:')) {
            $type = substr($table, strlen('entity_relations:'));
            $class = EntityRelation::ENTITY_TYPES[$type];
            $subject = $this->entity($class);
            $object = $this->entity($class);

            EntityRelation::create([
                'entity_type' => $type,
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'relation_id' => $relation->id,
            ]);

            return [$subject, $object];
        }

        [[$subjectClass, $subjectKey], [$objectClass, $objectKey]] = self::PIVOTS[$table];
        $subject = $this->entity($subjectClass);
        $object = $this->entity($objectClass);

        DB::table($table)->insert([
            $subjectKey => $subject->id,
            $objectKey => $object->id,
            'relation_id' => $relation->id,
        ]);

        return [$subject, $object];
    }

    /**
     * 述詞與它的反向，在指定的連結表各有兩條邊：一條兩端都在、一條有一端被刪除。
     * 計數要同時等於兩邊邊清單的 `total`。
     */
    private function assertCountsMatchEdgeList(string $table, string $deletedEnd): void
    {
        $relation = Relation::factory()->create();
        $reverse = Relation::factory()->create();
        $relation->update(['reverse_id' => $reverse->id]);

        foreach ([$relation, $reverse] as $owner) {
            $this->edge($table, $owner);
            $ends = $this->edge($table, $owner);
            $ends[$deletedEnd === 'subject' ? 0 : 1]->delete();
        }

        $edgesTotal = fn (Relation $r) => $this->getJson("/api/relations/{$r->id}/edges")->assertOk()->json('total');
        $this->assertSame(1, $edgesTotal($relation), '邊清單不列已刪除實體的邊。');
        $this->assertSame(1, $edgesTotal($reverse));

        $show = $this->getJson("/api/relations/{$relation->id}")->assertOk()->json();
        $this->assertSame($edgesTotal($relation), $show['own_edges_count'], '詳情的 own_edges_count 要等於邊清單的 total。');
        $this->assertSame($edgesTotal($reverse), $show['reverse_edges_count'], '詳情的 reverse_edges_count 要等於反向邊清單的 total。');

        $rows = collect($this->getJson('/api/relations')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($edgesTotal($relation), $rows[$relation->id]['own_edges_count'], '列表的 own_edges_count 要等於邊清單的 total。');
        $this->assertSame($edgesTotal($reverse), $rows[$relation->id]['reverse_edges_count']);
        $this->assertSame($edgesTotal($reverse), $rows[$reverse->id]['own_edges_count']);
        $this->assertSame($edgesTotal($relation), $rows[$reverse->id]['reverse_edges_count']);
    }

    #[DataProvider('endpoints')]
    #[TestDox('訪客：任一端已軟刪除的邊不計入，且等於邊清單的總數')]
    public function test_guest_counts_exclude_edges_with_a_soft_deleted_endpoint(string $table, string $deletedEnd)
    {
        $this->assertCountsMatchEdgeList($table, $deletedEnd);
    }

    #[DataProvider('endpoints')]
    #[TestDox('登入後：任一端已軟刪除的邊不計入，且等於邊清單的總數')]
    public function test_signed_in_counts_exclude_edges_with_a_soft_deleted_endpoint(string $table, string $deletedEnd)
    {
        $this->signIn();

        $this->assertCountsMatchEdgeList($table, $deletedEnd);
    }

    /**
     * 6 種連結表（3 張 pivot、3 種 `entity_relations`）。
     *
     * @return array<string, array{0: string}>
     */
    public static function tables(): array
    {
        $tables = [
            ...array_keys(self::PIVOTS),
            ...array_map(fn (string $type) => 'entity_relations:'.$type, array_keys(EntityRelation::ENTITY_TYPES)),
        ];

        return array_combine($tables, array_map(fn (string $table) => [$table], $tables));
    }

    /**
     * 還原的測試只挑一張 pivot、一種 `entity_relations`，訪客與登入後各跑一次；
     * 各張表的過濾已經由上面的「任一端已軟刪除」測試逐張守住。
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function restoreCases(): array
    {
        $cases = [];
        foreach (['documentation_technique', 'entity_relations:documentation'] as $table) {
            $cases["訪客 {$table}"] = [$table, false];
            $cases["登入後 {$table}"] = [$table, true];
        }

        return $cases;
    }

    /** @return array<string, array{0: bool}> */
    public static function viewers(): array
    {
        return ['訪客' => [false], '登入後' => [true]];
    }

    /** 邊清單的總數；計數要拿它當標準答案。 */
    private function edgesTotal(Relation $relation): int
    {
        return $this->getJson("/api/relations/{$relation->id}/edges")->assertOk()->json('total');
    }

    /**
     * 同一條邊的端點依序：刪除主體、再刪除客體、只還原主體、再還原客體。
     * 只要有一端還在已刪除的狀態，邊就不能計入；兩端都在才算回來。
     */
    #[DataProvider('restoreCases')]
    #[TestDox('端點軟刪除後又還原，邊從計數消失再回來')]
    public function test_counts_follow_soft_delete_and_restore_of_both_endpoints(string $table, bool $signedIn)
    {
        if ($signedIn) {
            $this->signIn();
        }

        $relation = Relation::factory()->create();
        [$subject, $object] = $this->edge($table, $relation);

        $steps = [
            '剛建立' => [fn () => null, 1],
            '刪除主體' => [fn () => $subject->delete(), 0],
            '兩端都刪除' => [fn () => $object->delete(), 0],
            '只還原主體（客體仍是已刪除）' => [fn () => $subject->restore(), 0],
            '兩端都還原' => [fn () => $object->restore(), 1],
        ];

        foreach ($steps as $label => [$act, $expected]) {
            $act();

            $show = $this->getJson("/api/relations/{$relation->id}")->assertOk()->json();
            $row = collect($this->getJson('/api/relations')->assertOk()->json('data'))->firstWhere('id', $relation->id);

            $this->assertSame($expected, $this->edgesTotal($relation), "{$label}：邊清單的 total。");
            $this->assertSame($expected, $show['own_edges_count'], "{$label}：詳情的 own_edges_count。");
            $this->assertSame($expected, $row['own_edges_count'], "{$label}：列表的 own_edges_count。");
        }
    }

    /**
     * 沒有用 `withCount()` 預載時，`ownEdgesCount()`／`reverseEdgesCount()` 會退回自己查一次。
     * 這條路不經過 controller 的預載，另外守：一樣不能數到已軟刪除端點的邊，且等於邊清單的筆數。
     * 建出來的實體都是訪客與登入後都看得到的，所以只用訪客跑；兩種身分的過濾差異由 `VisibleCountsTest` 守。
     */
    #[DataProvider('tables')]
    #[TestDox('沒預載計數時，退回查詢的邊數也不含已軟刪除端點的邊')]
    public function test_counts_without_preloaded_attributes_exclude_soft_deleted_endpoint_edges(string $table)
    {
        $relation = Relation::factory()->create();
        $reverse = Relation::factory()->create();
        $relation->update(['reverse_id' => $reverse->id]);

        foreach ([$relation, $reverse] as $owner) {
            $this->edge($table, $owner);
            [$subject] = $this->edge($table, $owner);
            $subject->delete();
        }

        $fresh = Relation::findOrFail($relation->id);
        $this->assertSame([], array_filter(array_keys($fresh->getAttributes()), fn (string $key) => str_ends_with($key, '_count')), '前置條件：沒有預載任何計數。');

        $this->assertSame(1, $fresh->ownEdgesCount());
        $this->assertSame((new RelationEdgeQuery($fresh))->count(), $fresh->ownEdgesCount(), '要等於邊清單的筆數。');
        $this->assertSame(1, $fresh->reverseEdgesCount());
    }

    /**
     * 更新端點的回應沒有預載計數（controller 沒呼叫 `loadCount()`），算的是退回查詢的那條路；
     * 回應裡的邊數也要跟邊清單一致。
     */
    #[TestDox('更新述詞的回應，邊數不含已軟刪除端點的邊')]
    public function test_update_response_edge_counts_exclude_soft_deleted_endpoint_edges()
    {
        $this->signIn();

        $relation = Relation::factory()->create();
        // 反向述詞的主體與客體要跟本身對調（`ReverseIsSwapped`），不然更新請求會被驗證擋下。
        $reverse = Relation::factory()->create(['subject_id' => $relation->object_id, 'object_id' => $relation->subject_id]);
        $relation->update(['reverse_id' => $reverse->id]);

        foreach ([$relation, $reverse] as $owner) {
            $this->edge('documentation_technique', $owner);
            [$documentation] = $this->edge('documentation_technique', $owner);
            $documentation->delete();
        }

        $body = $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => $relation->subject_id,
            'object_id' => $relation->object_id,
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
            'name' => $relation->name,
            'note' => '只改備註',
            'reverse_id' => $reverse->id,
        ])->assertOk()->json('data');

        $this->assertSame(1, $body['own_edges_count']);
        $this->assertSame($this->edgesTotal($relation), $body['own_edges_count']);
        $this->assertSame(1, $body['reverse_edges_count']);
    }

    /**
     * `entity_relations` 的兩端沒有外鍵，實體不存在、或 `entity_type` 跟 id 對不上的列都可能存在。
     * 邊清單 join 不到端點，這種列不列出來；計數也不能數。
     */
    #[DataProvider('viewers')]
    #[TestDox('entity_relations 連到不存在或型別對不上的實體時，不計入')]
    public function test_entity_relations_with_missing_or_mismatched_endpoints_are_not_counted(bool $signedIn)
    {
        if ($signedIn) {
            $this->signIn();
        }

        $relation = Relation::factory()->create();

        // `EntityRelation` 的 model event 會擋掉不存在的端點，所以直接寫表，模擬舊資料或繞過 model 的寫入。
        // 端點根本不存在。
        DB::table('entity_relations')->insert([
            'entity_type' => 'documentation',
            'subject_id' => 900001,
            'object_id' => 900002,
            'relation_id' => $relation->id,
        ]);

        // 端點的 id 是存在的技術，但這一列宣稱自己是 documentation，而文件表裡沒有這兩個 id。
        $techniques = Technique::factory()->count(2)->create();
        DB::table('entity_relations')->insert([
            'entity_type' => 'documentation',
            'subject_id' => $techniques[0]->id,
            'object_id' => $techniques[1]->id,
            'relation_id' => $relation->id,
        ]);

        $body = $this->getJson("/api/relations/{$relation->id}")->assertOk()->json();

        $this->assertSame(0, $this->edgesTotal($relation), '前置條件：邊清單不列這兩列。');
        $this->assertSame(0, $body['own_edges_count']);
        $this->assertTrue($body['is_referenced'], '鎖定看的是有沒有列用到這個名字，孤兒列也算。');
    }

    /**
     * 訪客／登入後 × 詳情／列表。
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function viewersByEndpoint(): array
    {
        $cases = [];
        foreach (['訪客' => false, '登入後' => true] as $viewer => $signedIn) {
            foreach (['show', 'index'] as $endpoint) {
                $cases["{$viewer} {$endpoint}"] = [$signedIn, $endpoint];
            }
        }

        return $cases;
    }

    /**
     * 鎖定看的是未過濾的 `*_count`：只剩已軟刪除端點的邊時，計數是 0，但述詞仍是已引用。
     * 自己有邊的那條是 `self`，只有反向有邊的那條是 `reverse`。訪客與登入後、詳情與列表都一樣。
     */
    #[DataProvider('viewersByEndpoint')]
    #[TestDox('只剩已軟刪除端點的邊時，自己與反向都仍是已引用')]
    public function test_relations_with_only_soft_deleted_endpoint_edges_stay_referenced(bool $signedIn, string $endpoint)
    {
        if ($signedIn) {
            $this->signIn();
        }

        $relation = Relation::factory()->create();
        $reverse = Relation::factory()->create();
        $relation->update(['reverse_id' => $reverse->id]);

        [$documentation] = $this->edge('documentation_technique', $relation);
        $documentation->delete();

        $fetch = function (Relation $r) use ($endpoint) {
            if ($endpoint === 'show') {
                return $this->getJson("/api/relations/{$r->id}")->assertOk()->json();
            }

            return collect($this->getJson('/api/relations')->assertOk()->json('data'))->firstWhere('id', $r->id);
        };

        $self = $fetch($relation);
        $this->assertSame(0, $self['own_edges_count']);
        $this->assertTrue($self['is_referenced']);
        $this->assertSame('self', $self['referenced_via']);
        $this->assertSame(Relation::LOCKED_FIELDS, $self['locked_fields']);

        $partner = $fetch($reverse);
        $this->assertSame(0, $partner['own_edges_count']);
        $this->assertSame(0, $partner['reverse_edges_count']);
        $this->assertTrue($partner['is_referenced']);
        $this->assertSame('reverse', $partner['referenced_via']);
        $this->assertSame(Relation::LOCKED_FIELDS, $partner['locked_fields']);
    }

    /**
     * 擋改名的檢查走的是沒預載的 `hasOwnReferences()`（路由綁定拿到的述詞沒有計數）。
     * 端點只是軟刪除、之後可能還原，邊還在，所以名字仍不能改。
     */
    #[TestDox('只剩已軟刪除端點的邊時，改鎖定欄位仍回 422')]
    public function test_locked_fields_stay_locked_when_only_soft_deleted_endpoint_edges_remain()
    {
        $this->signIn();

        $relation = Relation::factory()->create();
        [$documentation] = $this->edge('documentation_technique', $relation);
        $documentation->delete();

        $this->putJson("/api/relations/{$relation->id}", [
            'subject_id' => $relation->subject_id,
            'object_id' => $relation->object_id,
            'class_number' => $relation->class_number,
            'call_number' => $relation->call_number,
            'name' => $relation->name.'Renamed',
            'note' => $relation->note,
            'reverse_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $this->assertSame($relation->name, $relation->fresh()->name);
    }

    #[TestDox('述詞列表的查詢次數不隨筆數成長（登入後）')]
    public function test_signed_in_relation_index_does_not_n_plus_one()
    {
        $this->signIn();

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/relations')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // 先放幾條有邊的述詞，基準線才包含「載入父層、主體、客體」那幾次查詢。
        foreach (Relation::factory()->count(2)->create() as $relation) {
            $this->edge('documentation_technique', $relation);
        }
        // 第一個請求要多查 token 與使用者（驗證身分），先送一次暖身，免得算進基準線。
        $countQueries();
        $baseline = $countQueries();

        foreach (Relation::factory()->count(15)->create() as $relation) {
            $this->edge('documentation_technique', $relation);
        }

        $this->assertSame($baseline, $countQueries(), '登入後的述詞列表查詢數隨筆數成長就是 N+1。');
    }
}
