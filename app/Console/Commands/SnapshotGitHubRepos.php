<?php

namespace App\Console\Commands;

use App\Services\GitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 抓取與 `github:sync-repos` 相同的資料，但改寫成 JSON
 * fixture 檔，而不是寫入資料庫。適用於無法連到 GitHub 一般 API 的環境
 * （例如 Claude Code Remote 的沙箱，其網路政策只允許呼叫已附加到該 session 的
 * repo 的 GitHub API），但仍想在本機預覽內容接近真實的網站。
 * 請在能直接存取 GitHub API 的地方執行，然後
 * commit 產生的 JSON 檔。讀取方式見 `GitHubReposSnapshotSeeder`。
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
