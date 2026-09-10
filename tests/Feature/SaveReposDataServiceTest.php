<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Service\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaveReposDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGitHub(?array $repos = null): void
    {
        $repos ??= [
            [
                'id' => 111,
                'private' => false,
                'html_url' => 'https://github.com/acme/demo',
                'name' => 'demo',
                'description' => 'A demo repo',
                'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                'topics' => ['laravel'],
                'created_at' => '2026-06-15T00:00:00Z',
                'archived' => false,
            ],
        ];

        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push($repos)
                ->push([]),
            'https://api.github.com/repos/acme/demo/languages' => Http::response([
                'PHP' => 12345,
                'Blade' => 234,
            ]),
        ]);
    }

    public function test_save_repos_data_creates_implementation_as_project()
    {
        $this->seed();
        $this->fakeGitHub();

        (new SaveReposDataService)->save_repos_data();

        $projectScopeId = Scope::where('name', 'project')->value('id');
        $this->assertDatabaseHas('implementations', [
            'git_repo_id' => 111,
            'title' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'type' => $projectScopeId,
        ]);
    }

    public function test_save_repos_data_stores_git_repo_created_at()
    {
        $this->seed();
        $this->fakeGitHub();

        (new SaveReposDataService)->save_repos_data();

        $implementation = Implementation::where('git_repo_id', 111)->firstOrFail();
        $this->assertSame('2026-06-15 00:00:00', $implementation->git_repo_created_at->format('Y-m-d H:i:s'));
    }

    public function test_save_repos_data_stores_description()
    {
        $this->seed();
        $this->fakeGitHub();

        (new SaveReposDataService)->save_repos_data();

        $this->assertDatabaseHas('implementations', ['git_repo_id' => 111, 'description' => 'A demo repo']);
    }

    public function test_save_repos_data_marks_non_archived_repo_as_maintained()
    {
        $this->seed();
        $this->fakeGitHub();

        (new SaveReposDataService)->save_repos_data();

        $implementation = Implementation::where('git_repo_id', 111)->firstOrFail();
        $this->assertTrue($implementation->maintain_status);
    }

    public function test_save_repos_data_marks_archived_repo_as_not_maintained()
    {
        $this->seed();
        $this->fakeGitHub([
            [
                'id' => 111,
                'private' => false,
                'html_url' => 'https://github.com/acme/demo',
                'name' => 'demo',
                'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                'topics' => [],
                'archived' => true,
            ],
        ]);

        (new SaveReposDataService)->save_repos_data();

        $implementation = Implementation::where('git_repo_id', 111)->firstOrFail();
        $this->assertFalse($implementation->maintain_status);
    }

    public function test_save_repos_data_creates_techniques_for_languages_and_topics()
    {
        $this->seed();
        $this->fakeGitHub();

        (new SaveReposDataService)->save_repos_data();

        $languageScopeId = Scope::where('name', 'language')->value('id');
        $frameworkScopeId = Scope::where('name', 'framework')->value('id');

        $this->assertDatabaseHas('techniques', ['title' => 'PHP', 'type' => $languageScopeId]);
        $this->assertDatabaseHas('techniques', ['title' => 'Blade', 'type' => $languageScopeId]);
        // laravel 是 FRAMEWORK_BASE_LANGUAGE 已知的 topic,歸類到 framework
        // scope,不是 packagetool——覆蓋 packagetool 的分支見另一個測試
        // (test_save_repos_data_classifies_unmapped_topic_as_packagetool_scope)。
        $this->assertDatabaseHas('techniques', ['title' => 'laravel', 'type' => $frameworkScopeId]);
    }

    public function test_save_repos_data_links_project_to_techniques_via_uses_relation()
    {
        $this->seed();
        $this->fakeGitHub();

        (new SaveReposDataService)->save_repos_data();

        $project = Implementation::where('git_repo_id', 111)->firstOrFail();
        $php = Technique::where('title', 'PHP')->firstOrFail();
        $usesRelationId = Relation::where('name', 'uses')->value('id');

        $this->assertDatabaseHas('technique_implementation', [
            'implementation_id' => $project->id,
            'technique_id' => $php->id,
            'relation_id' => $usesRelationId,
        ]);
    }

    public function test_save_repos_data_reuses_existing_technique_across_repos()
    {
        $this->seed();

        $repo = [
            'id' => 111,
            'private' => false,
            'html_url' => 'https://github.com/acme/demo',
            'name' => 'demo',
            'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
            'topics' => ['laravel'],
        ];

        // Two full sync runs (e.g. two scheduled fires) seeing the same
        // language/topic must not create duplicate Technique rows.
        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([$repo])->push([])
                ->push([$repo])->push([]),
            'https://api.github.com/repos/acme/demo/languages' => Http::response(['PHP' => 12345]),
        ]);

        (new SaveReposDataService)->save_repos_data();
        $this->assertSame(1, Technique::where('title', 'PHP')->count());

        (new SaveReposDataService)->save_repos_data();
        $this->assertSame(1, Technique::where('title', 'PHP')->count());
    }

    public function test_save_repos_data_updates_existing_implementation_by_git_repo_id()
    {
        $this->seed();
        $this->fakeGitHub([
            [
                'id' => 111,
                'private' => false,
                'html_url' => 'https://github.com/acme/demo',
                'name' => 'demo-renamed',
                'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                'topics' => [],
            ],
        ]);
        Implementation::factory()->create(['git_repo_id' => 111, 'title' => 'demo']);

        (new SaveReposDataService)->save_repos_data();

        $this->assertSame(1, Implementation::where('git_repo_id', 111)->count());
        $this->assertDatabaseHas('implementations', ['git_repo_id' => 111, 'title' => 'demo-renamed']);
    }

    public function test_command_runs_the_sync()
    {
        $this->seed();
        $this->fakeGitHub();

        $this->artisan('github:sync-repos')->assertSuccessful();

        $this->assertDatabaseHas('implementations', ['git_repo_id' => 111]);
    }

    public function test_save_repos_data_links_known_framework_topic_to_its_base_language_via_requires()
    {
        $this->seed();
        $this->fakeGitHub(); // topics: ['laravel'], languages: PHP/Blade

        (new SaveReposDataService)->save_repos_data();

        $laravel = Technique::where('title', 'laravel')->firstOrFail();
        $php = Technique::where('title', 'PHP')->firstOrFail();
        $requiresRelationId = Relation::where('name', 'requires')->value('id');

        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'technique',
            'subject_id' => $laravel->id,
            'object_id' => $php->id,
            'relation_id' => $requiresRelationId,
        ]);
    }

    public function test_save_repos_data_does_not_invent_a_requires_edge_for_an_unmapped_topic()
    {
        $this->seed();
        $this->fakeGitHub([
            [
                'id' => 111,
                'private' => false,
                'html_url' => 'https://github.com/acme/demo',
                'name' => 'demo',
                'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                'topics' => ['testing'],
                'archived' => false,
            ],
        ]);

        (new SaveReposDataService)->save_repos_data();

        $this->assertDatabaseHas('techniques', ['title' => 'testing']);
        $this->assertSame(0, EntityRelation::where('entity_type', 'technique')->count());
    }

    public function test_save_repos_data_creates_sourcesite_documentation_for_known_techniques_via_specs()
    {
        $this->seed();
        $this->fakeGitHub(); // topics: ['laravel'], languages: PHP/Blade

        (new SaveReposDataService)->save_repos_data();

        $laravel = Technique::where('title', 'laravel')->firstOrFail();
        $php = Technique::where('title', 'PHP')->firstOrFail();
        $specsRelationId = Relation::where('name', 'specs')->value('id');
        $sourcesiteScopeId = Scope::where('name', 'sourcesite')->value('id');

        $laravelDocs = Documentation::where('url', 'https://laravel.com/docs')->firstOrFail();
        $this->assertSame($sourcesiteScopeId, $laravelDocs->type);
        $this->assertDatabaseHas('documentation_technique', [
            'documentation_id' => $laravelDocs->id,
            'technique_id' => $laravel->id,
            'relation_id' => $specsRelationId,
        ]);

        $phpDocs = Documentation::where('url', 'https://www.php.net/docs.php')->firstOrFail();
        $this->assertDatabaseHas('documentation_technique', [
            'documentation_id' => $phpDocs->id,
            'technique_id' => $php->id,
            'relation_id' => $specsRelationId,
        ]);
    }

    public function test_save_repos_data_does_not_invent_a_sourcesite_for_an_unmapped_technique()
    {
        $this->seed();
        $this->fakeGitHub(); // languages include 'Blade', which has no OFFICIAL_DOCS entry

        (new SaveReposDataService)->save_repos_data();

        $blade = Technique::where('title', 'Blade')->firstOrFail();
        $this->assertDatabaseMissing('documentation_technique', ['technique_id' => $blade->id]);
    }

    public function test_save_repos_data_classifies_known_framework_topic_as_framework_scope()
    {
        $this->seed();
        $this->fakeGitHub(); // topics: ['laravel'] — a FRAMEWORK_BASE_LANGUAGE key

        (new SaveReposDataService)->save_repos_data();

        $frameworkScopeId = Scope::where('name', 'framework')->value('id');
        $packagetoolScopeId = Scope::where('name', 'packagetool')->value('id');

        $this->assertDatabaseHas('techniques', ['title' => 'laravel', 'type' => $frameworkScopeId]);
        $this->assertDatabaseMissing('techniques', ['title' => 'laravel', 'type' => $packagetoolScopeId]);
    }

    public function test_save_repos_data_classifies_unmapped_topic_as_packagetool_scope()
    {
        $this->seed();
        $this->fakeGitHub([
            [
                'id' => 111,
                'private' => false,
                'html_url' => 'https://github.com/acme/demo',
                'name' => 'demo',
                'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                'topics' => ['testing'],
                'archived' => false,
            ],
        ]);

        (new SaveReposDataService)->save_repos_data();

        $packagetoolScopeId = Scope::where('name', 'packagetool')->value('id');
        $this->assertDatabaseHas('techniques', ['title' => 'testing', 'type' => $packagetoolScopeId]);
    }

    public function test_save_repos_data_still_links_framework_scope_topic_to_base_language_via_requires()
    {
        $this->seed();
        $this->fakeGitHub(); // topics: ['laravel'], languages: PHP/Blade

        (new SaveReposDataService)->save_repos_data();

        $frameworkScopeId = Scope::where('name', 'framework')->value('id');
        $laravel = Technique::where('title', 'laravel')->where('type', $frameworkScopeId)->firstOrFail();
        $php = Technique::where('title', 'PHP')->firstOrFail();
        $requiresRelationId = Relation::where('name', 'requires')->value('id');

        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'technique',
            'subject_id' => $laravel->id,
            'object_id' => $php->id,
            'relation_id' => $requiresRelationId,
        ]);
    }

    public function test_save_repos_data_classifies_vue3_topic_as_framework_scope()
    {
        $this->seed();
        $this->fakeGitHub([
            [
                'id' => 111,
                'private' => false,
                'html_url' => 'https://github.com/acme/demo',
                'name' => 'demo',
                'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                // 這個 repo 真實用的 topic 是 vue3,不是 vue/vuejs——
                // FRAMEWORK_BASE_LANGUAGE 三個 key 都要各自涵蓋到。
                'topics' => ['vue3'],
                'archived' => false,
            ],
        ]);

        (new SaveReposDataService)->save_repos_data();

        $frameworkScopeId = Scope::where('name', 'framework')->value('id');
        $packagetoolScopeId = Scope::where('name', 'packagetool')->value('id');

        $this->assertDatabaseHas('techniques', ['title' => 'vue3', 'type' => $frameworkScopeId]);
        $this->assertDatabaseMissing('techniques', ['title' => 'vue3', 'type' => $packagetoolScopeId]);
    }

    public function test_reclassify_vue3_topic_migration_moves_existing_packagetool_row_in_place()
    {
        $this->seed();

        $packagetoolScopeId = Scope::where('name', 'packagetool')->value('id');
        $frameworkScopeId = Scope::where('name', 'framework')->value('id');

        // 模擬修正前的同步結果:vue3 之前被誤分類進 packagetool。
        $existing = Technique::create(['type' => $packagetoolScopeId, 'title' => 'vue3']);

        $usesRelationId = Relation::where('name', 'uses')->value('id');
        $project = Implementation::factory()->create();
        $project->techniques()->attach($existing->id, ['relation_id' => $usesRelationId]);

        $unrelated = Technique::create(['type' => $packagetoolScopeId, 'title' => 'webpack']);

        $migration = require database_path('migrations/2026_09_09_000000_reclassify_vue3_topic_from_packagetool_to_framework.php');
        $migration->up();

        $existing->refresh();
        $unrelated->refresh();

        $this->assertSame($frameworkScopeId, $existing->type);
        $this->assertSame($packagetoolScopeId, $unrelated->type);

        $this->assertDatabaseHas('technique_implementation', [
            'implementation_id' => $project->id,
            'technique_id' => $existing->id,
            'relation_id' => $usesRelationId,
        ]);
    }

    public function test_reclassify_framework_topics_migration_moves_existing_packagetool_row_in_place()
    {
        $this->seed();

        $packagetoolScopeId = Scope::where('name', 'packagetool')->value('id');
        $frameworkScopeId = Scope::where('name', 'framework')->value('id');

        // 模擬修正前的同步結果:laravel 之前被誤分類進 packagetool。
        $existing = Technique::create(['type' => $packagetoolScopeId, 'title' => 'laravel']);

        // 掛一個既有的 technique_implementation pivot row,用來驗證 migration
        // 是就地改 type、不是刪除重建(不然這筆 pivot 就會斷連)。
        $usesRelationId = Relation::where('name', 'uses')->value('id');
        $project = Implementation::factory()->create();
        $project->techniques()->attach($existing->id, ['relation_id' => $usesRelationId]);

        // 不相關的 packagetool row 不該被動到。
        $unrelated = Technique::create(['type' => $packagetoolScopeId, 'title' => 'webpack']);

        // RefreshDatabase 在測試套件一開始就把所有 migration(含這支)跑過一輪
        // 了(當時 DB 還是空的,up() 是 no-op),所以這裡不能靠再跑一次
        // `migrate` 指令(該 migration 已經記錄在 migrations table,指令會
        // 跳過它)。直接載入 migration 檔案、手動呼叫 up() 來驗證邏輯本身。
        $migration = require database_path('migrations/2026_09_07_000000_reclassify_framework_topics_from_packagetool_to_framework.php');
        $migration->up();

        $existing->refresh();
        $unrelated->refresh();

        $this->assertSame($frameworkScopeId, $existing->type);
        $this->assertSame($packagetoolScopeId, $unrelated->type);

        $this->assertDatabaseHas('technique_implementation', [
            'implementation_id' => $project->id,
            'technique_id' => $existing->id,
            'relation_id' => $usesRelationId,
        ]);
    }

    public function test_save_repos_data_requires_and_specs_edges_are_idempotent_across_syncs()
    {
        $this->seed();

        $repo = [
            'id' => 111,
            'private' => false,
            'html_url' => 'https://github.com/acme/demo',
            'name' => 'demo',
            'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
            'topics' => ['laravel'],
        ];

        // Same two-full-sync-runs shape as test_save_repos_data_reuses_existing_technique_across_repos:
        // one Http::fake() with the /user/repos sequence doubled, not two separate fakeGitHub() calls
        // (a second Http::fake() call doesn't append to the first sequence, it replaces it).
        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([$repo])->push([])
                ->push([$repo])->push([]),
            'https://api.github.com/repos/acme/demo/languages' => Http::response(['PHP' => 12345]),
        ]);

        (new SaveReposDataService)->save_repos_data();
        (new SaveReposDataService)->save_repos_data();

        $this->assertSame(1, EntityRelation::where('entity_type', 'technique')->count());
        $this->assertSame(1, Documentation::where('url', 'https://laravel.com/docs')->count());
        $this->assertSame(1, Documentation::where('url', 'https://www.php.net/docs.php')->count());
    }

    public function test_save_repos_data_accepts_pre_fetched_repos_without_calling_github()
    {
        $this->seed();

        // Deliberately no Http::fake() at all — an injected collection must
        // skip GitService/the network entirely (this is what lets
        // GitHubReposSnapshotSeeder replay a JSON snapshot in environments
        // that can't reach GitHub's API).
        $repos = collect([
            [
                'id' => 111,
                'git_repo_id' => 111,
                'title' => 'demo',
                'html_url' => 'https://github.com/acme/demo',
                'description' => 'A demo repo',
                'topics' => ['laravel'],
                'languages' => ['PHP'],
                'created_at' => '2026-06-15T00:00:00Z',
                'archived' => false,
                'owner' => 'acme',
            ],
        ]);

        (new SaveReposDataService($repos))->save_repos_data();

        $this->assertDatabaseHas('implementations', [
            'git_repo_id' => 111,
            'title' => 'demo',
        ]);
    }
}
