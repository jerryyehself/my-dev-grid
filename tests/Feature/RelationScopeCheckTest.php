<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    private function send(string $mode, string $own, string $field, int $ownType, int $linkedType, ?Relation $relation = null, bool $linkedDeleted = false): array
    {
        $linkedClass = self::MODELS[Str::singular($field)];
        $linked = $linkedClass::factory()->create(['type' => $linkedType]);

        if ($linkedDeleted) {
            $linked->delete();
        }

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

    /** 回應裡所有驗證錯誤訊息，串成一段文字。 */
    private function allErrorMessages(TestResponse $response): string
    {
        return collect($response->json('errors'))->flatten()->implode("\n");
    }

    /**
     * 已軟刪除的述詞跟不存在的一樣，由 `relation_id` 自己的規則擋下，分類檢查不再跑。
     * （刪除分類時會連帶軟刪除以它為主詞、受詞的述詞，這種述詞是真的會出現的。）
     */
    #[TestDox('述詞已軟刪除時只報 relation_id 無效，不會多報分類不符')]
    public function test_a_soft_deleted_relation_reports_only_the_exists_error(): void
    {
        $this->relation->delete();

        // 分類也不符（主詞端），但述詞已經不能用，不用再報這一則。
        [$response] = $this->send('store', 'documentation', 'techniques', $this->other->id, $this->object->id);

        $response->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
        $this->assertSame(['techniques.0.relation_id'], array_keys($response->json('errors')));
        $this->assertCount(1, $response->json('errors')['techniques.0.relation_id']);
        $this->assertStringNotContainsString('分類', $this->allErrorMessages($response));
    }

    #[DataProvider('linkFields')]
    #[TestDox('連結到的實體已軟刪除時只報 id 無效，不會多報分類不符')]
    public function test_a_soft_deleted_linked_entity_reports_only_the_id_error(string $mode, string $own, string $field, bool $ownIsSubject): void
    {
        // 「連結到的實體」這一端的分類也不符；自己這一端符合。
        [$ownType, $linkedType] = $ownIsSubject
            ? [$this->subject->id, $this->other->id]
            : [$this->object->id, $this->other->id];

        [$response] = $this->send($mode, $own, $field, $ownType, $linkedType, linkedDeleted: true);

        $response->assertUnprocessable()->assertJsonValidationErrors(["{$field}.0.id"]);
        $this->assertSame(["{$field}.0.id"], array_keys($response->json('errors')));
        $this->assertStringNotContainsString('分類', $this->allErrorMessages($response));
    }

    /**
     * 連結的 id 或 relation_id 本身就不合格時，只由 `id`／`relation_id` 自己的規則報錯；
     * 分類檢查找不到可比對的東西就略過，不能丟例外（500）或多報一則分類不符。
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedLinks(): array
    {
        return [
            '缺 id' => [['relation_id' => 'relation'], 'techniques.0.id'],
            '不存在的 id' => [['id' => 999999, 'relation_id' => 'relation'], 'techniques.0.id'],
            'id 是陣列' => [['id' => [1], 'relation_id' => 'relation'], 'techniques.0.id'],
            'relation_id 是陣列' => [['id' => 'technique', 'relation_id' => [1]], 'techniques.0.relation_id'],
            'relation_id 不是數字' => [['id' => 'technique', 'relation_id' => 'abc'], 'techniques.0.relation_id'],
        ];
    }

    /**
     * 布林 `true` 能通過 `integer` 規則，存進去就是 1。分類檢查要把它當 1 檢查，
     * 不能因為「不是整數」就略過，不然分類不符的連結會被寫進資料庫。
     *
     * @return array<string, array{mixed}>
     */
    public static function spellingsOfIdOne(): array
    {
        return ['整數 1' => [1], '布林 true' => [true]];
    }

    #[DataProvider('spellingsOfIdOne')]
    #[TestDox('relation_id 是 1 或 true，分類不符都回 422')]
    public function test_relation_id_spelled_as_true_is_checked_like_id_one(mixed $relationId): void
    {
        // 讓 id 為 1 的述詞是 SubjectRoot -> ObjectRoot；setUp 建的那一條先清掉。
        Relation::withTrashed()->forceDelete();
        Relation::factory()->create(['id' => 1, 'name' => 'idOne', 'subject_id' => $this->subject->id, 'object_id' => $this->object->id]);
        $technique = Technique::factory()->create(['type' => $this->other->id]);

        $response = $this->postJson('/api/documentations', [
            'type' => $this->other->id,
            'title' => 'Own title',
            'techniques' => [['id' => $technique->id, 'relation_id' => $relationId]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
        $this->assertDatabaseMissing('documentations', ['title' => 'Own title']);
    }

    /** @param array<string, mixed> $link */
    #[DataProvider('malformedLinks')]
    #[TestDox('連結的 id 或 relation_id 不合格時，只報那個欄位的錯')]
    public function test_malformed_link_is_left_to_the_field_rules(array $link, string $expectedErrorKey): void
    {
        // 兩端都放不符的分類：分類檢查若沒略過，一定會多報分類不符。
        $technique = Technique::factory()->create(['type' => $this->other->id]);
        $link = array_map(fn ($value) => match ($value) {
            'relation' => $this->relation->id,
            'technique' => $technique->id,
            default => $value,
        }, $link);

        $response = $this->postJson('/api/documentations', [
            'type' => $this->other->id,
            'title' => 'Own title',
            'techniques' => [$link],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$expectedErrorKey]);
        $this->assertCount(1, $response->json('errors')[$expectedErrorKey], '只該有欄位自己的那一則錯。');
        $this->assertStringNotContainsString('必須屬於分類', $this->allErrorMessages($response));
    }

    /**
     * 請求自己的分類拿不到（沒帶、不是數字、null）時，由 `type` 自己的規則報錯，
     * 分類檢查不能拿「未分類」去比對而多報一則。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableOwnTypes(): array
    {
        return [
            '沒帶 type' => [[]],
            'type 不是數字' => [['type' => 'abc']],
            'type 是 null' => [['type' => null]],
        ];
    }

    /** @param array<string, mixed> $typeField */
    #[DataProvider('unusableOwnTypes')]
    #[TestDox('請求自己的 type 不能用時，只報 type 的錯，分類檢查略過')]
    public function test_unusable_own_type_is_left_to_the_type_rule(array $typeField): void
    {
        $technique = Technique::factory()->create(['type' => $this->other->id]);

        $response = $this->postJson('/api/documentations', [
            ...$typeField,
            'title' => 'Own title',
            'techniques' => [['id' => $technique->id, 'relation_id' => $this->relation->id]],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type'])
            ->assertJsonMissingValidationErrors(['techniques.0.relation_id']);
    }

    /**
     * 更新時請求沒帶 `type`，六支更新請求各自要把路由綁定之實體目前的分類交給規則。
     * 路由參數名接錯的話，規則拿到 null 就整個略過，分類不符的連結反而放行。
     *
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function updateLinkFields(): iterable
    {
        foreach (self::linkFields() as $name => $case) {
            if ($case[0] === 'update') {
                yield $name => $case;
            }
        }
    }

    #[DataProvider('updateLinkFields')]
    #[TestDox('更新時沒帶 type，用路由綁定之實體目前的分類比對連結')]
    public function test_update_without_type_checks_against_the_bound_entity_current_type(string $mode, string $own, string $field, bool $ownIsSubject): void
    {
        $linkedClass = self::MODELS[Str::singular($field)];
        $ownClass = self::MODELS[$own];
        [$matchingOwn, $matchingLinked] = $this->ownAndLinkedType($ownIsSubject, $this->subject->id, $this->object->id);

        $putWithoutType = function (int $currentOwnType) use ($linkedClass, $ownClass, $matchingLinked, $field, $own) {
            $linked = $linkedClass::factory()->create(['type' => $matchingLinked]);
            $ownModel = $ownClass::factory()->create(['type' => $currentOwnType]);

            return $this->putJson('/api/'.Str::plural($own)."/{$ownModel->id}", [
                'title' => 'Own title',
                $field => [['id' => $linked->id, 'relation_id' => $this->relation->id]],
            ]);
        };

        $key = "{$field}.0.relation_id";

        // `type` 是必填，所以兩種都會有 type 的錯；差別在連結欄位有沒有被分類擋下。
        $putWithoutType($matchingOwn)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type'])
            ->assertJsonMissingValidationErrors([$key]);

        $mismatching = $putWithoutType($this->other->id);
        $mismatching->assertUnprocessable()->assertJsonValidationErrors(['type', $key]);
        $this->assertStringContainsString('這筆資料', $mismatching->json('errors')[$key][0]);
    }

    /**
     * 刪除分類不會動到底下的實體，所以實體的分類可能是已刪除的；述詞規定的分類也可能已刪除
     * （這裡在刪除之後才用 factory 建述詞，它不會被 `Scope::booted()` 連帶刪掉）。
     * 重點是訊息要查得到已刪除分類的名稱。
     */
    #[TestDox('錯誤訊息裡的分類名稱，已軟刪除的分類也顯示名稱而不是 id')]
    public function test_error_message_names_soft_deleted_scopes(): void
    {
        $goneRequired = Scope::factory()->create(['name' => 'GoneRequired', 'parent_class' => null]);
        $goneActual = Scope::factory()->create(['name' => 'GoneActual', 'parent_class' => null]);
        $goneRequired->delete();
        $goneActual->delete();
        $relation = Relation::factory()->create([
            'name' => 'goneLink',
            'subject_id' => $this->subject->id,
            'object_id' => $goneRequired->id,
        ]);

        // 連結到的技術屬於已刪除的分類 GoneActual，述詞的受詞端規定的是已刪除的 GoneRequired。
        [$response] = $this->send('store', 'documentation', 'techniques', $this->subject->id, $goneActual->id, $relation);

        $response->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
        $message = $response->json('errors')['techniques.0.relation_id'][0];
        $this->assertStringContainsString('「GoneRequired」', $message);
        $this->assertStringContainsString('「GoneActual」', $message);
        $this->assertStringNotContainsString('#', $message);
    }

    #[TestDox('連結變多，讀分類樹的次數不會跟著增加')]
    public function test_scope_tree_reads_do_not_grow_with_the_number_of_links(): void
    {
        $scopeTreeQueries = function (int $links): int {
            $techniques = Technique::factory()->count($links)->create(['type' => $this->object->id]);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson('/api/documentations', [
                'type' => $this->subject->id,
                'title' => 'Own title '.$links,
                'techniques' => $techniques->map(fn (Technique $t) => ['id' => $t->id, 'relation_id' => $this->relation->id])->all(),
            ])->assertCreated();
            $queries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'parent_class'))->count();
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame(1, $scopeTreeQueries(1));
        $this->assertSame(1, $scopeTreeQueries(6), '連結變多，讀分類樹的查詢不能跟著變多。');
    }
}
