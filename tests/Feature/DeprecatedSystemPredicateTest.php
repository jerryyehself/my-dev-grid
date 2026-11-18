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

    #[TestDox('棄用前同步建的邊保留原述詞，之後的同步不再更新它的 last_seen_at')]
    public function test_edges_written_before_the_deprecation_keep_their_predicate()
    {
        $usedBy = (int) Relation::where('name', 'usedBy')->value('id');
        $project = $this->sync();
        DB::table('technique_implementation')->update(['last_seen_at' => '2026-11-01 00:00:00']);
        [$employedBy] = $this->deprecateInFavourOfNewPair('usedBy', 'employedBy');

        $this->sync();

        $this->assertEqualsCanonicalizing([$usedBy, $employedBy->id], $this->projectEdgeRelationIds($project));
        $this->assertSame('2026-11-01 00:00:00', DB::table('technique_implementation')->where('relation_id', $usedBy)->value('last_seen_at'));
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
}
