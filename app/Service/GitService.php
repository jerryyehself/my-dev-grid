<?php

namespace App\Service;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * access to git
 */
class GitService
{
    private $accessGit;

    public function __construct()
    {
        $this->accessGit = Http::withToken(config('services.github.token'));
    }

    /**
     * Fetch every public repo visible to the configured token, paginating
     * through GitHub's `/user/repos` until an empty page is returned.
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

        return array_merge($work, [
            'git_repo_id' => $work['id'],
            'title' => $work['name'],
            'topics' => $work['topics'] ?? [],
            'languages' => $this->get_languages($work['languages_url'] ?? null),
        ]);
    }

    /**
     * GitHub's repo listing doesn't include per-language byte counts, only a
     * `languages_url` to a separate endpoint — fetch it and keep just the
     * language names.
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

        return array_keys($languages ?? []);
    }
}
