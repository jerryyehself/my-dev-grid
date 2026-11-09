<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\User;
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
     * 鎖定看的是「有沒有邊用到這個名字」：端點只是軟刪除、可以還原，邊還在，
     * 所以計數雖然變 0，述詞仍然是已引用，欄位仍然鎖住。
     */
    #[TestDox('只剩連到已軟刪除實體的邊時，計數為 0 但述詞仍是已引用')]
    public function test_a_relation_with_only_soft_deleted_endpoint_edges_stays_referenced()
    {
        $this->signIn();

        $relation = Relation::factory()->create();
        [$documentation] = $this->edge('documentation_technique', $relation);
        $documentation->delete();

        $body = $this->getJson("/api/relations/{$relation->id}")->assertOk()->json();

        $this->assertSame(0, $body['own_edges_count']);
        $this->assertTrue($body['is_referenced']);
        $this->assertNotEmpty($body['locked_fields']);
    }
}
