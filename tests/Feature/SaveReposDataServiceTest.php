<?php

namespace Tests\Feature;

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
                'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                'topics' => ['laravel'],
                'created_at' => '2026-06-15T00:00:00Z',
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

    public function test_save_repos_data_creates_techniques_for_languages_and_topics()
    {
        $this->seed();
        $this->fakeGitHub();

        (new SaveReposDataService)->save_repos_data();

        $languageScopeId = Scope::where('name', 'language')->value('id');
        $packagetoolScopeId = Scope::where('name', 'packagetool')->value('id');

        $this->assertDatabaseHas('techniques', ['title' => 'PHP', 'type' => $languageScopeId]);
        $this->assertDatabaseHas('techniques', ['title' => 'Blade', 'type' => $languageScopeId]);
        $this->assertDatabaseHas('techniques', ['title' => 'laravel', 'type' => $packagetoolScopeId]);
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
}
