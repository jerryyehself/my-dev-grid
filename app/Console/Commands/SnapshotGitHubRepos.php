<?php

namespace App\Console\Commands;

use App\Service\GitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Fetches the same data as `github:sync-repos` but writes it to a JSON
 * fixture instead of the database. For environments that can't reach
 * GitHub's general API (e.g. a Claude Code Remote sandbox, whose network
 * policy only allows GitHub API calls scoped to repos already attached to
 * that session) but still want to preview the site locally with realistic
 * content. Run this somewhere with real GitHub API access, then commit the
 * resulting JSON — see `GitHubReposSnapshotSeeder` for how it's consumed.
 */
class SnapshotGitHubRepos extends Command
{
    protected $signature = 'github:snapshot-repos {--path= : Override the output file path (defaults to the committed fixture)}';

    protected $description = 'Fetch public GitHub repos and save them as a JSON fixture for offline/preview use';

    public function handle(GitService $service): int
    {
        $repos = $service->get_repos();

        $path = $this->option('path') ?: database_path('seeders/fixtures/github-repos-snapshot.json');

        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'captured_at' => now()->toIso8601String(),
            'repos' => $repos->values()->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $this->info("Snapshot of {$repos->count()} repos saved to {$path}.");

        return self::SUCCESS;
    }
}
