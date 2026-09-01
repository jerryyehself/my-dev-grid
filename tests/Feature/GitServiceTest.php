<?php

namespace Tests\Feature;

use App\Service\GitService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitServiceTest extends TestCase
{
    public function test_get_repos_excludes_private_repos()
    {
        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([
                    ['id' => 1, 'private' => false, 'name' => 'public-repo', 'html_url' => 'x', 'languages_url' => 'https://api.github.com/repos/a/public-repo/languages', 'topics' => []],
                    ['id' => 2, 'private' => true, 'name' => 'secret-repo', 'html_url' => 'x', 'languages_url' => 'https://api.github.com/repos/a/secret-repo/languages', 'topics' => []],
                ])
                ->push([]),
            'https://api.github.com/repos/a/public-repo/languages' => Http::response(['PHP' => 1]),
        ]);

        $repos = (new GitService)->get_repos();

        $this->assertCount(1, $repos);
        $this->assertSame('public-repo', $repos->first()['title']);
    }

    public function test_get_repos_paginates_until_an_empty_page()
    {
        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([['id' => 1, 'private' => false, 'name' => 'repo-1', 'html_url' => 'x', 'languages_url' => 'https://api.github.com/repos/a/repo-1/languages', 'topics' => []]])
                ->push([['id' => 2, 'private' => false, 'name' => 'repo-2', 'html_url' => 'x', 'languages_url' => 'https://api.github.com/repos/a/repo-2/languages', 'topics' => []]])
                ->push([]),
            'https://api.github.com/repos/a/repo-1/languages' => Http::response(['PHP' => 1]),
            'https://api.github.com/repos/a/repo-2/languages' => Http::response(['PHP' => 1]),
        ]);

        $repos = (new GitService)->get_repos();

        $this->assertCount(2, $repos);
    }

    public function test_get_repos_keeps_description_and_archived_fields()
    {
        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([[
                    'id' => 1,
                    'private' => false,
                    'name' => 'repo-1',
                    'html_url' => 'x',
                    'description' => 'a repo',
                    'languages_url' => 'https://api.github.com/repos/a/repo-1/languages',
                    'topics' => [],
                    'archived' => true,
                ]])
                ->push([]),
            'https://api.github.com/repos/a/repo-1/languages' => Http::response(['PHP' => 1]),
        ]);

        $repo = (new GitService)->get_repos()->first();

        $this->assertSame('a repo', $repo['description']);
        $this->assertTrue($repo['archived']);
    }

    public function test_get_repos_keeps_going_when_a_single_repo_languages_fetch_fails()
    {
        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([['id' => 1, 'private' => false, 'name' => 'repo-1', 'html_url' => 'x', 'languages_url' => 'https://api.github.com/repos/a/repo-1/languages', 'topics' => []]])
                ->push([]),
            'https://api.github.com/repos/a/repo-1/languages' => Http::response(['error' => 'boom'], 500),
        ]);

        $repos = (new GitService)->get_repos();

        $this->assertCount(1, $repos);
        $this->assertSame([], $repos->first()['languages']);
    }
}
