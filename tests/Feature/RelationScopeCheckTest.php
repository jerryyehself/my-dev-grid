<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 建立／更新連結時，兩端實體的分類（Scope）要符合述詞（Relation）的主詞與受詞。
 *
 * 六支 Store／Update 請求共用同一條規則（`RelationFitsLinkedScopes`），這裡每個請求的每個連結欄位
 * 都跑一遍，確認「哪一端是主詞」沒有接反：連結表 `documentation_technique`、`documentation_implementation`
 * 的主詞是文件，`technique_implementation` 的主詞是技術。
 */
class RelationScopeCheckTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS = [
        'documentation' => Documentation::class,
        'technique' => Technique::class,
        'implementation' => Implementation::class,
    ];

    private Scope $subject;

    private Scope $subjectChild;

    private Scope $subjectGrandchild;

    private Scope $object;

    private Scope $objectChild;

    private Scope $other;

    private Relation $relation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsOwner();

        // 兩棵獨立的分類樹：主詞這棵三層，受詞這棵兩層，另外一個不相干的分類
        $this->subject = Scope::factory()->create(['name' => 'SubjectRoot', 'parent_class' => null]);
        $this->subjectChild = Scope::factory()->create(['name' => 'SubjectChild', 'parent_class' => $this->subject->id]);
        $this->subjectGrandchild = Scope::factory()->create(['name' => 'SubjectGrandchild', 'parent_class' => $this->subjectChild->id]);
        $this->object = Scope::factory()->create(['name' => 'ObjectRoot', 'parent_class' => null]);
        $this->objectChild = Scope::factory()->create(['name' => 'ObjectChild', 'parent_class' => $this->object->id]);
        $this->other = Scope::factory()->create(['name' => 'Unrelated', 'parent_class' => null]);

        $this->relation = Relation::factory()->create([
            'name' => 'linksTo',
            'subject_id' => $this->subject->id,
            'object_id' => $this->object->id,
        ]);
    }

    /**
     * 六支請求的連結欄位 × 新增／更新。
     *
     * 欄位：[請求自己的實體, 連結欄位, 請求自己的實體是不是主詞端]
     *
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function linkFields(): iterable
    {
        $fields = [
            ['documentation', 'techniques', true],
            ['documentation', 'implementations', true],
            ['implementation', 'documentations', false],
            ['implementation', 'techniques', false],
            ['technique', 'documentations', false],
            ['technique', 'implementations', true],
        ];

        foreach (['store', 'update'] as $mode) {
            foreach ($fields as [$own, $field, $ownIsSubject]) {
                yield "{$mode} {$own} {$field}" => [$mode, $own, $field, $ownIsSubject];
            }
        }
    }

    /**
     * 把 linkFields() 再乘上「哪一端不符」。
     *
     * @return iterable<string, array{string, string, string, bool, string}>
     */
    public static function linkFieldsWithFailingEnd(): iterable
    {
        foreach (self::linkFields() as $name => $case) {
            foreach (['subject', 'object'] as $failingEnd) {
                yield "{$name}, {$failingEnd} end mismatches" => [...$case, $failingEnd];
            }
        }
    }

    /**
     * 送出一個帶一筆連結的請求。
     *
     * 更新時先建好請求自己的實體（分類是 $ownType），再 PUT；新增時直接 POST。
     *
     * @return array{0: TestResponse, 1: Model} [回應, 連結到的實體]
     */
    private function send(string $mode, string $own, string $field, int $ownType, int $linkedType, ?Relation $relation = null): array
    {
        $linkedClass = self::MODELS[Str::singular($field)];
        $linked = $linkedClass::factory()->create(['type' => $linkedType]);
        $payload = [
            'type' => $ownType,
            'title' => 'Own title',
            $field => [['id' => $linked->id, 'relation_id' => ($relation ?? $this->relation)->id]],
        ];
        $path = '/api/'.Str::plural($own);

        if ($mode === 'store') {
            return [$this->postJson($path, $payload), $linked];
        }

        $ownModel = self::MODELS[$own]::factory()->create(['type' => $ownType]);

        return [$this->putJson("{$path}/{$ownModel->id}", $payload), $linked];
    }

    /** 主詞端、受詞端各自的分類，換算成「請求自己的實體」與「連結的實體」各是哪個分類。 */
    private function ownAndLinkedType(bool $ownIsSubject, int $subjectEndType, int $objectEndType): array
    {
        return $ownIsSubject ? [$subjectEndType, $objectEndType] : [$objectEndType, $subjectEndType];
    }

    private function assertLinked(TestResponse $response, string $own, string $field, Model $linked): void
    {
        $ownModel = self::MODELS[$own]::findOrFail($response->json('data.id'));

        $this->assertTrue($ownModel->{$field}()->whereKey($linked->id)->exists());
    }

    #[DataProvider('linkFields')]
    #[TestDox('兩端分類剛好等於述詞規定的分類時，連結成功')]
    public function test_exact_scope_match_passes(string $mode, string $own, string $field, bool $ownIsSubject): void
    {
        [$ownType, $linkedType] = $this->ownAndLinkedType($ownIsSubject, $this->subject->id, $this->object->id);

        [$response, $linked] = $this->send($mode, $own, $field, $ownType, $linkedType);

        $response->assertSuccessful();
        $this->assertLinked($response, $own, $field, $linked);
    }

    #[DataProvider('linkFields')]
    #[TestDox('兩端分類都是述詞規定分類的子分類時，連結成功')]
    public function test_subclass_scope_match_passes(string $mode, string $own, string $field, bool $ownIsSubject): void
    {
        [$ownType, $linkedType] = $this->ownAndLinkedType($ownIsSubject, $this->subjectChild->id, $this->objectChild->id);

        [$response, $linked] = $this->send($mode, $own, $field, $ownType, $linkedType);

        $response->assertSuccessful();
        $this->assertLinked($response, $own, $field, $linked);
    }

    #[DataProvider('linkFields')]
    #[TestDox('主詞端是孫分類（隔了兩層）時，連結成功')]
    public function test_grandchild_scope_match_passes(string $mode, string $own, string $field, bool $ownIsSubject): void
    {
        [$ownType, $linkedType] = $this->ownAndLinkedType($ownIsSubject, $this->subjectGrandchild->id, $this->object->id);

        [$response, $linked] = $this->send($mode, $own, $field, $ownType, $linkedType);

        $response->assertSuccessful();
        $this->assertLinked($response, $own, $field, $linked);
    }

    #[DataProvider('linkFieldsWithFailingEnd')]
    #[TestDox('其中一端的分類不符時回 422，錯誤指出是哪一筆連結、哪一端、要什麼分類')]
    public function test_scope_mismatch_returns_422_naming_the_link_and_end(string $mode, string $own, string $field, bool $ownIsSubject, string $failingEnd): void
    {
        $subjectEnd = $failingEnd === 'subject' ? $this->other->id : $this->subject->id;
        $objectEnd = $failingEnd === 'object' ? $this->other->id : $this->object->id;
        [$ownType, $linkedType] = $this->ownAndLinkedType($ownIsSubject, $subjectEnd, $objectEnd);

        [$response, $linked] = $this->send($mode, $own, $field, $ownType, $linkedType);

        $key = "{$field}.0.relation_id";
        $response->assertUnprocessable()->assertJsonValidationErrors([$key]);

        $messages = $response->json('errors')[$key];
        $this->assertCount(1, $messages);
        $expectedEnd = $failingEnd === 'subject' ? '主詞端' : '受詞端';
        $expectedRequired = $failingEnd === 'subject' ? 'SubjectRoot' : 'ObjectRoot';
        $failingIsOwn = ($failingEnd === 'subject') === $ownIsSubject;
        $expectedWho = $failingIsOwn ? '這筆資料' : '所連結的 '.class_basename($linked)."（id {$linked->id}）";
        foreach (["{$field}.0", '「linksTo」', $expectedEnd, "「{$expectedRequired}」", $expectedWho, '「Unrelated」'] as $expected) {
            $this->assertStringContainsString($expected, $messages[0]);
        }

        $this->assertFalse($linked->{Str::plural($own)}()->exists(), '驗證失敗不能寫入連結');
    }

    #[DataProvider('linkFields')]
    #[TestDox('主詞受詞兩端放反時回 422，兩端各報一則')]
    public function test_swapped_ends_return_422_with_both_ends_reported(string $mode, string $own, string $field, bool $ownIsSubject): void
    {
        // 述詞要 SubjectRoot -> ObjectRoot，這裡主詞端給 ObjectRoot、受詞端給 SubjectRoot
        [$ownType, $linkedType] = $this->ownAndLinkedType($ownIsSubject, $this->object->id, $this->subject->id);

        [$response] = $this->send($mode, $own, $field, $ownType, $linkedType);

        $key = "{$field}.0.relation_id";
        $response->assertUnprocessable()->assertJsonValidationErrors([$key]);
        $messages = $response->json('errors')[$key];
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('主詞端', $messages[0]);
        $this->assertStringContainsString('受詞端', $messages[1]);
    }

    #[TestDox('父類不能當作子分類的實體用：述詞規定子分類時，父類的實體回 422')]
    public function test_parent_scope_does_not_satisfy_a_subclass_requirement(): void
    {
        $relation = Relation::factory()->create([
            'name' => 'narrowLink',
            'subject_id' => $this->subjectChild->id,
            'object_id' => $this->object->id,
        ]);

        [$response] = $this->send('store', 'documentation', 'techniques', $this->subject->id, $this->object->id, $relation);

        $response->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
    }

    #[TestDox('多筆連結中只有第二筆不符時，錯誤只指向第二筆')]
    public function test_only_the_failing_link_index_is_reported(): void
    {
        $good = Technique::factory()->create(['type' => $this->object->id]);
        $bad = Technique::factory()->create(['type' => $this->other->id]);

        $response = $this->postJson('/api/documentations', [
            'type' => $this->subjectChild->id,
            'title' => 'Own title',
            'techniques' => [
                ['id' => $good->id, 'relation_id' => $this->relation->id],
                ['id' => $bad->id, 'relation_id' => $this->relation->id],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['techniques.1.relation_id'])
            ->assertJsonMissingValidationErrors(['techniques.0.relation_id']);
        $this->assertStringContainsString('techniques.1', $response->json('errors')['techniques.1.relation_id'][0]);
        $this->assertDatabaseMissing('documentations', ['title' => 'Own title']);
    }

    #[TestDox('更新時請求沒帶 type，改用路由綁定之實體目前的分類來比對')]
    public function test_update_without_type_falls_back_to_the_bound_entity_type(): void
    {
        $technique = Technique::factory()->create(['type' => $this->object->id]);
        $matching = Documentation::factory()->create(['type' => $this->subjectChild->id]);
        $mismatching = Documentation::factory()->create(['type' => $this->other->id]);
        $payload = [
            'title' => 'Own title',
            'techniques' => [['id' => $technique->id, 'relation_id' => $this->relation->id]],
        ];

        // type 本身是必填，所以兩個都會有 type 的錯；差別在連結欄位有沒有被分類擋下
        $this->putJson("/api/documentations/{$matching->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type'])
            ->assertJsonMissingValidationErrors(['techniques.0.relation_id']);

        $this->putJson("/api/documentations/{$mismatching->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'techniques.0.relation_id']);
    }

    #[TestDox('更新時一併改了 type，以請求裡的新分類為準')]
    public function test_update_uses_the_type_in_the_payload_over_the_current_one(): void
    {
        $technique = Technique::factory()->create(['type' => $this->object->id]);
        $link = [['id' => $technique->id, 'relation_id' => $this->relation->id]];

        // 原本不符、這次改成符合的分類：成功
        $wasMismatching = Documentation::factory()->create(['type' => $this->other->id]);
        $this->putJson("/api/documentations/{$wasMismatching->id}", ['type' => $this->subject->id, 'title' => 'Own title', 'techniques' => $link])
            ->assertOk();

        // 原本符合、這次改成不符的分類：擋下
        $wasMatching = Documentation::factory()->create(['type' => $this->subject->id]);
        $this->putJson("/api/documentations/{$wasMatching->id}", ['type' => $this->other->id, 'title' => 'Own title', 'techniques' => $link])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['techniques.0.relation_id']);
    }

    #[TestDox('分類的父類繞成環時，驗證照樣收尾並回 422')]
    public function test_scope_parent_cycle_does_not_hang_validation(): void
    {
        $a = Scope::factory()->create(['name' => 'CycleA', 'parent_class' => null]);
        $b = Scope::factory()->create(['name' => 'CycleB', 'parent_class' => $a->id]);
        $a->update(['parent_class' => $b->id]);

        [$response] = $this->send('store', 'documentation', 'techniques', $a->id, $this->object->id);

        $response->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
    }

    #[TestDox('中間那一層分類已軟刪除時，往上的路徑不會斷')]
    public function test_soft_deleted_intermediate_scope_does_not_break_the_walk(): void
    {
        $this->subjectChild->delete();

        [$response, $linked] = $this->send('store', 'documentation', 'techniques', $this->subjectGrandchild->id, $this->object->id);

        $response->assertCreated();
        $this->assertLinked($response, 'documentation', 'techniques', $linked);
    }

    #[TestDox('不存在的 relation_id 只報 exists 的錯，不會多報分類不符')]
    public function test_unknown_relation_id_reports_only_the_exists_error(): void
    {
        $technique = Technique::factory()->create(['type' => $this->other->id]);

        $response = $this->postJson('/api/documentations', [
            'type' => $this->other->id,
            'title' => 'Own title',
            'techniques' => [['id' => $technique->id, 'relation_id' => 999999]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
        $this->assertCount(1, $response->json('errors')['techniques.0.relation_id']);
        $this->assertStringNotContainsString('分類', $response->json('errors')['techniques.0.relation_id'][0]);
    }
}
