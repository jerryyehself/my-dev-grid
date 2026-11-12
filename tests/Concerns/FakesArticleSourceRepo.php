<?php

namespace Tests\Concerns;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * 用 `Http::fake()` 模擬文章來源 repo 的 GitHub API：分支最新的 commit、recursive tree、
 * Contents API。每次 `publishSourceFiles()` 產生一個新 commit，舊 commit 的內容也查得到，
 * 跟 GitHub 一樣可以用 `?ref=<舊 commit>` 讀到當時的版本。
 *
 * 回應帶 ETag，請求帶相同的 `If-None-Match` 時回 304，用來測 conditional request。
 */
trait FakesArticleSourceRepo
{
    protected string $sourceRepo = 'example-owner/example-articles';

    protected string $headCommit = '';

    /** @var array<string, array<string, array{content: string, mode: string, size: int|null}>> commit SHA => 路徑 => 檔案 */
    private array $sourceCommits = [];

    /** @var Closure(Request): (PromiseInterface|null)|null 回傳非 null 時取代正常的回應 */
    private ?Closure $githubOverride = null;

    protected function configureArticleSource(?string $token = 'test-token', ?string $repo = null, string $ref = 'main'): void
    {
        config(['services.github_articles' => ['token' => $token, 'repo' => $repo ?? $this->sourceRepo, 'ref' => $ref]]);
    }

    /**
     * 把 repo 換成這些檔案，成為新的 HEAD commit。
     *
     * @param  array<string, string|array{content: string, mode?: string, size?: int}>  $files  路徑 => 內容
     */
    protected function publishSourceFiles(array $files): string
    {
        $normalized = [];
        foreach ($files as $path => $file) {
            $file = is_string($file) ? ['content' => $file] : $file;
            $normalized[$path] = ['content' => $file['content'], 'mode' => $file['mode'] ?? '100644', 'size' => $file['size'] ?? null];
        }

        $this->headCommit = sha1('commit '.count($this->sourceCommits).serialize($normalized));
        $this->sourceCommits[$this->headCommit] = $normalized;

        return $this->headCommit;
    }

    /** @param  Closure(Request): (PromiseInterface|null)  $override */
    protected function overrideGitHub(Closure $override): void
    {
        $this->githubOverride = $override;
    }

    protected static function blobSha(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }

    protected static function treeShaOf(string $commit): string
    {
        return sha1('tree'.$commit);
    }

    /**
     * 組一份有 front matter 的原稿。
     *
     * @param  array<string, mixed>  $extra  其他 front matter 欄位（已經寫好的 YAML 片段）
     */
    protected static function article(?string $slug, string $title, string $body = "內文第一段。\n", array $extra = [], bool $import = true): string
    {
        $lines = ['---'];
        if ($slug !== null) {
            $lines[] = "slug: {$slug}";
        }
        $lines[] = 'import: '.($import ? 'true' : 'false');
        foreach ($extra as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }
        $lines[] = '---';

        return implode("\n", $lines)."\n# {$title}\n\n{$body}";
    }

    protected function fakeGitHub(): void
    {
        Http::fake(function (Request $request) {
            if ($this->githubOverride !== null && ($response = ($this->githubOverride)($request)) !== null) {
                return $response;
            }

            return $this->githubResponse($request);
        });
    }

    /** @return list<Request> 對 GitHub 發出的請求 */
    protected function githubRequests(?string $pathContains = null): array
    {
        return array_values(array_map(
            fn (array $pair) => $pair[0],
            Http::recorded(fn (Request $request) => $pathContains === null || str_contains(urldecode($request->url()), $pathContains))->all(),
        ));
    }

    private function githubResponse(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = rawurldecode($url['path'] ?? '');
        parse_str($url['query'] ?? '', $query);
        $prefix = '/repos/'.$this->sourceRepo;

        if ($request->method() !== 'GET' || ! str_starts_with($path, $prefix.'/')) {
            return Http::response(['message' => 'Not Found'], 404);
        }
        $rest = substr($path, strlen($prefix) + 1);

        if (str_starts_with($rest, 'commits/')) {
            $ref = substr($rest, strlen('commits/'));
            $commit = $ref === 'main' ? $this->headCommit : $ref;
            if (! isset($this->sourceCommits[$commit])) {
                return Http::response(['message' => 'No commit found for SHA: '.$ref], 422);
            }

            return $this->withEtag($request, ['sha' => $commit, 'commit' => ['tree' => ['sha' => self::treeShaOf($commit)]]]);
        }

        if (str_starts_with($rest, 'git/trees/')) {
            $treeSha = substr($rest, strlen('git/trees/'));
            foreach ($this->sourceCommits as $commit => $files) {
                if (self::treeShaOf($commit) === $treeSha) {
                    $tree = [];
                    foreach ($files as $filePath => $file) {
                        $tree[] = [
                            'path' => $filePath,
                            'mode' => $file['mode'],
                            'type' => 'blob',
                            'sha' => self::blobSha($file['content']),
                            'size' => $file['size'] ?? strlen($file['content']),
                        ];
                    }

                    return $this->withEtag($request, ['sha' => $treeSha, 'tree' => $tree, 'truncated' => false]);
                }
            }

            return Http::response(['message' => 'Not Found'], 404);
        }

        if (str_starts_with($rest, 'contents/')) {
            $filePath = substr($rest, strlen('contents/'));
            $file = $this->sourceCommits[$query['ref'] ?? $this->headCommit][$filePath] ?? null;
            if ($file === null) {
                return Http::response(['message' => 'Not Found'], 404);
            }
            $tooLarge = ($file['size'] ?? strlen($file['content'])) > 1024 * 1024;

            return $this->withEtag($request, [
                'type' => 'file',
                'path' => $filePath,
                'sha' => self::blobSha($file['content']),
                'size' => $file['size'] ?? strlen($file['content']),
                'encoding' => $tooLarge ? 'none' : 'base64',
                // GitHub 每 60 個字元換一行
                'content' => $tooLarge ? '' : chunk_split(base64_encode($file['content']), 60, "\n"),
            ]);
        }

        return Http::response(['message' => 'Not Found'], 404);
    }

    /** @param  array<string, mixed>  $json */
    private function withEtag(Request $request, array $json): PromiseInterface
    {
        $etag = 'W/"'.sha1(serialize($json)).'"';
        if ($request->header('If-None-Match') === [$etag]) {
            return Http::response('', 304, ['ETag' => $etag]);
        }

        return Http::response($json, 200, ['ETag' => $etag]);
    }
}
