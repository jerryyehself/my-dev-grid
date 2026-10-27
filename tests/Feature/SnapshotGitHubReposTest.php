<?php

namespace Tests\Feature;

use App\Services\GitService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SnapshotGitHubReposTest extends TestCase
{
    #[TestDox('把取得的 repo 清單寫入指定路徑的快照檔')]
    public function test_it_writes_fetched_repos_to_the_given_path()
    {
        $path = storage_path('framework/testing/github-repos-snapshot-test.json');
        File::delete($path);

        $this->mock(GitService::class, function ($mock) {
            $mock->shouldReceive('get_repos')->once()->andReturn(collect([
                ['id' => 1, 'title' => 'demo', 'topics' => ['laravel']],
            ]));
        });

        $this->artisan('github:snapshot-repos', ['--path' => $path])
            ->assertSuccessful();

        $this->assertFileExists($path);

        $snapshot = json_decode(File::get($path), true);

        $this->assertArrayHasKey('captured_at', $snapshot);
        $this->assertSame([
            ['id' => 1, 'title' => 'demo', 'topics' => ['laravel']],
        ], $snapshot['repos']);

        File::delete($path);
    }
}
