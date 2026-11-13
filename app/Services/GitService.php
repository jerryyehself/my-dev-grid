<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 存取 git
 */
class GitService
{
    private $accessGit;

    public function __construct()
    {
        $this->accessGit = Http::withToken(config('services.github.token'));
    }

    /**
     * 抓取設定的 token 可以看見的所有公開 repo，透過分頁讀取 GitHub 的 `/user/repos`，
     * 直到回傳空頁為止。
     */
    public function get_repos()
    {
        $repos = collect();
        $page = 1;
        $batch = null;

        do {
            try {
                $batch = $this->accessGit
                    ->get('https://api.github.com/user/repos', [
                        'sort' => 'updated',
                        'direction' => 'desc',
                        'per_page' => 100,
                        'page' => $page,
                    ])
                    ->throw()
                    ->json();
            } catch (RequestException $e) {
                Log::error('GitService::get_repos failed to fetch repos from GitHub.', ['page' => $page, 'exception' => $e]);

                break;
            }

            $repos = $repos->concat($batch);
            $page++;
        } while (! empty($batch));

        return $repos
            ->where('private', false)
            ->map(fn ($repo) => $this->clean_repo_info($repo))
            ->filter()
            ->values();
    }

    private function clean_repo_info($repo = null)
    {
        if (! $repo) {
            return null;
        }

        $work = Arr::only($repo, ['id', 'html_url', 'name', 'languages_url', 'topics', 'created_at', 'description', 'archived']);
        $languageBytes = $this->get_languages($work['languages_url'] ?? null);

        return array_merge($work, [
            'git_repo_id' => $work['id'],
            'title' => $work['name'],
            'topics' => $work['topics'] ?? [],
            'languages' => array_keys($languageBytes),
            // 各語言的位元組數，只有 nomens 的影子紀錄用（語言的證據門檻）；同步寫邊只看上面的 languages
            'language_bytes' => $languageBytes,
            // /user/repos 預設 affiliation 涵蓋 collaborator、organization_member，
            // 回傳的 repo 不保證都是 token 使用者自己 owner，所以 owner 是有意義的
            // 獨立欄位，不是跟 token 使用者重複的資訊。
            'owner' => $repo['owner']['login'] ?? null,
        ]);
    }

    /**
     * GitHub 的 repo 列表不含各語言的位元組數，只提供一個 `languages_url` 指向另一個端點，
     * 因此要另外呼叫它。
     *
     * @return array<string, int> 語言名稱 => 位元組數；抓不到時是空陣列
     */
    private function get_languages(?string $languagesUrl): array
    {
        if (! $languagesUrl) {
            return [];
        }

        try {
            $languages = $this->accessGit->get($languagesUrl)->throw()->json();
        } catch (RequestException $e) {
            Log::error('GitService::get_languages failed to fetch languages from GitHub.', [
                'url' => $languagesUrl,
                'exception' => $e,
            ]);

            return [];
        }

        return is_array($languages) ? $languages : [];
    }
}
