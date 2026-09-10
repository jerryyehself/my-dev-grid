<?php

namespace Database\Seeders;

use App\Service\SaveReposDataService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Seeds Implementation/Technique data from a point-in-time JSON snapshot
 * (see `github:snapshot-repos`) instead of a live GitHub API call. Use this
 * where the real API isn't reachable — e.g. a Claude Code Remote sandbox,
 * whose network policy only allows GitHub API calls scoped to repos already
 * attached to that session — or before GITHUB_TOKEN/GCP is set up, to
 * preview the site with realistic content.
 *
 * Not part of DatabaseSeeder's default chain, since it's a stand-in for a
 * live sync rather than baseline app data; run explicitly with
 * `php artisan db:seed --class=GitHubReposSnapshotSeeder`. The seeded data
 * is only as fresh as the snapshot's capture time — refresh it with
 * `php artisan github:snapshot-repos` somewhere with real API access when
 * it drifts too far from reality.
 */
class GitHubReposSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        $path = $this->snapshotPath();

        if (! file_exists($path)) {
            throw new RuntimeException("GitHub repos snapshot not found at {$path}. Run `php artisan github:snapshot-repos` somewhere with real GitHub API access first, then commit the resulting file.");
        }

        $snapshot = json_decode(file_get_contents($path), true);

        (new SaveReposDataService(Collection::make($snapshot['repos'] ?? [])))->save_repos_data();
    }

    protected function snapshotPath(): string
    {
        return database_path('seeders/fixtures/github-repos-snapshot.json');
    }
}
