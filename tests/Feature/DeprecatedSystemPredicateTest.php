<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Nomen;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\ArticleAboutLinker;
use App\Services\NomenRecorder;
use App\Services\NomenResolver;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 同步與文章匯入依名稱取用的述詞（`Relation::SYSTEM_PREDICATES`）可以棄用；棄用後程式改用它的
 * 改用對象寫新的邊。用 `$this->seed()` 種出真正的分類與述詞，改用對象照 API 的規則建（主詞受詞
 * 分類相同、成對），棄用也走 API，確認整條路徑接得起來。
 */
class DeprecatedSystemPredicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * 為 $name 建一對主詞受詞分類相同的改用對象，並透過 API 把 $name 標為棄用、改用它。
     *
     * @return array{0: Relation, 1: Relation} [改用對象, 改用對象的反向]
     */
    private function deprecateInFavourOfNewPair(string $name, string $newName): array
    {
        $old = Relation::where('name', $name)->firstOrFail();
        $new = Relation::factory()->create(['name' => $newName, 'subject_id' => $old->subject_id, 'object_id' => $old->object_id, 'class_number' => '88']);
        $newRev = Relation::factory()->create(['name' => $newName.'Rev', 'subject_id' => $old->object_id, 'object_id' => $old->subject_id, 'class_number' => '88', 'reverse_id' => $new->id]);

        $this->actingAsOwner();
        $this->patchJson("/api/relations/{$old->id}/deprecation", ['deprecated' => true, 'replaced_by_id' => $new->id])->assertOk();

        return [$new->fresh(), $newRev->fresh()];
    }

    private function sync(int $id = 111): Implementation
    {
        (new SaveReposDataService(collect([[
            'id' => $id,
            'git_repo_id' => $id,
            'title' => "demo-{$id}",
            'html_url' => "https://github.com/example/demo-{$id}",
            'description' => null,
            'topics' => [],
            'languages' => ['PHP'],
            'language_bytes' => ['PHP' => 1000],
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => 'example',
        ]])))->save_repos_data();

        return Implementation::where('git_repo_id', $id)->firstOrFail();
    }

    /** @return list<int> 這個專案的技術邊用的述詞 id */
    private function projectEdgeRelationIds(Implementation $project): array
    {
        return DB::table('technique_implementation')->where('implementation_id', $project->id)->pluck('relation_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<MessageLogged> */
    private function captureLogs(callable $run): array
    {
        $logs = [];
        Log::listen(function (MessageLogged $message) use (&$logs) {
            $logs[] = $message;
        });
        $run();

        return $logs;
    }

    #[TestDox('usedBy 棄用後，同步改用改用對象寫新的技術邊，影子紀錄也記改用對象')]
    public function test_sync_follows_the_replacement_of_a_deprecated_used_by()
    {
        $usedBy = Relation::where('name', 'usedBy')->value('id');
        [$employedBy] = $this->deprecateInFavourOfNewPair('usedBy', 'employedBy');

        $logs = $this->captureLogs(fn () => $this->sync());
        $project = Implementation::where('git_repo_id', 111)->firstOrFail();

        $this->assertSame([$employedBy->id], $this->projectEdgeRelationIds($project));
        $this->assertNotContains($usedBy, $this->projectEdgeRelationIds($project));
        $this->assertSame([$employedBy->id], Nomen::where('source', Nomen::SOURCE_GITHUB_LANGUAGE)->pluck('predicate_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotEmpty(array_filter($logs, fn (MessageLogged $m) => $m->level === 'info' && str_contains($m->message, '述詞「usedBy」已棄用，同步改用「employedBy」')));
    }

    /** @return list<array{technique_id: int, relation_id: int, last_seen_at: string|null}> */
    private function projectEdges(Implementation $project): array
    {
        return DB::table('technique_implementation')->where('implementation_id', $project->id)->orderBy('technique_id')
            ->get(['technique_id', 'relation_id', 'last_seen_at'])
            ->map(fn (object $row) => ['technique_id' => (int) $row->technique_id, 'relation_id' => (int) $row->relation_id, 'last_seen_at' => $row->last_seen_at])
            ->all();
    }

    #[TestDox('usedBy 棄用後同步兩次：棄用前的邊保留原述詞、照常更新 last_seen_at，同一對只有一列')]
    public function test_edges_written_before_the_deprecation_keep_their_predicate()
    {
        $usedBy = (int) Relation::where('name', 'usedBy')->value('id');
        $project = $this->sync();
        DB::table('technique_implementation')->update(['last_seen_at' => '2026-11-01 00:00:00']);
        $before = $this->projectEdges($project);
        $this->assertNotEmpty($before);
        $this->deprecateInFavourOfNewPair('usedBy', 'employedBy');

        $this->sync();
        $this->sync();

        $after = $this->projectEdges($project);
        $this->assertSame(array_column($before, 'technique_id'), array_column($after, 'technique_id'), '同一對專案與技術只有一列');
        $this->assertSame(array_fill(0, count($after), $usedBy), array_column($after, 'relation_id'), '舊邊不改寫述詞');
        foreach ($after as $edge) {
            $this->assertNotSame('2026-11-01 00:00:00', $edge['last_seen_at'], '舊邊照常更新 last_seen_at');
        }
    }

    #[TestDox('usedBy 棄用後，已經連著的專案再加新技術，新的那一對用改用對象寫；別的專案的新配對也是')]
    public function test_new_project_technique_pairs_after_deprecation_use_the_replacement()
    {
        $usedBy = (int) Relation::where('name', 'usedBy')->value('id');
        $project = $this->sync();
        $php = Technique::where('title', 'PHP')->whereNull('version')->firstOrFail();
        [$employedBy] = $this->deprecateInFavourOfNewPair('usedBy', 'employedBy');

        (new SaveReposDataService(collect([[
            'id' => 111, 'git_repo_id' => 111, 'title' => 'demo-111', 'html_url' => 'https://github.com/example/demo-111',
            'description' => null, 'topics' => [], 'languages' => ['PHP', 'Python'], 'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false, 'owner' => 'example',
        ]])))->save_repos_data();
        $other = $this->sync(222);

        $python = Technique::where('title', 'Python')->whereNull('version')->firstOrFail();
        $edges = collect($this->projectEdges($project))->pluck('relation_id', 'technique_id')->all();
        $this->assertSame([$php->id => $usedBy, $python->id => $employedBy->id], $edges);
        $this->assertSame([$employedBy->id], array_column($this->projectEdges($other), 'relation_id'));
    }

    /** @return iterable<string, array{string}> */
    public static function editedRelations(): iterable
    {
        yield '重送原述詞' => ['old'];
        yield '改送改用對象' => ['new'];
    }

    #[DataProvider('editedRelations')]
    #[TestDox('usedBy 棄用並同步後，後台存檔專案的技術清單、技術的專案清單都回 200')]
    public function test_editor_can_save_after_used_by_is_deprecated(string $send)
    {
        $usedBy = (int) Relation::where('name', 'usedBy')->value('id');
        $project = $this->sync();
        [$employedBy] = $this->deprecateInFavourOfNewPair('usedBy', 'employedBy');
        $this->sync();
        $relationId = $send === 'old' ? $usedBy : $employedBy->id;
        $technique = Technique::findOrFail($this->projectEdges($project)[0]['technique_id']);

        $this->putJson("/api/implementations/{$project->id}", [
            'type' => $project->type,
            'title' => $project->title,
            'techniques' => array_map(fn (array $edge) => ['id' => $edge['technique_id'], 'relation_id' => $edge['relation_id']], $this->projectEdges($project)),
        ])->assertOk();

        $this->putJson("/api/techniques/{$technique->id}", [
            'type' => $technique->type,
            'title' => $technique->title,
            'implementations' => [['id' => $project->id, 'relation_id' => $relationId]],
        ])->assertOk();

        $this->assertSame(1, DB::table('technique_implementation')->where(['implementation_id' => $project->id, 'technique_id' => $technique->id])->count());
        $this->assertSame($relationId, (int) DB::table('technique_implementation')->where(['implementation_id' => $project->id, 'technique_id' => $technique->id])->value('relation_id'));
    }

    #[TestDox('改用對象也棄用時，同步沿著改用一路找到仍在使用的那一條')]
    public function test_sync_follows_a_chain_of_replacements()
    {
        [$first] = $this->deprecateInFavourOfNewPair('usedBy', 'employedBy');
        [$second] = $this->deprecateInFavourOfNewPair('employedBy', 'appliedIn');

        $project = $this->sync();

        $this->assertSame([$second->id], $this->projectEdgeRelationIds($project));
        $this->assertNotSame($first->id, $second->id);
    }

    #[TestDox('沒有改用對象的棄用述詞（直接改資料庫才會發生）：同步照舊用它，不停下來，並記警告')]
    public function test_sync_keeps_going_with_a_deprecated_predicate_that_has_no_replacement()
    {
        $usedBy = Relation::where('name', 'usedBy')->firstOrFail();
        $usedBy->forceFill(['deprecated_at' => now()])->save();

        $logs = $this->captureLogs(fn () => $this->sync());
        $project = Implementation::where('git_repo_id', 111)->firstOrFail();

        $this->assertSame([$usedBy->id], $this->projectEdgeRelationIds($project));
        $this->assertNotEmpty(array_filter($logs, fn (MessageLogged $m) => $m->level === 'warning' && str_contains($m->message, '述詞「usedBy」已棄用但找不到可用的改用對象')));
    }

    #[TestDox('requires、specs、isVersionOf 棄用後，同步一樣改用改用對象')]
    public function test_other_sync_predicates_follow_their_replacements()
    {
        $this->assertNotEmpty(array_diff(Relation::SYSTEM_PREDICATES, ['usedBy', 'about', 'aboutProject']));
        $replacements = [];
        foreach (['requires', 'specs', 'isVersionOf'] as $name) {
            [$replacements[$name]] = $this->deprecateInFavourOfNewPair($name, $name.'Next');
        }

        // vue 會連到 JavaScript（requires）、有版本（isVersionOf），JavaScript 有官方文件（specs）
        (new SaveReposDataService(collect([[
            'id' => 111, 'git_repo_id' => 111, 'title' => 'demo', 'html_url' => 'https://github.com/example/demo',
            'description' => null, 'topics' => ['vue3'], 'languages' => ['JavaScript'], 'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false, 'owner' => 'example',
        ]])))->save_repos_data();

        $entityRelationIds = DB::table('entity_relations')->pluck('relation_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $this->assertContains($replacements['requires']->id, $entityRelationIds);
        $this->assertContains($replacements['isVersionOf']->id, $entityRelationIds);
        foreach (['requires', 'isVersionOf'] as $name) {
            $this->assertNotContains((int) Relation::where('name', $name)->value('id'), $entityRelationIds);
        }
        $this->assertSame([$replacements['specs']->id], DB::table('documentation_technique')->pluck('relation_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    #[TestDox('about／aboutProject 棄用後，文章匯入改用改用對象建立連結')]
    public function test_article_import_follows_the_replacement_of_about()
    {
        [$discusses] = $this->deprecateInFavourOfNewPair('about', 'discusses');
        [$discussesProject] = $this->deprecateInFavourOfNewPair('aboutProject', 'discussesProject');
        $documentation = Documentation::factory()->create(['type' => Scope::where('name', 'post')->value('id')]);
        $technique = Technique::factory()->create(['type' => Scope::where('name', 'framework')->value('id'), 'title' => 'Alpha', 'version' => null]);
        $project = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id'), 'title' => 'Beta']);

        $warnings = (new ArticleAboutLinker(new NomenRecorder(new NomenResolver)))
            ->link($documentation, ['techniques' => ['Alpha'], 'projects' => ['Beta']]);

        $this->assertSame([], $warnings);
        $this->assertSame([$technique->id => $discusses->id], DB::table('documentation_technique')->where('documentation_id', $documentation->id)->pluck('relation_id', 'technique_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$project->id => $discussesProject->id], DB::table('documentation_implementation')->where('documentation_id', $documentation->id)->pluck('relation_id', 'implementation_id')->map(fn ($id) => (int) $id)->all());
    }

    #[TestDox('about 棄用但沒有改用對象（直接改資料庫）時，匯入不建連結並回警告')]
    public function test_article_import_warns_when_about_is_deprecated_without_replacement()
    {
        Relation::where('name', 'about')->firstOrFail()->forceFill(['deprecated_at' => now()])->save();
        $documentation = Documentation::factory()->create(['type' => Scope::where('name', 'post')->value('id')]);
        Technique::factory()->create(['type' => Scope::where('name', 'framework')->value('id'), 'title' => 'Alpha', 'version' => null]);

        $warnings = (new ArticleAboutLinker(new NomenRecorder(new NomenResolver)))
            ->link($documentation, ['techniques' => ['Alpha'], 'projects' => []]);

        $this->assertSame('about_predicate_deprecated', $warnings[0]['code']);
        $this->assertSame(0, DB::table('documentation_technique')->where('documentation_id', $documentation->id)->count());
    }

    /** 對已棄用的述詞重送棄用、把改用對象清成 null，回狀態碼。 */
    private function clearReplacement(Relation $relation): int
    {
        return $this->patchJson("/api/relations/{$relation->id}/deprecation", ['deprecated' => true, 'replaced_by_id' => null])->status();
    }

    #[TestDox('已棄用的系統述詞再送一次，不能把改用對象清掉（清成 null 或不帶都擋），匯入照常用改用對象')]
    public function test_redeprecating_a_system_predicate_cannot_clear_its_replacement()
    {
        $about = Relation::where('name', 'about')->firstOrFail();
        $subjectOf = Relation::where('name', 'subjectOf')->firstOrFail();
        [$discusses] = $this->deprecateInFavourOfNewPair('about', 'discusses');

        $this->patchJson("/api/relations/{$about->id}/deprecation", ['deprecated' => true, 'replaced_by_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('replaced_by_id');
        $this->patchJson("/api/relations/{$about->id}/deprecation", ['deprecated' => true])->assertUnprocessable();
        $this->assertSame(422, $this->clearReplacement($subjectOf), '從反向那條清也一樣擋');

        $this->assertSame($discusses->id, $about->fresh()->replaced_by_id);
        $this->assertSame($discusses->id, Relation::resolveByName('about')->id);

        $documentation = Documentation::factory()->create(['type' => Scope::where('name', 'post')->value('id')]);
        Technique::factory()->create(['type' => Scope::where('name', 'framework')->value('id'), 'title' => 'Alpha', 'version' => null]);
        $warnings = (new ArticleAboutLinker(new NomenRecorder(new NomenResolver)))->link($documentation, ['techniques' => ['Alpha'], 'projects' => []]);
        $this->assertSame([], $warnings);
    }

    #[TestDox('改用鏈中間的述詞（和它的反向）不能把改用對象清掉；換成另一條可用的改用對象、或取消棄用都可以')]
    public function test_middle_of_the_chain_cannot_clear_its_replacement()
    {
        [$employedBy, $employedByRev] = $this->deprecateInFavourOfNewPair('usedBy', 'employedBy');
        [$appliedIn] = $this->deprecateInFavourOfNewPair('employedBy', 'appliedIn');
        $uses = Relation::where('name', 'uses')->firstOrFail();

        $this->assertSame(422, $this->clearReplacement($employedBy->fresh()));
        $this->assertSame(422, $this->clearReplacement($employedByRev->fresh()));
        $this->assertSame(422, $this->clearReplacement($uses), '起點的反向也擋');
        $this->assertSame($appliedIn->id, Relation::resolveByName('usedBy')->id);

        [$other] = $this->deprecateInFavourOfNewPair('appliedIn', 'reliedOnBy');
        $this->patchJson("/api/relations/{$employedBy->id}/deprecation", ['deprecated' => true, 'replaced_by_id' => $other->id])->assertOk();
        $this->assertSame($other->id, Relation::resolveByName('usedBy')->id);

        $this->patchJson("/api/relations/{$employedBy->id}/deprecation", ['deprecated' => false])->assertOk();
        $this->assertSame($employedBy->id, Relation::resolveByName('usedBy')->id, '取消棄用後，路徑停在它身上');
    }

    #[TestDox('第一次棄用系統述詞的反向，也要指定改用對象')]
    public function test_reverse_of_a_system_predicate_requires_a_replacement()
    {
        $uses = Relation::where('name', 'uses')->firstOrFail();
        $this->actingAsOwner();

        $this->patchJson("/api/relations/{$uses->id}/deprecation", ['deprecated' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('replaced_by_id');
    }

    private function syncFramework(string $topic, string $language, int $id = 111): void
    {
        (new SaveReposDataService(collect([[
            'id' => $id, 'git_repo_id' => $id, 'title' => "demo-{$id}", 'html_url' => "https://github.com/example/demo-{$id}",
            'description' => null, 'topics' => [$topic], 'languages' => [$language], 'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false, 'owner' => 'example',
        ]])))->save_repos_data();
    }

    /** @return array<string, list<array<string, int>>> 沒有 last_seen_at 的兩張連結表（文件—技術、技術之間）：每一列的 id 與述詞 */
    private function untrackedEdges(): array
    {
        $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get(['id', 'relation_id', ...$columns])
            ->map(fn (object $row) => array_map('intval', (array) $row))->all();

        return [
            'documentation_technique' => $rows('documentation_technique', ['documentation_id', 'technique_id']),
            'entity_relations' => $rows('entity_relations', ['subject_id', 'object_id']),
        ];
    }

    #[TestDox('specs、requires、isVersionOf 棄用後同步兩次：既有的邊列數與述詞都不變，不改寫、不重複')]
    public function test_existing_untracked_edges_are_left_alone_after_deprecation()
    {
        $this->syncFramework('vue3', 'JavaScript');
        $before = $this->untrackedEdges();
        $old = Relation::whereIn('name', ['specs', 'requires', 'isVersionOf'])->pluck('id', 'name')->map(fn ($id) => (int) $id);
        $this->assertContains($old['specs'], array_column($before['documentation_technique'], 'relation_id'), '前置條件：有 specs 邊');
        $this->assertContains($old['requires'], array_column($before['entity_relations'], 'relation_id'), '前置條件：有 requires 邊');
        $this->assertContains($old['isVersionOf'], array_column($before['entity_relations'], 'relation_id'), '前置條件：有 isVersionOf 邊');

        foreach (['specs', 'requires', 'isVersionOf'] as $name) {
            $this->deprecateInFavourOfNewPair($name, $name.'Next');
        }
        $this->syncFramework('vue3', 'JavaScript');
        $this->syncFramework('vue3', 'JavaScript');

        $this->assertSame($before, $this->untrackedEdges());
    }

    #[TestDox('棄用前沒有邊的新配對，用改用對象寫；再同步一次不會多出來')]
    public function test_new_pairs_after_deprecation_use_the_replacement()
    {
        $this->syncFramework('vue3', 'JavaScript');
        $replacements = [];
        foreach (['specs', 'requires', 'isVersionOf'] as $name) {
            [$replacements[$name]] = $this->deprecateInFavourOfNewPair($name, $name.'Next');
        }
        $before = $this->untrackedEdges();

        // Nuxt 3 是新的技術：Nuxt requires JavaScript、Nuxt 3 isVersionOf Nuxt；PHP 有官方文件（specs）
        $this->syncFramework('nuxt3', 'PHP', id: 222);
        $this->syncFramework('nuxt3', 'PHP', id: 222);

        $nuxt = Technique::where('title', 'Nuxt')->whereNull('version')->firstOrFail();
        $nuxt3 = Technique::where('title', 'Nuxt')->where('version', '3')->firstOrFail();
        $javascript = Technique::where('title', 'JavaScript')->firstOrFail();
        $php = Technique::where('title', 'PHP')->whereNull('version')->firstOrFail();

        $after = $this->untrackedEdges();
        $added = fn (string $table) => array_values(array_udiff($after[$table], $before[$table], fn ($a, $b) => $a['id'] <=> $b['id']));
        $this->assertEqualsCanonicalizing([
            ['subject_id' => $nuxt->id, 'object_id' => $javascript->id, 'relation_id' => $replacements['requires']->id],
            ['subject_id' => $nuxt3->id, 'object_id' => $nuxt->id, 'relation_id' => $replacements['isVersionOf']->id],
        ], array_map(fn ($row) => ['subject_id' => $row['subject_id'], 'object_id' => $row['object_id'], 'relation_id' => $row['relation_id']], $added('entity_relations')));
        $this->assertSame(
            [['technique_id' => $php->id, 'relation_id' => $replacements['specs']->id]],
            array_map(fn ($row) => ['technique_id' => $row['technique_id'], 'relation_id' => $row['relation_id']], $added('documentation_technique')),
        );
    }

    #[TestDox('沒棄用時，specs 邊再同步也不會被改動（不重寫 relation_id、不多出列）')]
    public function test_specs_edges_are_stable_without_deprecation()
    {
        $this->syncFramework('vue3', 'JavaScript');
        $before = $this->untrackedEdges();

        $this->syncFramework('vue3', 'JavaScript');

        $this->assertSame($before, $this->untrackedEdges());
    }
}
