<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 存取 git
 */
class GitService
{
    /** 單一 repo 最多讀幾份清單檔，避免巨大的 monorepo 讓一次同步打出幾十個請求 */
    private const MAX_MANIFESTS_PER_REPO = 10;

    /**
     * 清單檔所在的第一層資料夾若是這些名稱就不讀：裡面放的是範例、測試資料或第三方套件，
     * 不是這個 repo 自己用的技術。以 `.` 開頭的資料夾也不讀。
     */
    private const SKIPPED_DIRECTORIES = ['node_modules', 'vendor', 'test', 'tests', 'fixtures', 'example', 'examples'];

    /**
     * monorepo 的子專案習慣放在這些資料夾底下（`apps/web/package.json`、`packages/core/package.json`），
     * 清單檔多往下一層也讀。其他資料夾的第三層不讀：docs 範例、vendored 程式碼也會長那個樣子。
     */
    private const MONOREPO_DIRECTORIES = ['apps', 'packages'];

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
            ...$this->get_packages($repo['owner']['login'] ?? null, $work['name'] ?? null),
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

    /**
     * 讀 repo 的套件清單檔（package.json、composer.json、requirements.txt），回傳兩個欄位：
     *
     * - `packages`：各生態系用到的套件名稱，例如 `['npm' => ['vue', 'eslint'], 'composer' => ['laravel/framework']]`，
     *   沒有清單檔的生態系不出現。這裡不判斷哪些套件算技術，那是 PackageTechniqueMap 的事，
     *   所以快照檔裡存的是完整名單，對照表改了不用重抓。
     * - `package_versions`：只記會帶出版本節點的套件（PackageTechniqueMap::VERSIONED_TECHNIQUES）
     *   在清單檔寫的版本限制原文，例如 `['npm' => ['vue' => '^3.5.0']]`；取哪一段當主版號是 PackageTechniqueMap 的事。
     *   同名套件出現在多份清單檔時，以先讀到的（路徑較淺的）為準。
     *
     * 找清單檔看根目錄、第一層子資料夾（monorepo 常見的 `frontend/`、`backend/`），以及
     * `apps/`、`packages/` 底下的子專案，更深的不找。
     * 任何一步失敗（repo 是空的、被限流、檔案讀不到）都只記 log 並略過那一步，不中斷同步：
     * 同步只新增邊、不刪邊，漏讀一次只是這次沒更新 last_seen_at。
     *
     * @return array{packages: array<string, list<string>>, package_versions: array<string, array<string, string>>}
     */
    private function get_packages(?string $owner, ?string $name): array
    {
        if (! $owner || ! $name) {
            return ['packages' => [], 'package_versions' => []];
        }

        $paths = $this->find_manifest_paths($owner, $name);
        $packages = [];
        $versions = [];

        foreach ($paths as $path) {
            $ecosystem = PackageManifestParser::ecosystemFor($path);
            $content = $this->get_file_content($owner, $name, $path);

            if ($ecosystem === null || $content === null) {
                continue;
            }

            $packages[$ecosystem] = array_values(array_unique(array_merge(
                $packages[$ecosystem] ?? [],
                PackageManifestParser::parse($ecosystem, $content),
            )));

            foreach (PackageManifestParser::parseVersionConstraints($ecosystem, $content) as $package => $constraint) {
                if (PackageTechniqueMap::isVersioned($ecosystem, (string) $package)) {
                    $versions[$ecosystem][$package] ??= $constraint;
                }
            }
        }

        return ['packages' => array_filter($packages), 'package_versions' => $versions];
    }

    /**
     * 用一次 git tree 請求列出整個 repo 的檔案，再挑出要讀的清單檔：根目錄、第一層子資料夾，
     * 以及 MONOREPO_DIRECTORIES 底下的子專案。淺的排在前面，超過 MAX_MANIFESTS_PER_REPO 時先留淺的。
     *
     * @return list<string>
     */
    private function find_manifest_paths(string $owner, string $name): array
    {
        try {
            $response = $this->accessGit
                ->get("https://api.github.com/repos/{$owner}/{$name}/git/trees/HEAD", ['recursive' => 1])
                ->throw();
            $tree = $response->json('tree');
        } catch (RequestException|ConnectionException $e) {
            // 沒有任何 commit 的空 repo 會回 409，所以用 warning 而不是 error
            Log::warning('GitService::find_manifest_paths failed to list repo files from GitHub.', [
                'repo' => "{$owner}/{$name}",
                'exception' => $e,
            ]);

            return [];
        }

        // 檔案太多時 GitHub 只回一部分並標 truncated。能拿到的照用（漏的只是這次沒讀到的清單檔，
        // 同步不刪邊），但記下來，才知道某個 repo 的套件讀得不完整
        if ($response->json('truncated') === true) {
            Log::warning('GitService::find_manifest_paths got a truncated file list from GitHub; some manifests may be missed.', [
                'repo' => "{$owner}/{$name}",
            ]);
        }

        $paths = collect($tree ?? [])
            ->where('type', 'blob')
            ->pluck('path')
            ->filter(fn ($path) => is_string($path) && PackageManifestParser::ecosystemFor($path) !== null)
            ->filter(fn (string $path) => $this->is_readable_manifest_path($path))
            ->sortBy(fn (string $path) => [substr_count($path, '/'), $path])
            ->values();

        return $paths->take(self::MAX_MANIFESTS_PER_REPO)->all();
    }

    /**
     * 清單檔的路徑要不要讀：根目錄的、第一層子資料夾的，以及 `apps/<子專案>/`、`packages/<子專案>/`
     * 這種第三層的；途中經過的資料夾不能是跳過的那幾種（node_modules、tests…或 `.` 開頭）。
     */
    private function is_readable_manifest_path(string $path): bool
    {
        $segments = explode('/', $path);
        $directories = array_slice($segments, 0, -1);

        $withinDepth = match (count($segments)) {
            1, 2 => true,
            3 => in_array(mb_strtolower($segments[0]), self::MONOREPO_DIRECTORIES, true),
            default => false,
        };

        return $withinDepth && ! collect($directories)->contains(fn (string $directory) => $this->is_skipped_directory($directory));
    }

    private function is_skipped_directory(string $directory): bool
    {
        return str_starts_with($directory, '.')
            || in_array(mb_strtolower($directory), self::SKIPPED_DIRECTORIES, true);
    }

    /** 讀 repo 預設分支上某個檔案的原始內容；讀不到回 null */
    private function get_file_content(string $owner, string $name, string $path): ?string
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        // PendingRequest 的設定方法會直接改動自己，所以先複製一份再指定 Accept，
        // 不然之後所有請求都會被改成要原始內容
        $request = clone $this->accessGit;

        try {
            return $request
                ->accept('application/vnd.github.raw+json')
                ->get("https://api.github.com/repos/{$owner}/{$name}/contents/{$encodedPath}")
                ->throw()
                ->body();
        } catch (RequestException|ConnectionException $e) {
            Log::warning('GitService::get_file_content failed to fetch a manifest from GitHub.', [
                'repo' => "{$owner}/{$name}",
                'path' => $path,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
