<?php

namespace App\Services;

use App\Exceptions\ArticleSourceException;
use App\Logging\AdminAudit;
use App\Models\Documentation;
use App\Models\Scope;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 * - `unchanged`：blob SHA 跟上次匯入時相同。`changed`：不同，站上的標題、日期與內文都沒改過。
 * - `conflict`：不同，而且站上的標題、日期或內文在上次匯入之後改過（見 `importedContentHash()`）。
 *   這一項另外附上 `site_version`（站上目前這三個欄位的雜湊）；要覆蓋時請求要原樣帶回，
 *   列出清單之後站上又改過就對不上，不會蓋掉看清單時還沒看到的修改。
 * - `site_deleted`：對應的文章在站上已經軟刪除；不會再匯入，也不會讓它復活。
 * - `removed`：站上有連結，但這個版本的 repo 裡已經沒有選擇匯入、slug 相同的檔案。
 * - `invalid`：選擇了匯入但不能匯入（原因見 `ArticleSourceParser::INVALID_REASONS`）。
 *
 * 匯入一律寫成草稿；更新既有文章時不改它的發布狀態（已發布的文章會直接換成新內容）。
 * 更新時，這次的 commit 不能比站上上次匯入的 commit 舊（見 `importedCommitRelations()`）；
 * 判斷不出先後（歷史改寫過、GitHub 查不到站上記的 commit）時，要帶上站上記的那個 commit 確認過才更新
 * （見 `unverifiedBase()`）。
 * 新增與更新時，另外依 front matter 的 `about_techniques`、`about_projects` 補上文章到技術與專案的連結
 * （規則見 `ArticleAboutLinker`），並把內文切成段落單位存進 `article_units`（`ArticleUnitSync`）；
 * 連結（`link`）只寫來源欄位，不讀內容，也就不建這些連結、不存段落單位。
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
    private const PARSER_VERSION = 3;

    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    public function __construct(
        private readonly ArticleSourceParser $parser,
        private readonly PublishSafetyScanner $scanner,
        private readonly ArticleAboutLinker $aboutLinker,
        private readonly ArticleUnitSync $unitSync,
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
     * @param  list<array{key: string, expected_blob_sha: string, action: string, documentation_id?: int|string|null, overwrite_site_edits?: bool|null, expected_site_version?: string|null, confirm_unverified_base?: string|null}>  $requested
     * @return list<array<string, mixed>>
     *
     * @throws ArticleSourceException 清單過期、狀態變了、站上已經匯入較新的版本、判斷不出先後而沒有確認（409）或 GitHub 出錯（5xx）
     * @throws ValidationException 要連結的文章不存在或已刪除
     */
    public function import(string $commitSha, array $requested): array
    {
        $config = $this->config();
        $commit = $this->commitForImport($config, $commitSha);
        $files = $this->files($config, $commit['sha'], $commit['tree']);

        $validFiles = [];
        $invalidFiles = [];
        foreach ($files as $file) {
            if ($file['invalid'] === null) {
                $validFiles[$file['key']] = $file;
            } elseif ($file['key'] !== null) {
                $invalidFiles[$file['key']] = $file;
            }
        }

        $stale = [];
        foreach ($requested as $i => $item) {
            $file = $validFiles[$item['key']] ?? null;
            $invalid = $invalidFiles[$item['key']] ?? null;
            if ($file === null && $invalid !== null && $invalid['sha'] === $item['expected_blob_sha']) {
                // 清單上已經列成 invalid 的同一版檔案：直接說不能匯入的原因，不要讓人以為重新整理就好
                $stale["items.{$i}.expected_blob_sha"] = ['這篇文章不能匯入：'.self::reasonMessage($invalid)];
            } elseif ($file === null || $file['sha'] !== $item['expected_blob_sha']) {
                $stale["items.{$i}.expected_blob_sha"] = ['這個版本裡沒有這篇文章，或內容跟清單上的不同。'];
            }
        }
        if ($stale !== []) {
            throw ArticleSourceException::stale($stale);
        }

        // 網路請求都在 transaction 外面做完，不要拿著資料庫的鎖等 GitHub
        $importedCommits = $this->importedCommitRelations($config, $commit['sha'], $requested);
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
            $results = DB::transaction(fn () => $this->write($config, $commit['sha'], $files, $validFiles, $requested, $articles, $importedCommits));
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
     * @param  array<string, string>  $importedCommits  `importedCommitRelations()` 的結果
     * @return list<array<string, mixed>>
     */
    private function write(array $config, string $commitSha, array $files, array $validFiles, array $requested, array $articles, array $importedCommits): array
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
        $unverified = [];
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
                    if (! in_array($status, [self::STATUS_CHANGED, self::STATUS_CONFLICT], true)) {
                        $conflicts[$field] = ["目前狀態是 {$status}，不能更新。"];
                    } elseif (($older = self::olderThanImported($entry['row'], $commitSha, $importedCommits)) !== null) {
                        // 放在 overwrite_site_edits 之前：覆蓋站上的修改也不能順便把文章倒回舊版
                        $conflicts[$field] = [$older];
                    } elseif (($base = self::unverifiedBase($entry['row'], $commitSha, $importedCommits)) !== null
                        && ! self::confirmsBase($base, $item['confirm_unverified_base'] ?? null)) {
                        // 同樣放在 overwrite_site_edits 之前：先後判斷不出來，覆蓋站上的修改也不代表確認了先後
                        $unverified[$i] = ['row' => $entry['row'], 'relation' => $importedCommits[$base]];
                        if (preg_match(self::SHA_PATTERN, $base) !== 1) {
                            $conflicts[$field] = ['站上記的來源 commit 格式不正確，無法比對先後，也不能確認後更新。'];
                        } else {
                            $conflicts["items.{$i}.confirm_unverified_base"] = [($item['confirm_unverified_base'] ?? null) === null
                                ? '無法確認這個版本是否比站上上次匯入的新（來源 repo 的歷史可能被改寫過）。確定要用這個版本更新，請帶上 confirm_unverified_base。'
                                : '站上這篇記的來源 commit 已經不是確認時的那一個，請重新確認。'];
                        }
                    } elseif ($status === self::STATUS_CONFLICT && ($item['overwrite_site_edits'] ?? false) !== true) {
                        $conflicts["items.{$i}.overwrite_site_edits"] = ['站上的標題、日期或內文在上次匯入之後改過，要覆蓋請明確設定 overwrite_site_edits。'];
                    } elseif ($status === self::STATUS_CONFLICT && ! self::sameSiteVersion($entry['row'], $item['expected_site_version'] ?? null)) {
                        // 同意覆蓋的是看清單時的站上內容；之後站上又改過，就不能連那次修改一起蓋掉
                        $conflicts["items.{$i}.expected_site_version"] = ['站上的標題、日期或內文在列出清單之後又改過，請重新整理清單，確認後再決定要不要覆蓋。'];
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
        if ($unverified !== []) {
            // 不寫內文與標題，只記文章 id 與比對結果：要數得出這種情況多常發生
            Log::warning('文章匯入：判斷不出來源 commit 的先後，沒有確認，整批不寫入。', [
                'items' => array_values(array_map(fn (array $u) => ['documentation_id' => $u['row']->id, 'relation' => $u['relation']], $unverified)),
            ]);
        }
        if ($conflicts !== []) {
            throw ArticleSourceException::conflict($conflicts, self::unverifiedCommits($unverified));
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
                // 連結：只寫來源欄位，內文與標題維持站上的版本；以站上目前的標題、日期與內文當作「匯入時的內容」，
                // 之後站上再改才算有修改
                $documentation = $targets[$i];
                $documentation->forceFill($source + ['source_body_hash' => self::importedContentHash($documentation)])->save();
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

                // 來源沒有 creation_date 時日期維持站上的值，所以雜湊要用寫入後的這一列算，不能只用來源的內容
                $documentation->forceFill($source + ['source_body_hash' => self::importedContentHash($documentation)])->save();
                // 連結只新增、不刪除；警告跟內容掃描的警告放在一起回報
                $warnings = [...$this->warnings($article), ...$this->aboutLinker->link($documentation, $article['about'])];
                $this->unitSync->sync($documentation);
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
                $files[] = $base + ['key' => $key, 'title' => null, 'invalid' => null, 'invalid_detail' => null, 'warnings' => []];
            } elseif ($entry['size'] > self::MAX_FILE_BYTES) {
                $files[] = $base + ['key' => null, 'title' => null, 'invalid' => 'too_large', 'invalid_detail' => null, 'warnings' => []];
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
                $file['invalid_detail'] = null;
            }
        }
        unset($file);

        return $files;
    }

    /**
     * 一份檔案的解析摘要，依 blob SHA 快取：同一個 blob 的內容永遠相同。只存清單需要的欄位，不存內文。
     *
     * @return array{import: bool, key?: string|null, title?: string|null, invalid?: string|null, invalid_detail?: string|null, warnings?: list<array<string, mixed>>}
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
                'invalid_detail' => $article['invalid_detail'],
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
            'site_version' => $entry['status'] === self::STATUS_CONFLICT ? self::importedContentHash($row) : null,
            'documentation_id' => $row?->id,
            'published' => $row === null || $row->trashed() ? null : $row->isPublished(),
            'reason' => $entry['invalid'],
            'reason_message' => $entry['invalid'] === null ? null : self::reasonMessage($entry),
            'warnings' => $entry['warnings'],
        ];
    }

    /**
     * 不能匯入的原因說明，有補充（例如重複的 ID）時接在後面。
     *
     * @param  array{invalid: string, invalid_detail?: string|null}  $entry
     */
    private static function reasonMessage(array $entry): string
    {
        $detail = $entry['invalid_detail'] ?? null;

        return ArticleSourceParser::INVALID_REASONS[$entry['invalid']].($detail === null ? '' : $detail);
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

    /**
     * 要更新的文章，站上記的 commit（上次匯入或連結時的版本）跟這次的 commit 是什麼關係。
     *
     * 為了擋舊畫面的匯入：清單是某個 commit 的內容，在那之後站上可能已經用更新的 commit 匯入過。
     * 這時 blob 也對不上，單看 blob 會判成 `changed`，照樣更新就會把文章倒回舊內容。
     * 先後要問 GitHub（Compare API），所以在 transaction 外問完；`olderThanImported()` 在鎖內確認
     * 站上的 commit 還是這裡比對過的那一個。
     *
     * @param  array{token: string, repo: string, ref: string}  $config
     * @param  list<array<string, mixed>>  $requested
     * @return array<string, string> 站上記的 commit => `ahead`（這次的較新）、`behind`（這次的較舊）、
     *                               `identical`、`diverged`（互不包含）、`unknown`（GitHub 查不到站上記的 commit，
     *                               或站上記的值不是 SHA 格式）
     */
    private function importedCommitRelations(array $config, string $commitSha, array $requested): array
    {
        $updateKeys = [];
        foreach ($requested as $item) {
            if ($item['action'] === self::ACTION_UPDATE) {
                $updateKeys[$item['key']] = true;
            }
        }
        if ($updateKeys === []) {
            return [];
        }

        $relations = [];
        foreach ($this->sourceRows($config['repo']) as $row) {
            $imported = $row->source_commit_sha;
            if (! isset($updateKeys[$row->source_key]) || ! is_string($imported) || $imported === $commitSha || isset($relations[$imported])) {
                continue;
            }
            // 這個值會組進 API 網址；正常只會是匯入時驗過的 SHA，其他值不送出，當成查不到
            $relations[$imported] = preg_match(self::SHA_PATTERN, $imported) === 1
                ? $this->compareCommits($config, $imported, $commitSha)
                : 'unknown';
        }

        return $relations;
    }

    /**
     * 這次的 commit 比站上記的舊，或站上記的 commit 已經不是比對過的那一個（比對完到上鎖之間又被匯入）時，
     * 回傳不能更新的原因。這兩種情況確認也不能放行。
     *
     * 互不包含（`diverged`）與 GitHub 查不到站上記的 commit（`unknown`）不在這裡擋，由 `unverifiedBase()` 要求確認。
     *
     * @param  array<string, string>  $importedCommits  `importedCommitRelations()` 的結果
     */
    private static function olderThanImported(Documentation $row, string $commitSha, array $importedCommits): ?string
    {
        $imported = $row->source_commit_sha;
        if (! is_string($imported) || $imported === $commitSha) {
            return null;
        }

        return match ($importedCommits[$imported] ?? null) {
            null => '站上這篇剛被匯入了其他版本，請重新整理清單。',
            'behind' => '站上這篇已經用比這個版本新的 commit 匯入過，不能用較舊的版本覆蓋。要退回舊內容，請在來源 repo 另外 commit。',
            default => null,
        };
    }

    /**
     * 判斷不出這次的 commit 跟站上記的 commit 誰新（`diverged`、`unknown`）時，回傳站上記的 commit；
     * 判斷得出來（或不用判斷）時回傳 null。
     *
     * 不直接放行：舊的匯入請求重送時剛好碰上這種情況，就會把文章倒回舊版。也不一律擋下：來源 repo
     * 改寫過歷史之後，新的 commit 跟站上記的舊 commit 都會是這樣，擋下來就再也更新不了。
     * 所以要求用戶端帶回站上記的那個 commit 表示確認過（`confirmsBase()`）。
     * 只在 `olderThanImported()` 放行之後呼叫，站上記的 commit 一定是比對過的那一個。
     *
     * @param  array<string, string>  $importedCommits  `importedCommitRelations()` 的結果
     */
    private static function unverifiedBase(Documentation $row, string $commitSha, array $importedCommits): ?string
    {
        $imported = $row->source_commit_sha;
        if (! is_string($imported) || $imported === $commitSha) {
            return null;
        }

        return in_array($importedCommits[$imported] ?? null, ['diverged', 'unknown'], true) ? $imported : null;
    }

    /**
     * 用戶端確認的是不是站上目前記的這個 commit。
     *
     * 綁定 commit 而不是只帶 true：收到 409 之後、確認之前，這篇可能又被別人用另一個 commit 匯入過，
     * 跟這次的 commit 一樣判斷不出先後；只帶 true 的話，確認就會套到使用者沒看過的那個版本上。
     * 站上記的值不是 SHA 格式時，請求驗證不讓這種值進來，永遠對不上。
     */
    private static function confirmsBase(string $base, ?string $confirmed): bool
    {
        return is_string($confirmed) && hash_equals($base, $confirmed);
    }

    /**
     * 409 回應附上的 `unverified_commits`：判斷不出先後、可以確認後更新的項目，各自要帶回的 commit。
     *
     * 寫成清單而不是以索引為 key 的物件：錯誤回應會再經過 `ProblemDetails` 解碼、重新編碼，
     * key 剛好是 0、1、2… 的物件會變成 JSON 陣列，形狀會跟著有幾項而變。
     * 站上記的值不是 SHA 格式的項目不列：請求驗證不收這種值，列出來也帶不回來。
     *
     * @param  array<int, array{row: Documentation, relation: string}>  $unverified  請求項目的索引 => 那一篇
     * @return array{unverified_commits?: list<array{index: int, commit_sha: string}>}
     */
    private static function unverifiedCommits(array $unverified): array
    {
        $commits = [];
        foreach ($unverified as $i => $u) {
            $base = (string) $u['row']->source_commit_sha;
            if (preg_match(self::SHA_PATTERN, $base) === 1) {
                $commits[] = ['index' => $i, 'commit_sha' => $base];
            }
        }

        return $commits === [] ? [] : ['unverified_commits' => $commits];
    }

    /**
     * 站上的標題、日期或內文跟上次匯入（或連結）時不同。沒有記錄就當作改過，要求明確覆蓋。
     *
     * 只記了內文雜湊的舊紀錄（這個比對納入標題與日期之前匯入的）也對不上，會判成改過：
     * 看不出那之後標題、日期有沒有被改，寧可多要求一次確認，也不要默默蓋掉。更新一次之後就是新的雜湊。
     */
    private static function siteEdited(Documentation $row): bool
    {
        return ! is_string($row->source_body_hash)
            || ! hash_equals($row->source_body_hash, self::importedContentHash($row));
    }

    /**
     * 站上目前的標題、日期與內文，跟清單上 `site_version`（列清單時用 `importedContentHash()` 算的）是不是同一版。
     *
     * 用內容的雜湊，不用 `updated_at`：`updated_at` 只到秒，同一秒內的修改分不出來；改發布狀態這類
     * 覆蓋不會動到的欄位也會讓它變，會多擋。雜湊只看覆蓋會蓋掉的三個欄位，跟判斷 `conflict` 的是同一個值。
     * 沒帶（null）一律當成對不上。
     */
    private static function sameSiteVersion(Documentation $row, ?string $expected): bool
    {
        return is_string($expected) && hash_equals(self::importedContentHash($row), $expected);
    }

    /**
     * 匯入會寫的三個欄位（標題、日期、內文）的 SHA-256，存在 `source_body_hash`。
     *
     * 欄位名稱是只比對內文時取的；改成三個欄位一起算，是因為 update 也會用來源的標題與日期覆蓋站上的值，
     * 只比內文的話，後台改過標題的文章會被判成 `changed`，不經確認就被蓋掉。
     * 每個值前面加上長度（NULL 寫成 `-`），欄位內容裡有什麼字元都不會讓兩組不同的值算出同一個字串。
     * public 是給測試準備「上次匯入時的狀態」用，正式程式只有這個類別呼叫。
     */
    public static function importedContentHash(Documentation $documentation): string
    {
        $fields = [$documentation->title, self::normalizedDate($documentation->creation_date), $documentation->body];

        return hash('sha256', implode("\n", array_map(
            fn (?string $value) => $value === null ? '-' : strlen($value).':'.$value,
            $fields,
        )));
    }

    /**
     * `creation_date` 統一成 `Y-m-d H:i:s` 再算雜湊。
     *
     * 這欄沒有 cast，讀到的是資料庫給的字串：匯入時寫的是 `2026-09-22`，PostgreSQL（timestamp）讀回來是
     * `2026-09-22 00:00:00`，SQLite 照原樣存。不統一的話，同一個日期寫入時跟讀回來時算出不同的雜湊。
     * 解析不了的值（只可能是 SQLite 裡手動寫進去的）照原字串算。
     */
    private static function normalizedDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (InvalidFormatException) {
            return (string) $value;
        }
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
     * Compare API 的 `status`：`head` 相對於 `base` 是 `ahead`、`behind`、`identical` 還是 `diverged`。
     * GitHub 查不到 `base`（404、422）時回 `unknown`；其他錯誤照樣丟出，整批不寫。
     *
     * `per_page=1`：只要 `status`，回應裡的 commit 清單取最少。
     *
     * @param  array{token: string, repo: string, ref: string}  $config
     */
    private function compareCommits(array $config, string $base, string $head): string
    {
        try {
            [$compare] = $this->fetchCached($config, '/repos/'.$config['repo'].'/compare/'.$base.'...'.$head.'?per_page=1', $this->reduceCompare(...));
        } catch (ArticleSourceException $e) {
            if (in_array($e->upstreamStatus, [404, 422], true)) {
                return 'unknown';
            }
            throw $e;
        }

        return $compare['status'];
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
     * @return array{status: string}
     */
    private function reduceCompare(mixed $json): array
    {
        $status = is_array($json) ? ($json['status'] ?? null) : null;

        if (! in_array($status, ['ahead', 'behind', 'identical', 'diverged'], true)) {
            throw ArticleSourceException::badResponse();
        }

        return ['status' => $status];
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
