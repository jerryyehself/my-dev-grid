<?php

namespace Tests\Feature;

use Database\Seeders\GitHubReposSnapshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class GitHubReposSnapshotSeederTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return storage_path('framework/testing/github-repos-snapshot-fixture-test.json');
    }

    protected function tearDown(): void
    {
        File::delete($this->fixturePath());

        parent::tearDown();
    }

    public function test_it_seeds_implementations_from_the_snapshot_file()
    {
        $this->seed();

        File::ensureDirectoryExists(dirname($this->fixturePath()));
        File::put($this->fixturePath(), json_encode([
            'captured_at' => '2026-09-10T00:00:00+00:00',
            'repos' => [
                [
                    'id' => 111,
                    'git_repo_id' => 111,
                    'title' => 'demo',
                    'html_url' => 'https://github.com/acme/demo',
                    'topics' => [],
                    'languages' => ['PHP'],
                ],
            ],
        ]));

        $seeder = new class extends GitHubReposSnapshotSeeder
        {
            protected function snapshotPath(): string
            {
                return storage_path('framework/testing/github-repos-snapshot-fixture-test.json');
            }
        };
        $seeder->setContainer($this->app)->run();

        $this->assertDatabaseHas('implementations', [
            'git_repo_id' => 111,
            'title' => 'demo',
        ]);
    }

    public function test_it_throws_a_clear_error_when_the_snapshot_is_missing()
    {
        File::delete($this->fixturePath());

        $seeder = new class extends GitHubReposSnapshotSeeder
        {
            protected function snapshotPath(): string
            {
                return storage_path('framework/testing/github-repos-snapshot-fixture-test.json');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('github:snapshot-repos');

        $seeder->run();
    }
}
