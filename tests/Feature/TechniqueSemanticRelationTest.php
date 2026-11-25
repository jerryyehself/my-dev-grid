<?php

namespace Tests\Feature;

use App\Models\EntityRelation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\SemanticRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\TestCase;

/**
 * `PUT`／`DELETE /api/techniques/{technique}/broader/{broader}` 與 `.../related/{related}`：
 * 後台新增、刪除技術之間的上下位與相關。
 *
 * 夾具是一小份詞表：PaaS、靜態託管都掛在雲端服務底下（兩者是兄弟），token（驗證）掛在資訊安全底下，
 * 另有 token（LLM）、SPA 兩個概念詞，與 Heroku、Cloudflare Pages 兩個技術。
 */
class TechniqueSemanticRelationTest extends TestCase
{
    use CapturesSecurityEvents;
    use RefreshDatabase;

    /** @var array<string, Technique> */
    private array $t = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $concept = Scope::where('name', 'packagetool')->value('id');
        $packagetool = Scope::where('name', 'packagetool')->value('id');
        foreach (['雲端服務', 'PaaS', '靜態託管', '資訊安全', 'token（驗證）', 'token（LLM）', 'SPA'] as $title) {
            $this->t[$title] = Technique::create(['type' => $concept, 'title' => $title]);
        }
        foreach (['Heroku', 'Cloudflare Pages'] as $title) {
            $this->t[$title] = Technique::create(['type' => $packagetool, 'title' => $title]);
        }

        $this->edge('PaaS', '雲端服務', 'broader');
        $this->edge('靜態託管', '雲端服務', 'broader');
        $this->edge('token（驗證）', '資訊安全', 'broader');
    }

    private function relationId(string $name): int
    {
        return (int) Relation::where('name', $name)->value('id');
    }

    private function edge(string $subject, string $object, string $predicate): EntityRelation
    {
        return EntityRelation::create([
            'entity_type' => 'technique',
            'subject_id' => $this->t[$subject]->id,
            'object_id' => $this->t[$object]->id,
            'relation_id' => $this->relationId($predicate),
        ]);
    }

    private function edgeRequest(string $method, string $kind, string $from, string $to): TestResponse
    {
        return $this->json($method, "/api/techniques/{$this->t[$from]->id}/{$kind}/{$this->t[$to]->id}");
    }

    /** @return list<array{int, int, string}> [主詞, 受詞, 述詞] */
    private function semanticRows(): array
    {
        $names = Relation::pluck('name', 'id');

        return DB::table('entity_relations')->where('entity_type', 'technique')->orderBy('id')->get()
            ->map(fn (object $row) => [(int) $row->subject_id, (int) $row->object_id, $names[$row->relation_id]])->all();
    }

    /** @return list<array<string, mixed>> */
    private function auditEvents(string $action): array
    {
        return array_values(array_map(
            fn ($message) => $message->context,
            array_filter($this->securityEvents('authz_admin'), fn ($message) => $message->context['action'] === $action),
        ));
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield '新增上下位' => ['PUT', 'broader'];
        yield '刪除上下位' => ['DELETE', 'broader'];
        yield '新增相關' => ['PUT', 'related'];
        yield '刪除相關' => ['DELETE', 'related'];
    }

    #[DataProvider('routes')]
    #[TestDox('沒登入回 401，邊不變')]
    public function test_guests_get_401_and_nothing_changes(string $method, string $kind): void
    {
        $this->edge('PaaS', '靜態託管', 'related');
        $before = $this->semanticRows();

        $this->edgeRequest($method, $kind, 'PaaS', $kind === 'broader' ? '雲端服務' : '靜態託管')->assertUnauthorized();

        $this->assertSame($before, $this->semanticRows());
    }

    #[TestDox('新增上下位：存一列 broader，反方向的 narrower 一起成立，記稽核')]
    public function test_adding_broader_stores_one_row_and_the_narrower_reverse_follows(): void
    {
        $user = $this->actingAsOwner();
        $this->captureLogs();

        $this->edgeRequest('PUT', 'broader', 'Heroku', 'PaaS')
            ->assertCreated()
            ->assertJsonPath('data.id', $this->t['Heroku']->id)
            ->assertJsonPath('data.broader', [$this->t['PaaS']->id])
            ->assertJsonPath('data.narrower', [])
            ->assertJsonPath('message', 'Technique updated.');

        $row = EntityRelation::where(['subject_id' => $this->t['Heroku']->id, 'object_id' => $this->t['PaaS']->id])->sole();
        $this->assertSame($this->relationId('broader'), (int) $row->relation_id);
        // 只存一個方向，沒有另一列 narrower
        $this->assertSame(0, EntityRelation::where('relation_id', $this->relationId('narrower'))->count());

        // 反方向：PaaS 的下位有 Heroku；從 PaaS 走到 Heroku 的路徑用 narrower 這個名稱
        $this->assertSame([$this->t['Heroku']->id], SemanticRelations::fromDatabase()->around($this->t['PaaS']->id)['narrower']);
        $this->getJson("/api/graph/path?start=technique-{$this->t['PaaS']->id}&end=technique-{$this->t['Heroku']->id}")
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('edges.0.predicate', 'narrower')
            ->assertJsonPath('edges.0.storedDirection', 'reverse');

        $context = $this->assertSecurityEvent('authz_admin', ['user_id' => $user->id, 'action' => 'attach', 'object' => 'technique', 'object_id' => $this->t['Heroku']->id]);
        $this->assertSame('broader', $context['predicate']);
        $this->assertSame($this->t['PaaS']->id, $context['other_id']);
        $this->assertCount(1, $context['entity_relations']);
        $this->assertSame([$row->id, $this->t['Heroku']->id, $this->t['PaaS']->id], [
            (int) $context['entity_relations'][0]['id'], (int) $context['entity_relations'][0]['subject_id'], (int) $context['entity_relations'][0]['object_id'],
        ]);
    }

    #[TestDox('同一條上下位再送一次回 200，不多一列也不記稽核')]
    public function test_adding_the_same_broader_twice_is_a_no_op(): void
    {
        $this->actingAsOwner();
        $before = $this->semanticRows();
        $this->captureLogs();

        $this->edgeRequest('PUT', 'broader', 'PaaS', '雲端服務')->assertOk()->assertJsonPath('data.broader', [$this->t['雲端服務']->id]);

        $this->assertSame($before, $this->semanticRows());
        $this->assertNoSecurityEvent('authz_admin');
    }

    #[TestDox('反著存的 narrower 列也算已經有這條上下位')]
    public function test_a_stored_narrower_row_counts_as_the_same_broader(): void
    {
        $this->edge('PaaS', 'Heroku', 'narrower');
        $this->actingAsOwner();
        $before = $this->semanticRows();

        $this->edgeRequest('PUT', 'broader', 'Heroku', 'PaaS')->assertOk();

        $this->assertSame($before, $this->semanticRows());
    }

    #[TestDox('多重上位：同一個技術可以掛在兩個不相干的上位底下')]
    public function test_a_technique_can_have_several_broader_concepts(): void
    {
        $this->actingAsOwner();

        $this->edgeRequest('PUT', 'broader', 'Cloudflare Pages', '靜態託管')->assertCreated();
        $this->edgeRequest('PUT', 'broader', 'Cloudflare Pages', 'SPA')->assertCreated()
            ->assertJsonPath('data.broader', collect([$this->t['靜態託管']->id, $this->t['SPA']->id])->sort()->values()->all());

        $this->assertSame([], SemanticRelations::fromDatabase()->problems());
    }

    #[TestDox('兄弟之間標相關：同一個上位底下的兩個技術可以標相關')]
    public function test_siblings_can_be_related(): void
    {
        $this->actingAsOwner();
        $this->captureLogs();
        [$low, $high] = $this->t['PaaS']->id < $this->t['靜態託管']->id ? ['PaaS', '靜態託管'] : ['靜態託管', 'PaaS'];

        // 從 id 大的那一端新增，存的時候 id 小的當主詞
        $this->edgeRequest('PUT', 'related', $high, $low)
            ->assertCreated()
            ->assertJsonPath('data.related', [$this->t[$low]->id]);

        $this->assertContains([$this->t[$low]->id, $this->t[$high]->id, 'related'], $this->semanticRows());
        $context = $this->assertSecurityEvent('authz_admin', ['action' => 'attach', 'object_id' => $this->t[$high]->id]);
        $this->assertSame('related', $context['predicate']);
    }

    #[TestDox('相關是對稱的：從另一端再送一次回 200，不多一列')]
    public function test_related_from_the_other_end_is_the_same_pair(): void
    {
        $this->actingAsOwner();
        $this->edgeRequest('PUT', 'related', 'token（LLM）', 'SPA')->assertCreated();
        $before = $this->semanticRows();

        $this->edgeRequest('PUT', 'related', 'SPA', 'token（LLM）')->assertOk()->assertJsonPath('data.related', [$this->t['token（LLM）']->id]);

        $this->assertSame($before, $this->semanticRows());
    }

    /** @return iterable<string, array{string, string, string, string}> [種類, 主詞, 受詞, 訊息要含的字] */
    public static function rejected(): iterable
    {
        yield '上位是自己' => ['broader', 'PaaS', 'PaaS', '不能連到自己'];
        yield '相關是自己' => ['related', 'SPA', 'SPA', '不能連到自己'];
        yield '兩個互為上位（環）' => ['broader', '雲端服務', 'PaaS', '繞成環'];
        yield '三個繞一圈（環）' => ['broader', '雲端服務', 'Heroku', '繞成環'];
        yield '下位跟上位標相關（S27）' => ['related', 'PaaS', '雲端服務', '上下位路徑'];
        yield '隔兩層也算上下位路徑（S27）' => ['related', 'Heroku', '雲端服務', '上下位路徑'];
        yield '標了相關的兩個又掛成上下位（S27）' => ['broader', 'Heroku', 'token（LLM）', '上下位路徑'];
        yield '上位重複：已經能經 PaaS 走到雲端服務' => ['broader', 'Heroku', '雲端服務', '重複的上位'];
        yield '主詞已停用' => ['broader', 'token（LLM）', '資訊安全', '已停用'];
        yield '受詞已停用' => ['broader', 'SPA', 'token（LLM）', '已停用'];
        yield '相關的一端已停用' => ['related', 'SPA', 'token（LLM）', '已停用'];
        yield '分類不是技術' => ['broader', '文章分類的技術', 'PaaS', '分類不符合'];
        yield '述詞已棄用' => ['broader', 'SPA', '資訊安全', '已棄用'];
        yield '述詞已刪除' => ['related', 'SPA', '資訊安全', '不存在'];
        yield '同名的述詞不只一筆' => ['broader', 'SPA', '資訊安全', '不只一筆'];
    }

    #[DataProvider('rejected')]
    #[TestDox('違反詞表規則、或判斷不出時回 422，什麼都不寫')]
    public function test_rejected_edges_get_422_and_nothing_is_written(string $kind, string $subject, string $object, string $message): void
    {
        $this->arrange($this->dataName());
        $before = $this->semanticRows();
        $this->actingAsOwner();
        $this->captureLogs();

        $errors = $this->edgeRequest('PUT', $kind, $subject, $object)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($kind)
            ->json("errors.{$kind}");

        $this->assertStringContainsString($message, implode("\n", $errors));
        $this->assertSame($before, $this->semanticRows());
        $this->assertNoSecurityEvent('authz_admin');
    }

    /** 各個擋下情境需要的額外資料。 */
    private function arrange(string $case): void
    {
        match ($case) {
            '三個繞一圈（環）', '隔兩層也算上下位路徑（S27）', '上位重複：已經能經 PaaS 走到雲端服務' => $this->edge('Heroku', 'PaaS', 'broader'),
            '標了相關的兩個又掛成上下位（S27）' => $this->edge('Heroku', 'token（LLM）', 'related'),
            '主詞已停用', '受詞已停用', '相關的一端已停用' => $this->t['token（LLM）']->deprecate(null),
            '分類不是技術' => $this->t['文章分類的技術'] = Technique::create(['type' => Scope::where('name', 'post')->value('id'), 'title' => '文章分類的技術']),
            '述詞已棄用' => Relation::where('name', 'broader')->sole()->deprecate(null),
            '述詞已刪除' => DB::table('relations')->where('name', 'related')->update(['deleted_at' => now()]),
            '同名的述詞不只一筆' => DB::table('relations')->insert([
                ...(array) DB::table('relations')->where('name', 'broader')->first(['subject_id', 'object_id', 'class_number', 'name']),
                'call_number' => '99', 'created_at' => now(), 'updated_at' => now(),
            ]),
            default => null,
        };
    }

    /**
     * 跟 `rejected()` 一條一條對著寫：每條擋下的規則，各配一個「差一點就違規、但不違規」的新增，必須通過。
     * 這些案例守的是規則不能寫得太寬：把任一條規則改成多擋一點（例如只比對名稱、只看有沒有共同上位），
     * 對應的案例就會變紅。
     *
     * 規則 → 通過的案例：
     * - 連到自己：名稱開頭相同（token）、但是不同的兩個技術。
     * - 繞成環：往上多接一層、往下多接一個，鏈變長但沒有回頭；多條路走到同一個上位（菱形）不是環。
     * - S27（相關不能跟上下位重疊）：兄弟、叔姪沒有上下位路徑，可以標相關；已經標相關的兩個，
     *   各自掛到對方的親戚底下也沒有路徑。
     * - 上位重複：第二個上位是兄弟，走不到、也不會被走到。
     * - 已停用：別的技術停用，不影響這次的兩端；名稱開頭相同的停用技術也不連坐。
     * - 分類：技術的任何子分類都算（不只 packagetool）。
     * - 述詞：另一個述詞棄用、刪除、同名重複都不影響這次用的述詞；棄用後恢復、同名的另一筆已軟刪除，都算只有一筆可用。
     *
     * @return iterable<string, array{string, string, string}> [種類, 主詞, 受詞]
     */
    public static function accepted(): iterable
    {
        yield '名稱開頭相同的兩個技術，上位不是自己' => ['broader', 'token（LLM）', 'token（驗證）'];
        yield '名稱開頭相同的兩個技術，相關不是自己' => ['related', 'token（LLM）', 'token（驗證）'];
        yield '往上多接一層不算環' => ['broader', '雲端服務', '資訊安全'];
        yield '往下多接一個不算環' => ['broader', '資訊安全', 'Heroku'];
        yield '兄弟標相關（沒有上下位路徑）' => ['related', 'PaaS', '靜態託管'];
        yield '叔姪標相關（共用祖先、沒有上下位路徑）' => ['related', 'Heroku', '靜態託管'];
        yield '標了相關的兩個，各自掛到對方的親戚底下' => ['broader', 'Heroku', 'PaaS'];
        yield '第二個上位是兄弟，不算上位重複' => ['broader', 'Heroku', '靜態託管'];
        yield '主詞沒停用，同一個上位底下另一個技術已停用' => ['broader', 'SPA', '資訊安全'];
        yield '受詞沒停用，名稱開頭相同的技術已停用' => ['broader', 'SPA', 'token（驗證）'];
        yield '相關兩端都沒停用，名稱開頭相同的技術已停用' => ['related', 'SPA', 'token（驗證）'];
        yield '分類是技術底下的另一個子分類' => ['broader', 'Vue', 'PaaS'];
        yield '只有 related 棄用，上下位照常' => ['broader', 'SPA', '資訊安全'];
        yield '只有 broader 棄用，相關照常' => ['related', 'SPA', '資訊安全'];
        yield '述詞棄用後恢復' => ['broader', 'SPA', '資訊安全'];
        yield '只有 related 已刪除，上下位照常' => ['broader', 'SPA', '資訊安全'];
        yield '只有 narrower 已刪除，上下位照常' => ['broader', 'SPA', '資訊安全'];
        yield 'broader 同名重複，相關照常' => ['related', 'SPA', '資訊安全'];
        yield 'broader 同名的另一筆已軟刪除，只算一筆' => ['broader', 'SPA', '資訊安全'];
    }

    #[DataProvider('accepted')]
    #[TestDox('差一點就違規但沒有違規時回 201，只多寫那一列並記稽核')]
    public function test_accepted_edges_get_201_and_exactly_one_row_is_written(string $kind, string $subject, string $object): void
    {
        $this->arrangeAccepted($this->dataName());
        $before = $this->semanticRows();
        $this->actingAsOwner();
        $this->captureLogs();

        $response = $this->edgeRequest('PUT', $kind, $subject, $object)->assertCreated();

        $this->assertContains($this->t[$object]->id, $response->json("data.{$kind}"));
        // 相關存的時候 id 小的當主詞
        $expected = $kind === 'related'
            ? [min($this->t[$subject]->id, $this->t[$object]->id), max($this->t[$subject]->id, $this->t[$object]->id), 'related']
            : [$this->t[$subject]->id, $this->t[$object]->id, 'broader'];
        $this->assertSame([...$before, $expected], $this->semanticRows());
        $context = $this->assertSecurityEvent('authz_admin', ['action' => 'attach', 'object_id' => $this->t[$subject]->id]);
        $this->assertSame($kind, $context['predicate']);
        $this->assertCount(1, $this->auditEvents('attach'));
    }

    /** `accepted()` 各個案例需要的額外資料。 */
    private function arrangeAccepted(string $case): void
    {
        match ($case) {
            '叔姪標相關（共用祖先、沒有上下位路徑）', '往下多接一個不算環', '第二個上位是兄弟，不算上位重複' => $this->edge('Heroku', 'PaaS', 'broader'),
            '標了相關的兩個，各自掛到對方的親戚底下' => $this->edge('Heroku', '靜態託管', 'related'),
            '主詞沒停用，同一個上位底下另一個技術已停用', '受詞沒停用，名稱開頭相同的技術已停用', '相關兩端都沒停用，名稱開頭相同的技術已停用' => $this->t['token（LLM）']->deprecate(null),
            '分類是技術底下的另一個子分類' => $this->t['Vue'] = Technique::create(['type' => Scope::where('name', 'framework')->value('id'), 'title' => 'Vue']),
            '只有 related 棄用，上下位照常' => Relation::where('name', 'related')->sole()->deprecate(null),
            '只有 broader 棄用，相關照常' => Relation::where('name', 'broader')->sole()->deprecate(null),
            '述詞棄用後恢復' => $this->deprecateAndRestore('broader'),
            '只有 related 已刪除，上下位照常' => DB::table('relations')->where('name', 'related')->update(['deleted_at' => now()]),
            '只有 narrower 已刪除，上下位照常' => DB::table('relations')->where('name', 'narrower')->update(['deleted_at' => now()]),
            'broader 同名重複，相關照常' => $this->duplicateBroader(null),
            'broader 同名的另一筆已軟刪除，只算一筆' => $this->duplicateBroader(now()),
            default => null,
        };
    }

    private function deprecateAndRestore(string $name): void
    {
        $relation = Relation::where('name', $name)->sole();
        $relation->deprecate(null);
        $relation->refresh()->undeprecate();
        $this->assertNull(Relation::where('name', $name)->sole()->deprecated_at);
    }

    /** 再插一筆名稱是 broader 的述詞（$deletedAt 不是 null 就是已軟刪除的那種）。 */
    private function duplicateBroader(mixed $deletedAt): void
    {
        DB::table('relations')->insert([
            ...(array) DB::table('relations')->where('name', 'broader')->first(['subject_id', 'object_id', 'class_number', 'name']),
            'call_number' => '99', 'deleted_at' => $deletedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[TestDox('原本就有的問題不擋無關的新增，只擋這次多出來的')]
    public function test_existing_problems_only_block_edges_that_add_new_ones(): void
    {
        // 直接寫進資料庫的環（後台擋得住，舊資料或手動改資料庫才會有）
        $this->edge('資訊安全', 'token（驗證）', 'broader');
        $this->actingAsOwner();

        $this->edgeRequest('PUT', 'broader', 'Heroku', 'PaaS')->assertCreated();
        // 掛到環底下不會讓環變大，不算新的問題；另外繞出一個新的環才擋
        $this->edgeRequest('PUT', 'broader', 'SPA', '資訊安全')->assertCreated();
        $this->edgeRequest('PUT', 'broader', '雲端服務', 'PaaS')->assertUnprocessable()->assertJsonValidationErrors(['broader' => '繞成環']);
    }

    #[TestDox('子述詞推出的上下位也算：版本掛到 broader 底下後，反過來掛會擋成環')]
    public function test_sub_predicates_of_broader_count_toward_cycles(): void
    {
        $framework = Scope::where('name', 'framework')->value('id');
        $vue = Technique::create(['type' => $framework, 'title' => 'Vue']);
        $vue3 = Technique::create(['type' => $framework, 'title' => 'Vue', 'version' => '3']);
        EntityRelation::create(['entity_type' => 'technique', 'subject_id' => $vue3->id, 'object_id' => $vue->id, 'relation_id' => $this->relationId('isVersionOf')]);
        // parent_class 是鎖住的欄位，後台改不了；這裡直接改資料庫，模擬之後把 isVersionOf 掛到 broader 底下
        DB::table('relations')->where('name', 'isVersionOf')->update(['parent_class' => $this->relationId('broader')]);
        $this->actingAsOwner();

        $this->json('PUT', "/api/techniques/{$vue->id}/broader/{$vue3->id}")->assertUnprocessable()->assertJsonValidationErrors(['broader' => '繞成環']);
        // 刪除只刪直接標的 broader，版本關係不在這裡刪
        $this->json('DELETE', "/api/techniques/{$vue3->id}/broader/{$vue->id}")->assertNotFound();
        $this->assertSame(1, EntityRelation::where(['subject_id' => $vue3->id, 'object_id' => $vue->id])->count());
    }

    #[TestDox('刪除上下位：那一列刪掉，反方向的 narrower 一起消失，稽核記整列')]
    public function test_removing_broader_deletes_the_row_and_the_narrower_reverse(): void
    {
        $row = DB::table('entity_relations')->where(['subject_id' => $this->t['PaaS']->id, 'object_id' => $this->t['雲端服務']->id])->sole();
        $this->actingAsOwner();
        $this->captureLogs();

        $this->edgeRequest('DELETE', 'broader', 'PaaS', '雲端服務')->assertOk()->assertJsonPath('data.broader', []);

        $this->assertNull(EntityRelation::find($row->id));
        $this->assertSame([$this->t['靜態託管']->id], SemanticRelations::fromDatabase()->around($this->t['雲端服務']->id)['narrower']);
        $this->getJson("/api/graph/path?start=technique-{$this->t['雲端服務']->id}&end=technique-{$this->t['PaaS']->id}")
            ->assertOk()->assertJsonPath('found', false);
        $context = $this->assertSecurityEvent('authz_admin', ['action' => 'detach', 'object_id' => $this->t['PaaS']->id]);
        $this->assertSame('broader', $context['predicate']);
        $this->assertEquals([(array) $row], $context['entity_relations']);
    }

    #[TestDox('刪除上下位時，反著存的 narrower 列一起刪')]
    public function test_removing_broader_also_removes_a_stored_narrower_row(): void
    {
        $this->edge('Heroku', 'PaaS', 'broader');
        $this->edge('PaaS', 'Heroku', 'narrower');
        $this->actingAsOwner();

        $this->edgeRequest('DELETE', 'broader', 'Heroku', 'PaaS')->assertOk();

        $this->assertSame(0, EntityRelation::whereIn('subject_id', [$this->t['Heroku']->id])->orWhereIn('object_id', [$this->t['Heroku']->id])->count());
    }

    #[TestDox('上下位只刪指定的方向：反過來送回 404，邊不動')]
    public function test_removing_broader_in_the_wrong_direction_is_404(): void
    {
        $before = $this->semanticRows();
        $this->actingAsOwner();

        $this->edgeRequest('DELETE', 'broader', '雲端服務', 'PaaS')->assertNotFound();

        $this->assertSame($before, $this->semanticRows());
    }

    #[TestDox('相關從哪一端刪都可以')]
    public function test_related_can_be_removed_from_either_end(): void
    {
        $this->edge('PaaS', '靜態託管', 'related');
        $this->actingAsOwner();

        $this->edgeRequest('DELETE', 'related', '靜態託管', 'PaaS')->assertOk()->assertJsonPath('data.related', []);

        $this->assertSame(0, EntityRelation::where('relation_id', $this->relationId('related'))->count());
    }

    #[TestDox('沒有這條相關時回 404')]
    public function test_removing_a_missing_related_pair_is_404(): void
    {
        $this->actingAsOwner();
        $this->captureLogs();

        $this->edgeRequest('DELETE', 'related', 'PaaS', '靜態託管')->assertNotFound();

        $this->assertNoSecurityEvent('authz_admin');
    }

    #[TestDox('停用的技術仍然可以刪掉舊的邊')]
    public function test_deprecated_techniques_can_still_lose_edges(): void
    {
        $this->t['token（驗證）']->deprecate(null);
        $this->actingAsOwner();

        $this->edgeRequest('DELETE', 'broader', 'token（驗證）', '資訊安全')->assertOk();

        $this->assertSame([], SemanticRelations::fromDatabase()->around($this->t['資訊安全']->id)['narrower']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function missingEnds(): iterable
    {
        foreach (['PUT' => '新增', 'DELETE' => '刪除'] as $method => $verb) {
            foreach (['broader' => '上下位', 'related' => '相關'] as $kind => $noun) {
                yield "{$verb}{$noun}，另一端已軟刪除" => [$method, $kind, 'trashed'];
                yield "{$verb}{$noun}，另一端不存在" => [$method, $kind, 'missing'];
            }
        }
    }

    #[DataProvider('missingEnds')]
    #[TestDox('另一端已軟刪除或不存在時回 404，邊不動')]
    public function test_missing_or_trashed_other_end_is_404(string $method, string $kind, string $case): void
    {
        $this->edge('PaaS', '靜態託管', 'related');
        $otherId = 999999;
        if ($case === 'trashed') {
            $otherId = $this->t['雲端服務']->id;
            $this->t['雲端服務']->delete();
        }
        $before = $this->semanticRows();
        $this->actingAsOwner();

        $this->json($method, "/api/techniques/{$this->t['PaaS']->id}/{$kind}/{$otherId}")->assertNotFound();

        $this->assertSame($before, $this->semanticRows());
    }
}
