<?php

namespace Database\Seeders;

use App\Services\SaveReposDataService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * 從時間點的 JSON 快照（見 `github:snapshot-repos`）填入 Implementation／Technique 資料，
 * 而不是即時呼叫 GitHub API。用在真正的 API 無法連到的地方，例如 Claude Code Remote 的沙箱
 * （其網路政策只允許呼叫已附加到該 session 的 repo 的 GitHub API），
 * 或尚未設定 GITHUB_TOKEN／GCP 的時候，用來預覽接近真實內容的網站。
 *
 * 這不屬於 DatabaseSeeder 的預設流程，因為它是即時同步的替代品，而不是基礎的應用程式資料；
 * 要明確執行：`php artisan db:seed --class=GitHubReposSnapshotSeeder`。填入的資料只會和快照的擷取時間一樣新，
 * 當它與現實偏離太遠時，請在能存取真實 API 的地方執行 `php artisan github:snapshot-repos` 重新產生快照。
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
