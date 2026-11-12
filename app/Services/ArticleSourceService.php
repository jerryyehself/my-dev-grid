<?php

namespace App\Services;

use App\Exceptions\ArticleSourceException;
use App\Logging\AdminAudit;
use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * 從文章來源 repo（`config('services.github_articles')`）列出可匯入的文章、把選定的文章匯入成
 * `documentations`。
 *
 * 讀 GitHub 的方式：
 * - 清單：先取分支最新的 commit，再用 Trees API（recursive）一次拿到所有 `.md` 檔的路徑、
 *   blob SHA 與大小。tree 被截斷（檔案太多）時直接失敗，不拿不完整的清單去判斷「GitHub 上沒了」。
 * - 內容：Contents API，用 `?ref=<commit SHA>` 固定版本，下載後再算一次 git blob SHA，
 *   確認拿到的就是清單上那一版。超過 1 MB 的檔案 Contents API 不給內容，直接列成 invalid。
 * - commit 與 tree 的回應放 Laravel cache：60 秒內不重打；過了 60 秒帶 `If-None-Match`，
 *   GitHub 回 304 不計入請求次數。檔案內容不進快取（快取存在資料庫，沒選擇匯入的筆記也會被下載），
 *   只快取由 blob SHA 決定的解析結果（有沒有選擇匯入、slug、標題、警告），不含內文。
 *
 * 狀態（清單上每一項的 `status`）：
 * - `new`：還沒有對應的文章。`possible_match`：沒有對應，但有一篇還沒連結的文章標題相同。
 * - `unchanged`：blob SHA 跟上次匯入時相同。`changed`：不同，站上的內文沒改過。
 * - `conflict`：不同，而且站上的內文在上次匯入之後改過（`body` 的 SHA-256 對不上 `source_body_hash`）。
 * - `site_deleted`：對應的文章在站上已經軟刪除；不會再匯入，也不會讓它復活。
 * - `removed`：站上有連結，但這個版本的 repo 裡已經沒有選擇匯入、slug 相同的檔案。
 * - `invalid`：選擇了匯入但不能匯入（原因見 `ArticleSourceParser::INVALID_REASONS`）。
 *
 * 匯入一律寫成草稿；更新既有文章時不改它的發布狀態（已發布的文章會直接換成新內容）。
 * 來源欄位（`source_*`）不在 `$fillable` 裡，只有這裡用 `forceFill()` 寫。
 */
final class ArticleSourceService
{
    public const STATUS_NEW = 'new';

    public const STATUS_CHANGED = 'changed';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_REMOVED = 'removed';

    public const STATUS_SITE_DELETED = 'site_deleted';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_POSSIBLE_MATCH = 'possible_match';

    public const STATUS_INVALID = 'invalid';

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_LINK = 'link';

    /** GitHub Contents API 會附上內容的上限（1 MB）。 */
    public const MAX_FILE_BYTES = 1024 * 1024;

    /** 分支最新 commit 的快取在幾秒內直接用，不重打 GitHub。 */
    public const FRESH_SECONDS = 60;

    private const API = 'https://api.github.com';

    private const CACHE_PREFIX = 'article-sources:';

    /** 解析規則改了就加一，舊的解析結果快取自然失效。 */
    private const PARSER_VERSION = 1;

    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    public function __construct(
        private readonly ArticleSourceParser $parser,
        private readonly PublishSafetyScanner $scanner,
    ) {}

    /**
     * 分支最新版本的匯入清單。
     *
     * @return array{repo: string, commit_sha: string, fetched_at: string, items: list<array<string, mixed>>}
     */
    public function listing(): array
    {
        $config = $this->config();
        [$commit, $fetchedAt] = $this->fetchCached($config, '/repos/'.$config['repo'].'/commits/'.$this->encodePath($config['ref']), $this->reduceCommit(...));

        $files = $this->files($config, $commit['sha'], $commit['tree']);
        $rows = $this->sourceRows($config['repo']);

        return [
            'repo' => $config['repo'],
            'commit_sha' => $commit['sha'],
            'fetched_at' => date('Y-m-d H:i:s', $fetchedAt),
            'items' => array_map($this->present(...), $this->classify($files, $rows)),
        ];
    }

    /**
     * 照清單上的選擇匯入。全部檢查過才寫入，任何一項不行就整批不寫。
     *
     * @param  list<array{key: string, expected_blob_sha: string, action: string, documentation_id?: int|string|null, overwrite_site_edits?: bool|null}>  $requested
     * @return list<array<string, mixed>>
     *
     * @throws ArticleSourceException 清單過期、狀態變了（409）或 GitHub 出錯（5xx）
     * @throws ValidationException 要連結的文章不存在或已刪除
     */
    public function import(string $commitSha, array $requested): array
    {
        $config = $this->config();
        $commit = $this->commitForImport($config, $commitSha);
        $files = $this->files($config, $commit['sha'], $commit['tree']);

        $validFiles = [];
        foreach ($files as $file) {
            if ($file['invalid'] === null) {
                $validFiles[$file['key']] = $file;
            }
        }

        $stale = [];
        foreach ($requested as $i => $item) {
            $file = $validFiles[$item['key']] ?? null;
            if ($file === null || $file['sha'] !== $item['expected_blob_sha']) {
                $stale["items.{$i}.expected_blob_sha"] = ['這個版本裡沒有這篇文章，或內容跟清單上的不同。'];
            }
        }
        if ($stale !== []) {
            throw ArticleSourceException::stale($stale);
        }

        // 網路請求都在 transaction 外面做完，不要拿著資料庫的鎖等 GitHub
        $articles = [];
        foreach ($requested as $i => $item) {
            if ($item['action'] === self::ACTION_LINK) {
                continue;
            }

            $file = $validFiles[$item['key']];
            $article = $this->parser->parse($this->fetchFile($config, $commit['sha'], $file['path'], $file['sha']));
            if ($article['invalid'] !== null || $article['key'] !== $item['key']) {
                throw ArticleSourceException::stale(["items.{$i}.expected_blob_sha" => ['檔案內容跟清單上的不同。']]);
            }
            $articles[$i] = $article + ['path' => $file['path'], 'sha' => $file['sha']];
        }

        try {
            $results = DB::transaction(fn () => $this->write($config, $commit['sha'], $files, $validFiles, $requested, $articles));
        } catch (UniqueConstraintViolationException) {
            // 同時有另一個請求匯入了同一個 slug
            throw ArticleSourceException::conflict(['items' => ['同一篇文章剛被匯入過。']]);
        }

        foreach ($results as $result) {
            AdminAudit::articleSourceImported(
                $result['action'] === self::ACTION_LINK ? 'link' : 'import',
                $result['documentation'],
                $result['key'],
                $result['blob_sha'],
                $commit['sha'],
            );
        }

        return array_map(fn (array $result) => [
            'key' => $result['key'],
            'action' => $result['action'],
            'documentation_id' => $result['documentation_id'],
            'title' => $result['title'],
            'published' => $result['published'],
            'warnings' => $result['warnings'],
        ], $results);
    }

    /**
     * @param  array{repo: string}  $config
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, array<string, mixed>>  $validFiles
     * @param  list<array<string, mixed>>  $requested
     * @param  array<int, array<string, mixed>>  $articles
     * @return list<array<string, mixed>>
     */
    private function write(array $config, string $commitSha, array $files, array $validFiles, array $requested, array $articles): array
    {
        // 鎖住這個 repo 的來源列再重新判斷狀態：清單是幾秒、幾分鐘前看的，這段時間站上可能被改過
        $rows = $this->sourceRows($config['repo'], lock: true);
        $statuses = [];
        foreach ($this->classify($files, $rows) as $entry) {
            if ($entry['status'] !== self::STATUS_INVALID && $entry['status'] !== self::STATUS_REMOVED) {
                $statuses[$entry['key']] = $entry;
            }
        }
        $presentKeys = array_keys($validFiles);

        $conflicts = [];
        $targets = [];
        foreach ($requested as $i => $item) {
            $entry = $statuses[$item['key']];
            $status = $entry['status'];
            $field = "items.{$i}.action";

            switch ($item['action']) {
                case self::ACTION_CREATE:
                    if (! in_array($status, [self::STATUS_NEW, self::STATUS_POSSIBLE_MATCH], true)) {
                        $conflicts[$field] = ["目前狀態是 {$status}，不能新增。"];
                    }
                    break;

                case self::ACTION_UPDATE:
                    if ($status === self::STATUS_CONFLICT && ($item['overwrite_site_edits'] ?? false) !== true) {
                        $conflicts["items.{$i}.overwrite_site_edits"] = ['站上的內文在上次匯入之後改過，要覆蓋請明確設定 overwrite_site_edits。'];
                    } elseif (! in_array($status, [self::STATUS_CHANGED, self::STATUS_CONFLICT], true)) {
                        $conflicts[$field] = ["目前狀態是 {$status}，不能更新。"];
                    } else {
                        $targets[$i] = $entry['row'];
                    }
                    break;

                case self::ACTION_LINK:
                    if (! in_array($status, [self::STATUS_NEW, self::STATUS_POSSIBLE_MATCH], true)) {
                        $conflicts[$field] = ["目前狀態是 {$status}，不能連結。"];
                        break;
                    }

                    $target = Documentation::withTrashed()->lockForUpdate()->find((int) $item['documentation_id']);
                    if ($target === null || $target->trashed()) {
                        throw ValidationException::withMessages([
                            "items.{$i}.documentation_id" => ['要連結的文章不存在或已刪除。'],
                        ]);
                    }
                    // 只能連到還沒有來源的文章，或同一個 repo 裡 slug 已經不在的文章（slug 改名後重新連結）
                    $linkable = $target->source_key === null
                        || ($target->source_repo === $config['repo'] && ! in_array($target->source_key, $presentKeys, true));
                    if (! $linkable) {
                        $conflicts["items.{$i}.documentation_id"] = ['這篇文章已經連結到其他來源檔案。'];
                        break;
                    }
                    $targets[$i] = $target;
                    break;
            }
        }
        if ($conflicts !== []) {
            throw ArticleSourceException::conflict($conflicts);
        }

        $post = null;
        $results = [];
        foreach ($requested as $i => $item) {
            $file = $validFiles[$item['key']];
            $source = [
                'source_repo' => $config['repo'],
                'source_key' => $item['key'],
                'source_path' => $file['path'],
                'source_blob_sha' => $file['sha'],
                'source_commit_sha' => $commitSha,
                'source_imported_at' => now()->format('Y-m-d H:i:s'),
            ];

            if ($item['action'] === self::ACTION_LINK) {
                // 連結：只寫來源欄位，內文與標題維持站上的版本；以站上目前的內文當作「匯入時的內文」，
                // 之後站上再改才算有修改
                $documentation = $targets[$i];
                $documentation->forceFill($source + ['source_body_hash' => self::bodyHash($documentation->body)])->save();
                $warnings = [];
            } else {
                $article = $articles[$i];
                $fields = ['title' => $article['title'], 'body' => $article['body']];
                if ($article['creation_date'] !== null) {
                    $fields['creation_date'] = $article['creation_date'];
                }

                if ($item['action'] === self::ACTION_CREATE) {
                    $post ??= Scope::where('name', 'post')->orderBy('id')->first() ?? throw ArticleSourceException::postScopeMissing();
                    $documentation = new Documentation($fields + ['type' => $post->id, 'status' => Documentation::STATUS_DRAFT]);
                } else {
                    // 發布狀態不動：已發布的文章直接換成新內容，草稿維持草稿
                    $documentation = $targets[$i]->fill($fields);
                }

                $documentation->forceFill($source + ['source_body_hash' => self::bodyHash($article['body'])])->save();
                $warnings = $this->warnings($article);
            }

            $results[] = [
                'key' => $item['key'],
                'action' => $item['action'],
                'documentation' => $documentation,
                'documentation_id' => $documentation->id,
                'title' => $documentation->title,
                'published' => $documentation->isPublished(),
                'blob_sha' => $file['sha'],
                'warnings' => $warnings,
            ];
        }

        return $results;
    }

    /**
     * 這個版本的 repo 裡，每個要出現在清單上的 `.md` 檔（選擇匯入的，加上超過大小上限、看不出有沒有選擇匯入的）。
     *
     * @param  array{token: string, repo: string, ref: string}  $config
     * @return list<array{path: string, sha: string, key: string|null, title: string|null, invalid: string|null, warnings: list<array<string, mixed>>}>
     */
    private function files(array $config, string $commitSha, string $treeSha): array
    {
        [$entries] = $this->fetchCached($config, '/repos/'.$config['repo'].'/git/trees/'.$treeSha.'?recursive=1', $this->reduceTree(...));

        // 路徑與 blob 都跟上次匯入時相同的檔案不用下載：內容沒變，slug 就是當時記下的那個
        $known = [];
        foreach ($this->sourceRows($config['repo']) as $row) {
            if ($row->source_key !== null) {
                $known[$row->source_path."\0".$row->source_blob_sha] = $row->source_key;
            }
        }

        $files = [];
        foreach ($entries as $entry) {
            $base = ['path' => $entry['path'], 'sha' => $entry['sha']];
            $key = $known[$entry['path']."\0".$entry['sha']] ?? null;

            if ($key !== null) {
                $files[] = $base + ['key' => $key, 'title' => null, 'invalid' => null, 'warnings' => []];
            } elseif ($entry['size'] > self::MAX_FILE_BYTES) {
                $files[] = $base + ['key' => null, 'title' => null, 'invalid' => 'too_large', 'warnings' => []];
            } else {
                $summary = $this->summarize($config, $commitSha, $entry['path'], $entry['sha']);
                if ($summary['import']) {
                    $files[] = $base + $summary;
                }
            }
        }

        // slug 重複的檔案全部列成 invalid，不挑其中一個匯入
        $counts = array_count_values(array_filter(array_column($files, 'key'), 'is_string'));
        foreach ($files as &$file) {
            if ($file['key'] !== null && $counts[$file['key']] > 1) {
                $file['invalid'] = 'duplicate_slug';
            }
        }
        unset($file);

        return $files;
    }

    /**
     * 一份檔案的解析摘要，依 blob SHA 快取：同一個 blob 的內容永遠相同。只存清單需要的欄位，不存內文。
     *
     * @return array{import: bool, key?: string|null, title?: string|null, invalid?: string|null, warnings?: list<array<string, mixed>>}
     */
    private function summarize(array $config, string $commitSha, string $path, string $sha): array
    {
        return Cache::remember(self::CACHE_PREFIX.'blob:v'.self::PARSER_VERSION.':'.$sha, now()->addDay(), function () use ($config, $commitSha, $path, $sha) {
            $article = $this->parser->parse($this->fetchFile($config, $commitSha, $path, $sha));
            if (! $article['import']) {
                return ['import' => false];
            }

            return [
                'import' => true,
                'key' => $article['key'],
                'title' => $article['title'],
                'invalid' => $article['invalid'],
                'warnings' => $article['invalid'] === null ? $this->warnings($article) : [],
            ];
        });
    }

    /**
     * @param  array{title: string|null, body: string|null, body_line: int}  $article
     * @return list<array{code: string, message: string, line: int|null}>
     */
    private function warnings(array $article): array
    {
        $warnings = [...$this->scanner->scan((string) $article['title'], null), ...$this->scanner->scan((string) $article['body'], $article['body_line'])];

        return array_slice($warnings, 0, PublishSafetyScanner::MAX_WARNINGS);
    }

    /**
     * 把檔案對上資料庫裡的文章，決定每一項的狀態；再加上站上有連結、repo 裡已經沒有的文章。
     *
     * @param  list<array<string, mixed>>  $files
     * @param  Collection<int, Documentation>  $rows  這個 repo 的來源列，含已軟刪除的
     * @return list<array<string, mixed>>
     */
    private function classify(array $files, Collection $rows): array
    {
        $byKey = $rows->keyBy('source_key');
        $presentKeys = array_flip(array_filter(array_column($files, 'key'), 'is_string'));
        $invalidPaths = [];
        foreach ($files as $file) {
            if ($file['invalid'] !== null && $file['key'] === null) {
                $invalidPaths[$file['path']] = true;
            }
        }

        $unlinkedTitles = array_values(array_unique(array_filter(array_map(
            fn (array $file) => $file['invalid'] === null && ! $byKey->has($file['key']) ? $file['title'] : null,
            $files,
        ))));
        $possibleMatches = $unlinkedTitles === [] ? collect() : Documentation::query()
            ->whereNull('source_key')
            ->whereIn('title', $unlinkedTitles)
            ->orderBy('id')
            ->get()
            ->unique('title')
            ->keyBy('title');

        $entries = [];
        foreach ($files as $file) {
            $entry = $file + ['status' => self::STATUS_INVALID, 'row' => null];

            if ($file['invalid'] !== null) {
                $entry['row'] = $file['key'] !== null
                    ? $byKey->get($file['key'])
                    : $rows->first(fn (Documentation $row) => ! $row->trashed() && $row->source_path === $file['path']);
                $entries[] = $entry;

                continue;
            }

            $row = $byKey->get($file['key']);
            if ($row !== null) {
                $entry['row'] = $row;
                $entry['title'] ??= $row->title;
                $entry['status'] = match (true) {
                    $row->trashed() => self::STATUS_SITE_DELETED,
                    $row->source_blob_sha === $file['sha'] => self::STATUS_UNCHANGED,
                    self::siteEdited($row) => self::STATUS_CONFLICT,
                    default => self::STATUS_CHANGED,
                };
            } elseif (($match = $possibleMatches->get($file['title'])) !== null) {
                $entry['row'] = $match;
                $entry['status'] = self::STATUS_POSSIBLE_MATCH;
            } else {
                $entry['status'] = self::STATUS_NEW;
            }
            $entries[] = $entry;
        }

        foreach ($rows as $row) {
            if ($row->trashed() || isset($presentKeys[$row->source_key]) || isset($invalidPaths[$row->source_path])) {
                continue;
            }
            $entries[] = [
                'path' => $row->source_path,
                'sha' => null,
                'key' => $row->source_key,
                'title' => $row->title,
                'invalid' => null,
                'warnings' => [],
                'status' => self::STATUS_REMOVED,
                'row' => $row,
            ];
        }

        usort($entries, fn (array $a, array $b) => strcmp((string) $a['path'], (string) $b['path']));

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function present(array $entry): array
    {
        $row = $entry['row'];

        return [
            'key' => $entry['key'],
            'path' => $entry['path'],
            'blob_sha' => $entry['sha'],
            'title' => $entry['title'],
            'status' => $entry['status'],
            'documentation_id' => $row?->id,
            'published' => $row === null || $row->trashed() ? null : $row->isPublished(),
            'reason' => $entry['invalid'],
            'reason_message' => $entry['invalid'] === null ? null : ArticleSourceParser::INVALID_REASONS[$entry['invalid']],
            'warnings' => $entry['warnings'],
        ];
    }

    /**
     * 這個 repo 的來源列（含已軟刪除的：刪掉的文章也佔著它的 slug，不能被重新匯入）。
     *
     * @return Collection<int, Documentation>
     */
    private function sourceRows(string $repo, bool $lock = false): Collection
    {
        return Documentation::withTrashed()
            ->where('source_repo', $repo)
            ->whereNotNull('source_key')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();
    }

    /** 站上的內文跟上次匯入（或連結）時不同。沒有記錄就當作改過，要求明確覆蓋。 */
    private static function siteEdited(Documentation $row): bool
    {
        return ! is_string($row->source_body_hash)
            || ! hash_equals($row->source_body_hash, self::bodyHash($row->body));
    }

    private static function bodyHash(?string $body): string
    {
        return hash('sha256', $body ?? '');
    }

    /**
     * @return array{token: string, repo: string, ref: string}
     */
    private function config(): array
    {
        $token = config('services.github_articles.token');
        $repo = config('services.github_articles.repo');
        $ref = config('services.github_articles.ref');

        if (! is_string($token) || $token === '' || ! is_string($repo) || $repo === '') {
            throw ArticleSourceException::notConfigured();
        }
        // 這兩個值會組進 API 網址，格式不對就不送出請求
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) !== 1 || str_contains($repo, '..')) {
            throw ArticleSourceException::misconfigured('repo');
        }
        if (! is_string($ref) || preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#', $ref) !== 1 || str_contains($ref, '..')) {
            throw ArticleSourceException::misconfigured('ref');
        }

        return ['token' => $token, 'repo' => $repo, 'ref' => $ref];
    }

    /**
     * 匯入時用清單上的 commit。GitHub 找不到這個 commit（404、422）代表清單已經不能用了，回 409。
     *
     * @param  array{token: string, repo: string, ref: string}  $config
     * @return array{sha: string, tree: string}
     */
    private function commitForImport(array $config, string $commitSha): array
    {
        try {
            [$commit] = $this->fetchCached($config, '/repos/'.$config['repo'].'/commits/'.$commitSha, $this->reduceCommit(...));
        } catch (ArticleSourceException $e) {
            if (in_array($e->upstreamStatus, [404, 422], true)) {
                throw ArticleSourceException::stale(['commit_sha' => ['找不到這個版本，請重新整理清單。']]);
            }
            throw $e;
        }

        if ($commit['sha'] !== $commitSha) {
            throw ArticleSourceException::badResponse();
        }

        return $commit;
    }

    /**
     * GET 一個 GitHub API 網址，把回應縮成需要的部分後放進快取。
     *
     * 快取 FRESH_SECONDS 秒內直接用；之後帶 ETag 發 conditional request，304 就沿用快取並重新計時。
     * 快取本身保留一天，ETag 才有機會用上。
     *
     * @param  array{token: string, repo: string, ref: string}  $config
     * @param  callable(mixed): array<mixed>  $reduce  從 JSON 取出要的部分；格式不對時丟例外，不寫進快取
     * @return array{0: array<mixed>, 1: int} 縮過的資料、從 GitHub 取得（或確認沒變）的時間
     */
    private function fetchCached(array $config, string $path, callable $reduce): array
    {
        $cacheKey = self::CACHE_PREFIX.'http:'.sha1($config['repo']."\0".$path);
        $cached = Cache::get($cacheKey);
        $cached = is_array($cached) && isset($cached['data'], $cached['fetched_at']) ? $cached : null;

        if ($cached !== null && $cached['fetched_at'] > now()->getTimestamp() - self::FRESH_SECONDS) {
            return [$cached['data'], $cached['fetched_at']];
        }

        $request = $this->client($config);
        if ($cached !== null && ($cached['etag'] ?? '') !== '') {
            $request = $request->withHeaders(['If-None-Match' => $cached['etag']]);
        }
        $response = $this->send($request, $path);

        if ($response->status() === 304) {
            if ($cached === null) {
                throw ArticleSourceException::badResponse();
            }
            $data = $cached['data'];
            $etag = $cached['etag'] ?? '';
        } else {
            $data = $reduce($response->json());
            $etag = $response->header('ETag');
        }

        $now = now()->getTimestamp();
        Cache::put($cacheKey, ['data' => $data, 'etag' => $etag, 'fetched_at' => $now], now()->addDay());

        return [$data, $now];
    }

    /**
     * 下載一份檔案（Contents API，固定在 commit），確認內容的 git blob SHA 就是清單上那一個。
     *
     * @param  array{token: string, repo: string, ref: string}  $config
     */
    private function fetchFile(array $config, string $commitSha, string $path, string $sha): string
    {
        $response = $this->send($this->client($config), '/repos/'.$config['repo'].'/contents/'.$this->encodePath($path).'?ref='.$commitSha);
        $json = $response->json();

        if (! is_array($json) || ($json['type'] ?? null) !== 'file' || ($json['sha'] ?? null) !== $sha) {
            throw ArticleSourceException::badResponse();
        }
        // 超過 1 MB 時 encoding 是 none、content 是空的；tree 已經先用 size 擋過，這裡是第二道
        if (($json['encoding'] ?? null) !== 'base64' || ! is_string($json['content'] ?? null)) {
            throw ArticleSourceException::badResponse();
        }

        $raw = base64_decode(preg_replace('/\s+/', '', $json['content']), true);
        if ($raw === false || sha1('blob '.strlen($raw)."\0".$raw) !== $sha) {
            throw ArticleSourceException::badResponse();
        }

        return $raw;
    }

    private function client(array $config): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken($config['token'])
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->connectTimeout(5)
            ->timeout(15);
    }

    /** 送出 GET；連不上或非 2xx（304 除外）一律丟出對應的錯誤，不回空結果。 */
    private function send(PendingRequest $request, string $path): Response
    {
        try {
            $response = $request->get($path);
        } catch (ConnectionException) {
            throw ArticleSourceException::unreachable();
        }

        if ($response->status() !== 304 && ! $response->successful()) {
            throw ArticleSourceException::fromGitHub($response);
        }

        return $response;
    }

    /**
     * @return array{sha: string, tree: string}
     */
    private function reduceCommit(mixed $json): array
    {
        $sha = is_array($json) ? ($json['sha'] ?? null) : null;
        $tree = is_array($json) ? ($json['commit']['tree']['sha'] ?? null) : null;

        if (! is_string($sha) || ! is_string($tree) || preg_match(self::SHA_PATTERN, $sha) !== 1 || preg_match(self::SHA_PATTERN, $tree) !== 1) {
            throw ArticleSourceException::badResponse();
        }

        return ['sha' => $sha, 'tree' => $tree];
    }

    /**
     * 只留一般檔案（mode 100644／100755）的 `.md` blob：路徑、SHA、大小。
     * symlink 在 tree 裡也是 blob，但 Contents API 會回傳它指向的檔案，SHA 對不上，所以不列。
     *
     * @return list<array{path: string, sha: string, size: int}>
     */
    private function reduceTree(mixed $json): array
    {
        if (! is_array($json) || ! is_array($json['tree'] ?? null)) {
            throw ArticleSourceException::badResponse();
        }
        if (($json['truncated'] ?? false) !== false) {
            throw ArticleSourceException::treeTruncated();
        }

        $entries = [];
        foreach ($json['tree'] as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'blob' || ! in_array($entry['mode'] ?? null, ['100644', '100755'], true)
                || ! is_string($entry['path'] ?? null) || preg_match('/\.md$/i', $entry['path']) !== 1) {
                continue;
            }
            if (! is_string($entry['sha'] ?? null) || preg_match(self::SHA_PATTERN, $entry['sha']) !== 1 || ! is_int($entry['size'] ?? null)) {
                throw ArticleSourceException::badResponse();
            }
            $entries[] = ['path' => $entry['path'], 'sha' => $entry['sha'], 'size' => $entry['size']];
        }

        return $entries;
    }

    /** 路徑每一段分別編碼，保留 `/`。 */
    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
