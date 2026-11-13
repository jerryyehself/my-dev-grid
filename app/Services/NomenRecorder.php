<?php

namespace App\Services;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Nomen;
use App\Models\Relation;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 影子模式：同步與文章匯入讀到的名稱字串，另外記一份到 `nomens`，邊照舊由原本的程式寫。
 *
 * 這裡的任何錯誤都不能影響原本的同步與匯入：每次寫入包在自己的 transaction 裡（外面已經有
 * transaction 時是 savepoint），失敗就退回這一段、記一則警告，然後照常往下走。PostgreSQL 上
 * transaction 裡一句失敗會讓整個 transaction 失效，所以光 catch 不夠，一定要有 savepoint。
 *
 * 寫入規則：
 * - 以 `(source, context_type, context_id, normalized)` 為鍵 upsert：再看到同一個字串只更新
 *   `last_seen_at`、`weight`、`predicate_id`，`string` 與 `first_seen_at` 留第一次的。
 * - 同一批裡正規化後相同的字串只留第一個（`Vue 3` 和 `vue3` 是同一列）。
 * - 正規化後是空字串的（只有標點或空白）不記。
 * - 不刪列：後來沒再看到的字串留著，`last_seen_at` 停在最後一次看到的時間。
 */
final class NomenRecorder
{
    public function __construct(private readonly NomenResolver $resolver) {}

    /**
     * 記下一個 repo 這次同步讀到的語言與 topic。
     *
     * 語言主張 `usedBy`（技術 usedBy 專案），權重是位元組數（`GitService` 的 `language_bytes`，
     * 舊快照沒有就是空值）。topic 不主張任何述詞：它是自由標籤，不保證代表「用到」，只拿來確認身分。
     * 被同步略過的語言（`SaveReposDataService::IGNORED_LANGUAGES`）這裡照樣記，影子報表才看得到它。
     *
     * @param  array<string, mixed>  $repo  `GitService::clean_repo_info()` 的形狀
     */
    public function recordRepo(Implementation $project, array $repo, DateTimeInterface $seenAt): void
    {
        $this->shadow('同步 repo', function () use ($project, $repo, $seenAt) {
            $bytes = is_array($repo['language_bytes'] ?? null) ? $repo['language_bytes'] : [];
            $languages = array_map(
                fn ($name) => [$name, is_string($name) && is_int($bytes[$name] ?? null) ? $bytes[$name] : null],
                $repo['languages'] ?? [],
            );
            $topics = array_map(fn ($topic) => [$topic, null], $repo['topics'] ?? []);

            $this->write(Nomen::SOURCE_GITHUB_LANGUAGE, 'linguist', Nomen::CONTEXT_IMPLEMENTATION, $project->id, $this->relationId('usedBy'), $languages, $seenAt);
            $this->write(Nomen::SOURCE_GITHUB_TOPIC, 'github-topics', Nomen::CONTEXT_IMPLEMENTATION, $project->id, null, $topics, $seenAt);
        });
    }

    /**
     * 記下一篇文章 front matter 的 `about_techniques`、`about_projects`。兩個欄位是不同的來源，
     * 同一個名稱同時寫在兩邊時是兩列。
     *
     * @param  array{techniques: list<string>, projects: list<string>}  $about
     */
    public function recordArticle(Documentation $documentation, array $about): void
    {
        $this->shadow('匯入文章', function () use ($documentation, $about) {
            $seenAt = now();
            $entries = fn (array $names) => array_map(fn ($name) => [$name, null], $names);

            $this->write(Nomen::SOURCE_ABOUT_TECHNIQUES, 'site', Nomen::CONTEXT_DOCUMENTATION, $documentation->id, $this->relationId('about'), $entries($about['techniques'] ?? []), $seenAt);
            $this->write(Nomen::SOURCE_ABOUT_PROJECTS, 'site', Nomen::CONTEXT_DOCUMENTATION, $documentation->id, $this->relationId('aboutProject'), $entries($about['projects'] ?? []), $seenAt);
            $this->resolver->resolveAll();
        });
    }

    /** 同步完所有 repo 之後，重算整張表對上的節點。 */
    public function resolve(): void
    {
        $this->shadow('解析', fn () => $this->resolver->resolveAll());
    }

    /**
     * @param  list<array{0: mixed, 1: int|null}>  $entries  [字串, 權重]
     */
    private function write(string $source, string $scheme, string $contextType, int $contextId, ?int $predicateId, array $entries, DateTimeInterface $seenAt): void
    {
        $rows = [];
        foreach ($entries as [$string, $weight]) {
            if (! is_string($string)) {
                continue;
            }
            $string = trim($string);
            $normalized = NomenResolver::normalize($string);
            if ($normalized === '' || isset($rows[$normalized])) {
                continue;
            }

            $rows[$normalized] = [
                'string' => $string,
                'normalized' => $normalized,
                'source' => $source,
                'scheme' => $scheme,
                'context_type' => $contextType,
                'context_id' => $contextId,
                'predicate_id' => $predicateId,
                'weight' => $weight,
                'resolution' => Nomen::UNRESOLVED,
                'first_seen_at' => $seenAt,
                'last_seen_at' => $seenAt,
                'created_at' => $seenAt,
                'updated_at' => $seenAt,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('nomens')->upsert(
            array_values($rows),
            ['source', 'context_type', 'context_id', 'normalized'],
            ['predicate_id', 'weight', 'last_seen_at', 'updated_at'],
        );
    }

    private function relationId(string $name): ?int
    {
        $id = Relation::where('name', $name)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function shadow(string $what, Closure $write): void
    {
        try {
            DB::transaction($write);
        } catch (Throwable $e) {
            Log::warning("nomens 影子寫入失敗（{$what}），已略過；同步與匯入照常。", ['exception' => $e]);
        }
    }
}
