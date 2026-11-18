<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Database\Seeders\RelationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\TestCase;

/**
 * 述詞的棄用：`PATCH /api/relations/{relation}/deprecation`。
 *
 * 已經被邊用到的述詞不能刪，要換掉時改標為棄用，可以指定改用對象。棄用記下時間；成對的反向
 * 一起棄用，改用對象對應到改用對象的反向。改用對象的主詞與受詞分類要跟原述詞相同。
 */
class RelationDeprecationTest extends TestCase
{
    use CapturesSecurityEvents;
    use RefreshDatabase;

    private Scope $doc;

    private Scope $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = Scope::factory()->create(['name' => 'docScope']);
        $this->tech = Scope::factory()->create(['name' => 'techScope']);
        Carbon::setTestNow('2026-11-18 09:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 一對互為反向的述詞：A 是 主詞→受詞，A′ 對調。
     *
     * @return array{0: Relation, 1: Relation}
     */
    private function pair(string $name, ?Scope $subject = null, ?Scope $object = null): array
    {
        $subject ??= $this->doc;
        $object ??= $this->tech;
        $a = Relation::factory()->create(['name' => $name, 'subject_id' => $subject->id, 'object_id' => $object->id]);
        $b = Relation::factory()->create(['name' => $name.'Rev', 'subject_id' => $object->id, 'object_id' => $subject->id, 'reverse_id' => $a->id]);

        return [$a->fresh(), $b->fresh()];
    }

    private function symmetric(string $name): Relation
    {
        $relation = Relation::factory()->create(['name' => $name, 'subject_id' => $this->tech->id, 'object_id' => $this->tech->id]);
        $relation->update(['reverse_id' => $relation->id]);

        return $relation->fresh();
    }

    private function single(string $name, ?Scope $subject = null, ?Scope $object = null): Relation
    {
        return Relation::factory()->create(['name' => $name, 'subject_id' => ($subject ?? $this->doc)->id, 'object_id' => ($object ?? $this->tech)->id]);
    }

    /** @param  array<string, mixed>  $body */
    private function deprecation(Relation $relation, array $body)
    {
        return $this->patchJson("/api/relations/{$relation->id}/deprecation", $body);
    }

    #[TestDox('沒登入回 401，述詞不變')]
    public function test_guest_cannot_deprecate()
    {
        [$a] = $this->pair('specsLike');

        $this->deprecation($a, ['deprecated' => true])->assertUnauthorized();

        $this->assertNull($a->fresh()->deprecated_at);
    }

    #[TestDox('棄用記下時間，API 回 deprecated 與 deprecated_at')]
    public function test_deprecating_records_the_time()
    {
        $this->actingAsOwner();
        [$a] = $this->pair('specsLike');

        $this->deprecation($a, ['deprecated' => true])
            ->assertOk()
            ->assertJsonPath('data.deprecated', true)
            ->assertJsonPath('data.deprecated_at', '2026-11-18 09:30:00')
            ->assertJsonPath('data.replaced_by_id', null)
            ->assertJsonPath('data.replaced_by', null);

        $this->assertSame('2026-11-18 09:30:00', $a->fresh()->deprecated_at->format('Y-m-d H:i:s'));
    }

    #[TestDox('已經被邊用到的述詞也可以棄用（鎖定欄位不擋），邊不受影響')]
    public function test_a_referenced_relation_can_be_deprecated()
    {
        $this->actingAsOwner();
        [$a] = $this->pair('specsLike');
        $documentation = Documentation::factory()->create(['type' => $this->doc->id]);
        $technique = Technique::factory()->create(['type' => $this->tech->id]);
        $documentation->techniques()->attach($technique->id, ['relation_id' => $a->id]);
        $this->assertTrue($a->fresh()->isReferenced(), '前置條件：述詞已被引用。');

        $this->deprecation($a, ['deprecated' => true])->assertOk()->assertJsonPath('data.deprecated', true);

        $this->assertDatabaseHas('documentation_technique', ['documentation_id' => $documentation->id, 'technique_id' => $technique->id, 'relation_id' => $a->id]);
    }

    #[TestDox('反向一起棄用，改用對象對應到改用對象的反向')]
    public function test_reverse_is_deprecated_together_and_maps_to_the_replacements_reverse()
    {
        $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        [$x, $xRev] = $this->pair('newSpecs');

        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])
            ->assertOk()
            ->assertJsonPath('data.replaced_by_id', $x->id)
            ->assertJsonPath('data.replaced_by', ['id' => $x->id, 'name' => 'newSpecs']);

        $a->refresh();
        $aRev->refresh();
        $this->assertTrue($a->isDeprecated());
        $this->assertTrue($aRev->isDeprecated());
        $this->assertEquals($a->deprecated_at, $aRev->deprecated_at);
        $this->assertSame($x->id, $a->replaced_by_id);
        $this->assertSame($xRev->id, $aRev->replaced_by_id);
        $this->assertFalse($x->fresh()->isDeprecated(), '改用對象本身不受影響。');
    }

    #[TestDox('從反向那一條棄用，結果一樣成對')]
    public function test_deprecating_from_the_reverse_side_is_symmetric()
    {
        $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        [$x, $xRev] = $this->pair('newSpecs');

        $this->deprecation($aRev, ['deprecated' => true, 'replaced_by_id' => $xRev->id])->assertOk();

        $this->assertSame($x->id, $a->fresh()->replaced_by_id);
        $this->assertSame($xRev->id, $aRev->fresh()->replaced_by_id);
    }

    #[TestDox('對稱述詞只改自己，改用對象也要是對稱的')]
    public function test_symmetric_relation_is_deprecated_alone()
    {
        $this->actingAsOwner();
        $a = $this->symmetric('accompaniesLike');
        $x = $this->symmetric('pairsWith');

        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])->assertOk();

        $this->assertSame($x->id, $a->fresh()->replaced_by_id);
        $this->assertSame($a->id, $a->fresh()->reverse_id);
        $this->assertFalse($x->fresh()->isDeprecated());
    }

    #[TestDox('取消棄用：兩邊一起清掉時間與改用對象')]
    public function test_undeprecate_clears_both_sides()
    {
        $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        [$x] = $this->pair('newSpecs');
        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])->assertOk();

        $this->deprecation($a, ['deprecated' => false])
            ->assertOk()
            ->assertJsonPath('data.deprecated', false)
            ->assertJsonPath('data.deprecated_at', null)
            ->assertJsonPath('data.replaced_by_id', null);

        foreach ([$a->fresh(), $aRev->fresh()] as $relation) {
            $this->assertNull($relation->deprecated_at);
            $this->assertNull($relation->replaced_by_id);
        }
    }

    #[TestDox('取消棄用時不能帶改用對象')]
    public function test_undeprecate_with_a_replacement_is_rejected()
    {
        $this->actingAsOwner();
        [$a] = $this->pair('oldSpecs');
        [$x] = $this->pair('newSpecs');

        $this->deprecation($a, ['deprecated' => false, 'replaced_by_id' => $x->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replaced_by_id' => '取消棄用時不能指定改用的述詞。']);
    }

    #[TestDox('重送同樣的棄用回 200，棄用時間維持第一次的；可以換改用對象')]
    public function test_resending_keeps_the_first_deprecation_time()
    {
        $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        [$x] = $this->pair('newSpecs');
        [$y, $yRev] = $this->pair('newerSpecs');
        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])->assertOk();

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])
            ->assertOk()
            ->assertJsonPath('data.deprecated_at', '2026-11-18 09:30:00');
        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $y->id])
            ->assertOk()
            ->assertJsonPath('data.deprecated_at', '2026-11-18 09:30:00');

        $this->assertSame($y->id, $a->fresh()->replaced_by_id);
        $this->assertSame($yRev->id, $aRev->fresh()->replaced_by_id);
    }

    /** @return iterable<string, array{string}> */
    public static function mismatchedReplacements(): iterable
    {
        yield '主詞分類不同' => ['subject'];
        yield '受詞分類不同' => ['object'];
        yield '主詞受詞對調' => ['swapped'];
    }

    #[DataProvider('mismatchedReplacements')]
    #[TestDox('改用對象的主詞或受詞分類不同時回 422，什麼都不改')]
    public function test_replacement_with_a_class_mismatch_is_rejected(string $case)
    {
        $this->actingAsOwner();
        $other = Scope::factory()->create(['name' => 'otherScope']);
        [$a, $aRev] = $this->pair('oldSpecs');
        [$x] = match ($case) {
            'subject' => $this->pair('newSpecs', $other, $this->tech),
            'object' => $this->pair('newSpecs', $this->doc, $other),
            'swapped' => $this->pair('newSpecs', $this->tech, $this->doc),
        };

        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replaced_by_id' => '改用的述詞「newSpecs」主詞與受詞的分類要跟「oldSpecs」相同。']);

        $this->assertNull($a->fresh()->deprecated_at);
        $this->assertNull($aRev->fresh()->deprecated_at);
    }

    #[TestDox('改用自己或自己的反向回 422')]
    public function test_replacement_cannot_be_itself_or_its_reverse()
    {
        $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        $symmetric = $this->symmetric('accompaniesLike');

        foreach ([[$a, $a], [$a, $aRev], [$symmetric, $symmetric]] as [$relation, $replacement]) {
            $this->deprecation($relation, ['deprecated' => true, 'replaced_by_id' => $replacement->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['replaced_by_id' => "不能改用「{$relation->name}」自己或它的反向述詞。"]);
        }

        $this->assertNull($a->fresh()->deprecated_at);
    }

    #[TestDox('改用已棄用的述詞回 422（所以「改用」不會繞成環）')]
    public function test_replacement_cannot_be_deprecated()
    {
        $this->actingAsOwner();
        [$a] = $this->pair('oldSpecs');
        [$x] = $this->pair('newSpecs');
        [$y] = $this->pair('newerSpecs');
        $this->deprecation($x, ['deprecated' => true, 'replaced_by_id' => $y->id])->assertOk();

        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replaced_by_id' => '述詞「newSpecs」也已棄用，請改用仍在使用的述詞。']);

        // 想做出環：Y 改用 X（X 已棄用）——同一條規則擋下
        $this->deprecation($y, ['deprecated' => true, 'replaced_by_id' => $x->id])->assertUnprocessable();
    }

    /** @return iterable<string, array{string, string}> */
    public static function shapeMismatches(): iterable
    {
        yield '有反向 → 沒有反向' => ['paired', 'none'];
        yield '有反向 → 對稱' => ['paired', 'symmetric'];
        yield '沒有反向 → 有反向' => ['none', 'paired'];
        yield '對稱 → 有反向' => ['symmetric', 'paired'];
    }

    #[DataProvider('shapeMismatches')]
    #[TestDox('改用對象的反向形狀不同時回 422')]
    public function test_replacement_with_a_different_reverse_shape_is_rejected(string $from, string $to)
    {
        $this->actingAsOwner();
        // 對稱述詞的主詞受詞相同，兩邊都用 tech→tech 才不會先被分類規則擋下
        $make = fn (string $shape, string $name) => match ($shape) {
            'paired' => $this->pair($name, $this->tech, $this->tech)[0],
            'none' => $this->single($name, $this->tech, $this->tech),
            'symmetric' => $this->symmetric($name),
        };
        $a = $make($from, 'oldOne');
        $x = $make($to, 'newOne');

        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('replaced_by_id');

        $this->assertNull($a->fresh()->deprecated_at);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidReplacementIds(): iterable
    {
        yield '不存在的 id' => [999999];
        yield '字串' => ['abc'];
        yield '小數' => [1.5];
    }

    #[DataProvider('invalidReplacementIds')]
    #[TestDox('改用對象不是存在的述詞 id 時回 422')]
    public function test_invalid_replacement_ids_are_rejected(mixed $id)
    {
        $this->actingAsOwner();
        [$a] = $this->pair('oldSpecs');

        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('replaced_by_id');
    }

    #[TestDox('改用對象已被刪除時回 422')]
    public function test_soft_deleted_replacement_is_rejected()
    {
        $this->actingAsOwner();
        [$a] = $this->pair('oldSpecs');
        $x = $this->single('gone');
        $x->delete();

        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('replaced_by_id');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeprecatedFlags(): iterable
    {
        yield '沒帶' => [null];
        yield '字串' => ['yes'];
        yield '陣列' => [[true]];
    }

    #[DataProvider('invalidDeprecatedFlags')]
    #[TestDox('deprecated 沒帶或不是布林時回 422')]
    public function test_deprecated_flag_is_required_and_boolean(mixed $flag)
    {
        $this->actingAsOwner();
        [$a] = $this->pair('oldSpecs');

        $this->deprecation($a, $flag === null ? [] : ['deprecated' => $flag])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('deprecated');

        $this->assertNull($a->fresh()->deprecated_at);
    }

    #[TestDox('不存在或已刪除的述詞回 404')]
    public function test_missing_relation_is_404()
    {
        $this->actingAsOwner();
        $gone = $this->single('gone');
        $gone->delete();

        $this->patchJson('/api/relations/999999/deprecation', ['deprecated' => true])->assertNotFound();
        $this->deprecation($gone, ['deprecated' => true])->assertNotFound();
    }

    #[TestDox('同步或匯入用到的述詞可以棄用，但一定要指定改用對象（含它的反向）')]
    public function test_system_predicates_require_a_replacement()
    {
        $this->actingAsOwner();
        [$usedBy, $uses] = $this->pair('usedBy', $this->tech, $this->doc);
        [$x, $xRev] = $this->pair('employedBy', $this->tech, $this->doc);

        foreach ([$usedBy, $uses] as $relation) {
            $this->deprecation($relation, ['deprecated' => true])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['replaced_by_id' => '這條述詞（或它的反向）是同步或文章匯入取用述詞時會經過的，棄用時必須指定改用的述詞，已棄用的也不能把改用對象清掉。']);
            $this->deprecation($relation, ['deprecated' => true, 'replaced_by_id' => null])->assertUnprocessable();
        }

        $this->deprecation($usedBy, ['deprecated' => true, 'replaced_by_id' => $x->id])->assertOk();
        $this->assertSame($x->id, $usedBy->fresh()->replaced_by_id);
        $this->assertSame($xRev->id, $uses->fresh()->replaced_by_id);

        // 改用對象現在被同步使用，它要棄用也一樣要指定改用對象
        $this->deprecation($x, ['deprecated' => true])->assertUnprocessable()->assertJsonValidationErrorFor('replaced_by_id');
    }

    #[TestDox('PUT 整份表單（舊版前端不帶棄用欄位）不會清掉棄用')]
    public function test_full_form_update_keeps_the_deprecation()
    {
        $user = $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        [$x] = $this->pair('newSpecs');
        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])->assertOk();

        $this->putJson("/api/relations/{$a->id}", [
            'subject_id' => $a->subject_id,
            'object_id' => $a->object_id,
            'class_number' => $a->class_number,
            'call_number' => $a->call_number,
            'name' => $a->name,
            'note' => '改了備註',
            'reverse_id' => $aRev->id,
            // 就算有人試著送，也不在 validated() 裡
            'deprecated_at' => null,
            'replaced_by_id' => null,
        ])->assertOk();

        $a->refresh();
        $this->assertSame('改了備註', $a->note);
        $this->assertTrue($a->isDeprecated());
        $this->assertSame($x->id, $a->replaced_by_id);
    }

    /** @return iterable<string, array{string}> */
    public static function reverseChanges(): iterable
    {
        yield '清空反向' => ['null'];
        yield '換成別條' => ['other'];
    }

    #[DataProvider('reverseChanges')]
    #[TestDox('已棄用的述詞不能更換反向（兩邊棄用狀態要一致）')]
    public function test_deprecated_relation_cannot_change_its_reverse(string $change)
    {
        $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        $other = $this->single('lonely', $this->tech, $this->doc);
        $this->deprecation($a, ['deprecated' => true])->assertOk();

        $this->putJson("/api/relations/{$a->id}", [
            'subject_id' => $a->subject_id,
            'object_id' => $a->object_id,
            'class_number' => $a->class_number,
            'call_number' => $a->call_number,
            'name' => $a->name,
            'reverse_id' => $change === 'null' ? null : $other->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reverse_id' => '述詞「oldSpecs」已棄用，不能更換反向述詞；請先取消棄用。']);

        $this->assertSame($aRev->id, $a->fresh()->reverse_id);
        $this->assertSame($a->id, $aRev->fresh()->reverse_id);
    }

    #[TestDox('使用中的述詞不能把已棄用的述詞設為反向（新增與修改都擋）')]
    public function test_a_deprecated_relation_cannot_become_a_new_reverse()
    {
        $this->actingAsOwner();
        $deprecated = $this->single('retired', $this->tech, $this->doc);
        $this->deprecation($deprecated, ['deprecated' => true])->assertOk();
        $live = $this->single('live');

        $this->putJson("/api/relations/{$live->id}", [
            'subject_id' => $live->subject_id,
            'object_id' => $live->object_id,
            'class_number' => $live->class_number,
            'call_number' => $live->call_number,
            'name' => $live->name,
            'reverse_id' => $deprecated->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reverse_id' => '關係「retired」已棄用，不能當這一筆的反向。']);

        $this->postJson('/api/relations', [
            'subject_id' => $this->doc->id,
            'object_id' => $this->tech->id,
            'class_number' => '77',
            'call_number' => null,
            'name' => 'brandNew',
            'reverse_id' => $deprecated->id,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('reverse_id');

        $this->assertNull($deprecated->fresh()->reverse_id);
    }

    #[TestDox('清單與單筆都標出棄用狀態；已棄用的仍列在清單裡')]
    public function test_index_and_show_mark_deprecated_relations()
    {
        $this->actingAsOwner();
        [$a] = $this->pair('oldSpecs');
        [$x] = $this->pair('newSpecs');
        $this->deprecation($a, ['deprecated' => true, 'replaced_by_id' => $x->id])->assertOk();
        auth()->forgetGuards();

        $list = collect($this->getJson('/api/relations')->assertOk()->json('data'))->keyBy('name');
        $this->assertCount(4, $list, '已棄用的述詞仍列在清單裡（既有連結要靠它顯示名稱）。');
        $this->assertTrue($list['oldSpecs']['deprecated']);
        $this->assertSame('2026-11-18 09:30:00', $list['oldSpecs']['deprecated_at']);
        $this->assertSame(['id' => $x->id, 'name' => 'newSpecs'], $list['oldSpecs']['replaced_by']);
        $this->assertTrue($list['oldSpecsRev']['deprecated']);
        $this->assertSame('newSpecsRev', $list['oldSpecsRev']['replaced_by']['name']);
        $this->assertFalse($list['newSpecs']['deprecated']);
        $this->assertNull($list['newSpecs']['replaced_by']);

        $this->getJson("/api/relations/{$a->id}")
            ->assertOk()
            ->assertJsonPath('deprecated', true)
            ->assertJsonPath('replaced_by', ['id' => $x->id, 'name' => 'newSpecs']);
    }

    #[TestDox('棄用兩筆都記稽核紀錄')]
    public function test_both_sides_are_audited()
    {
        $user = $this->actingAsOwner();
        [$a, $aRev] = $this->pair('oldSpecs');
        $this->captureLogs();

        $this->deprecation($a, ['deprecated' => true])->assertOk();

        $updates = collect($this->securityEvents('authz_admin'))
            ->map(fn ($m) => $m->context)
            ->where('action', 'update')
            ->where('object', 'relation');
        $this->assertEqualsCanonicalizing([$a->id, $aRev->id], $updates->pluck('object_id')->all());
        foreach ($updates as $context) {
            $this->assertSame($user->id, $context['user_id']);
            $this->assertContains('deprecated_at', $context['fields']);
        }
    }

    #[TestDox('resolveByName 沿改用往下找；沒有改用對象時停在棄用的那一條；繞成環也會停')]
    public function test_resolve_by_name_follows_the_replacement_chain()
    {
        [$a] = $this->pair('first');
        [$b] = $this->pair('second');
        [$c] = $this->pair('third');

        $this->assertSame($a->id, Relation::resolveByName('first')->id);
        $this->assertNull(Relation::resolveByName('nope'));

        $a->deprecate($b);
        $b->deprecate($c);
        $this->assertSame($c->id, Relation::resolveByName('first')->id);

        $c->deprecate(null);
        $resolved = Relation::resolveByName('first');
        $this->assertSame($c->id, $resolved->id);
        $this->assertTrue($resolved->isDeprecated());

        // 直接改資料庫做出環（API 擋得住，這裡測的是不會卡死）
        $c->forceFill(['replaced_by_id' => $a->id])->save();
        $this->assertTrue(Relation::resolveByName('first')->isDeprecated());
    }

    #[TestDox('seeder 不會把新建的述詞配給一條已棄用的述詞')]
    public function test_seeder_does_not_pair_a_new_relation_with_a_deprecated_one()
    {
        $this->seed();
        $targets = Relation::where('name', 'targets')->firstOrFail();
        $targetedBy = Relation::where('name', 'targetedBy')->firstOrFail();
        // 後台解除配對、把原本的反向改名（沒有邊，名稱沒鎖），再把 targets 標為棄用
        $targetedBy->update(['reverse_id' => null]);
        $targetedBy->update(['name' => 'renamedTargetedBy']);
        $targets->fresh()->deprecate(null);

        $this->seed(RelationSeeder::class);

        $recreated = Relation::where('name', 'targetedBy')->firstOrFail();
        $this->assertNotSame($targetedBy->id, $recreated->id, '前置條件：seeder 重建了 targetedBy');
        $this->assertNull($recreated->reverse_id);
        $this->assertNull($targets->fresh()->reverse_id);
    }
}
