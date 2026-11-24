<?php

namespace Tests\Feature;

use App\Enums\ResLabelType;
use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\ResLabel;
use App\Models\Scope;
use App\Models\Technique;
use Database\Seeders\GitHubReposSnapshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\TestCase;

/**
 * `PATCH /api/techniques/{technique}/deprecation`：只停用、停用並合併進另一筆、取消停用。
 *
 * 合併的例子：Linguist 把 Procfile 當成一種語言，在同步加上權威對照（Procfile → Heroku）之前，會建出一筆
 * Procfile 技術，跟 Heroku 一樣連到 Laravel-LearningLibrary（usedBy）。快照同步現在會把 Procfile 換成 Heroku，
 * 所以測試先用 GitHub 快照同步，再補上那筆舊的 Procfile 與它的邊，重現合併前的資料。
 */
class TechniqueDeprecationTest extends TestCase
{
    use CapturesSecurityEvents;
    use RefreshDatabase;

    private Technique $procfile;

    private Technique $heroku;

    private Implementation $learningLibrary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(GitHubReposSnapshotSeeder::class);

        $this->heroku = Technique::where('title', 'Heroku')->sole();
        $this->learningLibrary = Implementation::where('title', 'Laravel-LearningLibrary')->sole();
        $this->procfile = Technique::create(['type' => Scope::where('name', 'language')->value('id'), 'title' => 'Procfile']);
        $this->learningLibrary->techniques()->attach($this->procfile->id, ['relation_id' => $this->relationId('usedBy')]);
    }

    private function relationId(string $name): int
    {
        return (int) Relation::where('name', $name)->value('id');
    }

    /** @param  array<string, mixed>  $body */
    private function deprecation(Technique $technique, array $body): TestResponse
    {
        return $this->patchJson("/api/techniques/{$technique->id}/deprecation", $body);
    }

    /**
     * 剛好一筆 `merge` 的稽核紀錄（停用本身另有一筆 `update`），回傳它的 context。空的欄位（沒有搬、沒有刪）不會寫進日誌。
     *
     * @return array<string, mixed>
     */
    private function mergeEvent(): array
    {
        $merges = array_values(array_filter($this->securityEvents('authz_admin'), fn ($message) => $message->context['action'] === 'merge'));
        $this->assertCount(1, $merges);

        return $merges[0]->context;
    }

    /** @return list<array{int, int}> [實作 id, 述詞 id] */
    private function implementationLinks(Technique $technique): array
    {
        return DB::table('technique_implementation')->where('technique_id', $technique->id)->orderBy('implementation_id')->orderBy('relation_id')
            ->get()->map(fn (object $row) => [(int) $row->implementation_id, (int) $row->relation_id])->all();
    }

    #[TestDox('沒登入不能停用（401），也不改任何東西')]
    public function test_guests_cannot_deprecate(): void
    {
        $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])->assertUnauthorized();

        $this->assertNull($this->procfile->fresh()->deprecated_at);
        $this->assertCount(1, $this->implementationLinks($this->procfile));
    }

    #[TestDox('只停用（Hack）：標為停用，連結都不動，不加別名，回傳的技術帶停用狀態')]
    public function test_deprecate_without_replacement_keeps_links(): void
    {
        $hack = Technique::create(['type' => Scope::where('name', 'language')->value('id'), 'title' => 'Hack']);
        $this->learningLibrary->techniques()->attach($hack->id, ['relation_id' => $this->relationId('usedBy')]);
        $this->actingAsOwner();
        $this->captureLogs();

        $response = $this->deprecation($hack, ['deprecated' => true])->assertOk();

        $response->assertJsonPath('data.deprecated', true)->assertJsonPath('data.replaced_by_id', null)
            ->assertJsonPath('message', 'Technique updated.');
        $this->assertNotNull($response->json('data.deprecated_at'));
        $this->assertSame([[$this->learningLibrary->id, $this->relationId('usedBy')]], $this->implementationLinks($hack));
        $this->assertSame(0, ResLabel::count());
        $context = $this->assertSecurityEvent('authz_admin', ['action' => 'update', 'object' => 'technique', 'object_id' => $hack->id]);
        $this->assertSame(['deprecated_at'], $context['fields']);
    }

    #[TestDox('合併（Procfile → Heroku）：停用並改用 Heroku，舊名成為隱藏別名，跟 Heroku 重複的連結刪掉')]
    public function test_merge_removes_duplicate_links_and_adds_the_old_name_as_alias(): void
    {
        $this->assertSame([[$this->learningLibrary->id, $this->relationId('usedBy')]], $this->implementationLinks($this->procfile));
        $this->assertContains([$this->learningLibrary->id, $this->relationId('usedBy')], $this->implementationLinks($this->heroku), '快照同步已經把 Heroku 連到 Laravel-LearningLibrary');
        $herokuLinks = $this->implementationLinks($this->heroku);
        $this->actingAsOwner();

        $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])
            ->assertOk()
            ->assertJsonPath('data.deprecated', true)
            ->assertJsonPath('data.replaced_by_id', $this->heroku->id);

        $this->assertSame([], $this->implementationLinks($this->procfile));
        $this->assertSame($herokuLinks, $this->implementationLinks($this->heroku));
        $label = ResLabel::sole();
        $this->assertSame(['technique', $this->heroku->id, 'Procfile', ResLabelType::Hidden, 'procfile'], [$label->res_type, $label->res_id, $label->label, $label->label_type, $label->normalized]);
    }

    #[TestDox('合併的稽核紀錄：記下刪掉的重複連結整列的值、改接的連結 id 與新加的別名，還原時查得到')]
    public function test_merge_audit_records_removed_and_moved_links(): void
    {
        $duplicateRow = DB::table('technique_implementation')->where('technique_id', $this->procfile->id)->sole();
        $other = Implementation::where('title', 'shorturl')->sole();
        $other->techniques()->attach($this->procfile->id, ['relation_id' => $this->relationId('usedBy')]);
        $movedId = (int) DB::table('technique_implementation')->where(['technique_id' => $this->procfile->id, 'implementation_id' => $other->id])->value('id');
        $user = $this->actingAsOwner();
        $this->captureLogs();

        $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])->assertOk();

        $context = $this->mergeEvent();
        $this->assertSame([$user->id, 'technique', $this->procfile->id], [$context['user_id'], $context['object'], $context['object_id']]);
        $this->assertSame($this->heroku->id, $context['merged_into']);
        $this->assertSame(['technique_implementation' => [$movedId]], $context['moved_links']);
        $this->assertCount(1, $context['removed_links']['technique_implementation']);
        $removed = $context['removed_links']['technique_implementation'][0];
        $this->assertSame([(int) $duplicateRow->id, $this->procfile->id, $this->learningLibrary->id, $this->relationId('usedBy')],
            [(int) $removed['id'], (int) $removed['technique_id'], (int) $removed['implementation_id'], (int) $removed['relation_id']]);
        $this->assertSame(ResLabel::sole()->id, $context['res_label_id']);
        // 改接的那一列真的接到 Heroku
        $this->assertSame($this->heroku->id, (int) DB::table('technique_implementation')->where('id', $movedId)->value('technique_id'));
    }

    #[TestDox('合併時文件連結與技術之間的邊也搬過去：自己連自己的刪掉，相關改成 id 小的當主詞')]
    public function test_merge_moves_documentation_links_and_entity_relations(): void
    {
        $vue = Technique::where('title', 'Vue')->whereNull('version')->sole();
        $vue3 = Technique::where('title', 'Vue')->where('version', '3')->sole();
        $selfLoop = EntityRelation::where(['subject_id' => $vue3->id, 'object_id' => $vue->id, 'relation_id' => $this->relationId('isVersionOf')])->sole();
        $spa = Technique::create(['type' => Scope::where('name', 'concept')->value('id'), 'title' => 'SPA']);
        $related = EntityRelation::create(['entity_type' => 'technique', 'subject_id' => $vue3->id, 'object_id' => $spa->id, 'relation_id' => $this->relationId('related')]);
        $post = Documentation::factory()->create(['type' => Scope::where('name', 'post')->value('id')]);
        $post->techniques()->attach($vue3->id, ['relation_id' => $this->relationId('about')]);
        $this->actingAsOwner();

        $this->deprecation($vue3, ['deprecated' => true, 'replaced_by_id' => $vue->id])->assertOk();

        $this->assertNull(EntityRelation::find($selfLoop->id), 'Vue 3 isVersionOf Vue 合併後變成 Vue isVersionOf Vue，要刪掉');
        $related->refresh();
        $this->assertSame([min($vue->id, $spa->id), max($vue->id, $spa->id)], [$related->subject_id, $related->object_id]);
        $this->assertSame([$vue->id], $post->techniques()->pluck('techniques.id')->all());
        // 正式名「Vue 3」跟 Vue 不同，寫成隱藏別名
        $this->assertSame(['Vue 3'], ResLabel::where('res_id', $vue->id)->pluck('label')->all());
    }

    #[TestDox('後台指定過的重複連結：刪掉舊的那列時，把指定時間帶到留下來的那列')]
    public function test_merge_keeps_the_manual_flag_of_a_removed_duplicate(): void
    {
        DB::table('technique_implementation')->where('technique_id', $this->procfile->id)->update(['manually_set_at' => '2026-11-20 10:00:00']);
        $herokuRow = DB::table('technique_implementation')->where(['technique_id' => $this->heroku->id, 'implementation_id' => $this->learningLibrary->id])->sole();
        $this->assertNull($herokuRow->manually_set_at);
        $this->actingAsOwner();
        $this->captureLogs();

        $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])->assertOk();

        $this->assertSame('2026-11-20 10:00:00', DB::table('technique_implementation')->where('id', $herokuRow->id)->value('manually_set_at'));
        $context = $this->mergeEvent();
        $this->assertSame(['technique_implementation' => [['id' => (int) $herokuRow->id, 'manually_set_at' => null]]], $context['updated_links']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blockedMerges(): iterable
    {
        yield '同一個實作，兩個技術的述詞不同' => ['different_predicate', '用的述詞不同'];
        yield '改用對象的分類不符合連結的述詞' => ['scope', '的分類不符合述詞「compiledBy」'];
        yield '合併後上下位繞成環' => ['cycle', '上下位會繞成環'];
        yield '合併後相關的兩端有上下位路徑' => ['related_in_hierarchy', '標了相關的兩個技術會有上下位路徑'];
        yield '合併後上位重複' => ['redundant_broader', '會有重複的上位'];
    }

    #[DataProvider('blockedMerges')]
    #[TestDox('合併會弄壞資料時回 422，整個不做：不停用、不搬連結、不加別名、不記 merge')]
    public function test_blocked_merges_change_nothing(string $case, string $message): void
    {
        $concept = Scope::where('name', 'concept')->value('id');
        $broader = $this->relationId('broader');
        $edge = fn (Technique $a, Technique $b, int $relation) => EntityRelation::create(['entity_type' => 'technique', 'subject_id' => $a->id, 'object_id' => $b->id, 'relation_id' => $relation]);
        $from = $this->procfile;
        $into = $this->heroku;
        switch ($case) {
            case 'different_predicate':
                $this->learningLibrary->techniques()->updateExistingPivot($this->heroku->id, ['relation_id' => $this->relationId('subjectOfProject')]);
                break;
            case 'scope':
                // 只收語言當主詞的述詞（後台可以建這種）：Procfile 是語言，Heroku 不是
                $languageOnly = Relation::factory()->create([
                    'name' => 'compiledBy', 'subject_id' => Scope::where('name', 'language')->value('id'),
                    'object_id' => Scope::where('name', 'Implementation')->value('id'), 'reverse_id' => null, 'parent_class' => null,
                ]);
                Implementation::where('title', 'shorturl')->sole()->techniques()->attach($from->id, ['relation_id' => $languageOnly->id]);
                break;
            case 'cycle':
                $hosting = Technique::create(['type' => $concept, 'title' => '靜態託管']);
                $edge($from, $hosting, $broader);
                $edge($hosting, $into, $broader);
                break;
            case 'related_in_hierarchy':
                $paas = Technique::create(['type' => $concept, 'title' => 'PaaS']);
                $edge($from, $paas, $broader);
                $edge($into, $paas, $this->relationId('related'));
                break;
            case 'redundant_broader':
                $cloud = Technique::create(['type' => $concept, 'title' => '雲端服務']);
                $paas = Technique::create(['type' => $concept, 'title' => 'PaaS']);
                $edge($paas, $cloud, $broader);
                $edge($from, $paas, $broader);
                $edge($into, $cloud, $broader);
                break;
        }
        $before = [DB::table('technique_implementation')->orderBy('id')->get(), DB::table('entity_relations')->orderBy('id')->get(), DB::table('documentation_technique')->orderBy('id')->get()];
        $this->actingAsOwner();
        $this->captureLogs();

        $errors = $this->deprecation($from, ['deprecated' => true, 'replaced_by_id' => $into->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('replaced_by_id')
            ->json('errors.replaced_by_id');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString($message, $errors[0]);

        $this->assertNull($from->fresh()->deprecated_at);
        $this->assertEquals($before, [DB::table('technique_implementation')->orderBy('id')->get(), DB::table('entity_relations')->orderBy('id')->get(), DB::table('documentation_technique')->orderBy('id')->get()]);
        $this->assertSame(0, ResLabel::count());
        $this->assertNoSecurityEvent('authz_admin');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReplacements(): iterable
    {
        yield '自己' => ['self'];
        yield '已停用的技術' => ['deprecated'];
        yield '已軟刪除的技術' => ['trashed'];
        yield '不存在的 id' => ['missing'];
        yield '不是整數' => ['string'];
        yield '取消停用時帶了改用對象' => ['undeprecate_with_replacement'];
    }

    #[DataProvider('invalidReplacements')]
    #[TestDox('改用對象不合格時回 422，什麼都不改')]
    public function test_invalid_replacements_are_rejected(string $case): void
    {
        $other = Technique::create(['type' => $this->heroku->type, 'title' => 'Render']);
        $body = ['deprecated' => true, 'replaced_by_id' => match ($case) {
            'self' => $this->procfile->id,
            'deprecated', 'trashed', 'undeprecate_with_replacement' => $other->id,
            'missing' => 999999,
            'string' => 'heroku',
        }];
        if ($case === 'deprecated') {
            DB::table('techniques')->where('id', $other->id)->update(['deprecated_at' => now()]);
        } elseif ($case === 'trashed') {
            $other->delete();
        } elseif ($case === 'undeprecate_with_replacement') {
            $body['deprecated'] = false;
        }
        $this->actingAsOwner();

        $this->deprecation($this->procfile, $body)->assertUnprocessable()->assertJsonValidationErrorFor('replaced_by_id');

        $this->assertNull($this->procfile->fresh()->deprecated_at);
        $this->assertCount(1, $this->implementationLinks($this->procfile));
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
    public function test_deprecated_flag_is_required_and_boolean(mixed $flag): void
    {
        $this->actingAsOwner();

        $this->deprecation($this->procfile, $flag === null ? [] : ['deprecated' => $flag])
            ->assertUnprocessable()->assertJsonValidationErrorFor('deprecated');
    }

    #[TestDox('取消停用：清掉停用時間與改用對象，合併時搬走的連結不搬回來')]
    public function test_undeprecate_clears_the_state_but_does_not_move_links_back(): void
    {
        $this->actingAsOwner();
        $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])->assertOk();

        $this->deprecation($this->procfile, ['deprecated' => false])
            ->assertOk()
            ->assertJsonPath('data.deprecated', false)
            ->assertJsonPath('data.deprecated_at', null)
            ->assertJsonPath('data.replaced_by_id', null);

        $this->assertSame([], $this->implementationLinks($this->procfile));
    }

    #[TestDox('重送同樣的合併回 200：停用時間維持第一次的，不重複加別名，也沒有連結可搬')]
    public function test_resending_the_same_merge_is_idempotent(): void
    {
        $this->actingAsOwner();
        $first = $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])->assertOk()->json('data.deprecated_at');
        $this->travel(5)->minutes();
        $this->captureLogs();

        $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])
            ->assertOk()
            ->assertJsonPath('data.deprecated_at', $first);

        $this->assertSame(1, ResLabel::count());
        // 停用時間與改用對象都沒變，沒有 update；merge 照記，但沒有連結可搬、可刪，也沒加別名
        $context = $this->assertSecurityEvent('authz_admin', ['action' => 'merge']);
        $this->assertSame([], array_intersect_key($context, array_flip(['moved_links', 'removed_links', 'updated_links', 'res_label_id'])));
    }

    #[TestDox('合併之後，名稱比對與文章匯入都經改用對象接到 Heroku')]
    public function test_after_a_merge_the_old_name_resolves_to_the_replacement(): void
    {
        $this->actingAsOwner();
        $this->deprecation($this->procfile, ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])->assertOk();

        $this->assertSame($this->heroku->id, $this->procfile->fresh()->inUse()?->id);
        $this->assertSame([$this->heroku->id], ResLabel::where('normalized', 'procfile')->pluck('res_id')->all());
    }
}
