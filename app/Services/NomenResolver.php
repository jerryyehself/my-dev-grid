<?php

namespace App\Services;

use App\Models\Implementation;
use App\Models\Nomen;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Support\Collection;

/**
 * 把 `nomens` 的字串對到站上的節點：先正規化，再依來源限定能對到哪種節點、哪些分類、用哪些變體規則。
 *
 * 目前是影子模式：結果只寫回 `nomens` 的 `res_*` 與 `resolution`，同步與文章匯入照舊用自己的比對法寫邊。
 * 每次都對整張表重算（幾百列的量級），所以後來才出現的節點會讓以前對不上的字串自動接上（晚綁定），
 * 結果也不受同步先後順序影響。
 *
 * 變體規則依序試，第一個對上的就用，`resolution` 記下用了哪一條：
 * - `exact`：正規化後的鍵跟節點的顯示名稱（`Technique::labelFrom()`、專案 `title`）完全相等。
 * - `variant:suffix`：去掉或補上 `js`／`css` 字尾（`vuejs`→Vue、`tailwind`→Tailwind CSS）。對應 LRM 的
 *   R17（同一個 res 的另一種寫法）。字根至少 3 個字元。
 * - `part:version`：拆出結尾的版本號（`vue3`→Vue 3）。對應 LRM 的 R16（版本是名稱的一部分，指的是
 *   另一個 res）。只在整個鍵對不到節點、而且字根對得到語言／框架／套件工具的節點時才拆，`isbn3`、
 *   `html5-qrcode` 這類套件名不會被拆。
 * - `crosswalk`：規則推不出來的跨命名系統對照（Linguist 的 Procfile→Heroku）。
 */
final class NomenResolver
{
    /**
     * 每種來源：[能對到哪種節點, 限定的分類（null 是不限）, 可以用的變體規則]。
     *
     * GitHub 語言只能對到 `language`，Linguist 的誤判（例如 Hack）才不會碰到同名的其他節點。
     * topic 不限分類，但在影子模式裡不主張任何述詞，只用來確認身分（見 `NomenRecorder`）。
     */
    private const SOURCES = [
        Nomen::SOURCE_GITHUB_LANGUAGE => [Nomen::RES_TECHNIQUE, ['language'], ['crosswalk']],
        Nomen::SOURCE_GITHUB_TOPIC => [Nomen::RES_TECHNIQUE, null, ['suffix', 'version', 'crosswalk']],
        Nomen::SOURCE_ABOUT_TECHNIQUES => [Nomen::RES_TECHNIQUE, null, ['suffix', 'version']],
        Nomen::SOURCE_ABOUT_PROJECTS => [Nomen::RES_IMPLEMENTATION, ['project'], []],
    ];

    /** 同一次同步一起寫入的來源：判斷「最新一次看到」時要一起看。 */
    private const SYNC_SOURCES = [Nomen::SOURCE_GITHUB_LANGUAGE, Nomen::SOURCE_GITHUB_TOPIC];

    private const SUFFIXES = ['js', 'css'];

    private const MIN_ROOT_LENGTH = 3;

    /** 拆版本號時，字根要對到這幾種分類的節點。 */
    private const VERSION_ROOT_SCOPES = ['language', 'framework', 'packagetool'];

    /** 正規化後的鍵 => 正規化後的節點鍵。只放規則推不出來的。 */
    private const CROSSWALK = [
        'procfile' => 'heroku',
    ];

    /**
     * GitHub 語言要撐起一條邊的證據門檻：至少這麼多位元組，而且（占這個 repo 的比例夠高，或其他 repo
     * 也過了下限）。數字依現有資料訂，日後要調改這裡。沒有位元組數（舊快照）時當成通過。
     */
    public const MIN_LANGUAGE_BYTES = 1024;

    public const MIN_LANGUAGE_SHARE = 0.2;

    /**
     * 比對用的鍵：全形英數轉半形、轉小寫，只留字母、數字與 `+`、`#`。
     *
     * 在 PHP 算好存起來，資料庫只做完全相等比對，避開 SQLite 的 `LOWER()` 只轉 ASCII、PostgreSQL 依語系的差異。
     * 映像檔沒有 intl，所以不用 `Normalizer`（NFKC），全形轉半形用 mbstring。留下 `+`、`#` 是因為
     * Linguist 有 C、C++、C# 三種語言，去掉就會變成同一個鍵。
     */
    public static function normalize(string $string): string
    {
        $string = mb_strtolower(mb_convert_kana(trim($string), 'as', 'UTF-8'), 'UTF-8');

        return preg_replace('/[^\p{L}\p{N}+#]+/u', '', $string) ?? '';
    }

    /**
     * 每個「同一批寫入」裡最新一次看到的列。
     *
     * 同步一次寫入所有 repo，`last_seen_at` 都是同一個時間，所以 GitHub 來源取整張表最新的那個時間；
     * 文章是一篇一篇匯入，取每篇文章自己最新的時間。比它舊的列，是後來的同步或匯入已經沒看到的字串。
     *
     * @param  Collection<int, Nomen>  $rows
     * @return Collection<int, Nomen>
     */
    public static function current(Collection $rows): Collection
    {
        $latestSync = $rows->filter(fn (Nomen $row) => in_array($row->source, self::SYNC_SOURCES, true))
            ->max(fn (Nomen $row) => $row->last_seen_at->getTimestamp());
        $latestByContext = $rows->reject(fn (Nomen $row) => in_array($row->source, self::SYNC_SOURCES, true))
            ->groupBy(fn (Nomen $row) => $row->context_type.':'.$row->context_id)
            ->map(fn (Collection $group) => $group->max(fn (Nomen $row) => $row->last_seen_at->getTimestamp()));

        return $rows->filter(fn (Nomen $row) => $row->last_seen_at->getTimestamp() === (
            in_array($row->source, self::SYNC_SOURCES, true) ? $latestSync : $latestByContext[$row->context_type.':'.$row->context_id]
        ));
    }

    /** 重算整張 `nomens` 對上的節點。 */
    public function resolveAll(): void
    {
        $rows = Nomen::all();
        if ($rows->isEmpty()) {
            return;
        }

        $targets = $this->targets();
        $results = [];
        $ambiguous = [];

        foreach ($rows as $row) {
            [$resType, $candidates, $rule] = $this->match($row, $targets);

            if (count($candidates) === 1) {
                $results[$row->id] = [$resType, $candidates[0]['id'], $rule];
            } elseif ($candidates === []) {
                $results[$row->id] = [null, null, Nomen::UNRESOLVED];
            } else {
                $results[$row->id] = [null, null, Nomen::AMBIGUOUS];
                $ambiguous[$row->id] = [$resType, array_column($candidates, 'id'), $rule];
            }
        }

        $this->preferContext($rows, $results, $ambiguous);

        $current = self::current($rows)->keyBy('id');
        $this->applyLanguageThreshold($current, $results);
        $this->applyTopicCorroboration($current, $results);

        foreach ($rows as $row) {
            [$resType, $resId, $resolution] = $results[$row->id];
            $row->fill(['res_type' => $resType, 'res_id' => $resId, 'resolution' => $resolution]);
            if ($row->isDirty()) {
                $row->save();
            }
        }
    }

    /**
     * 站上能被對到的節點，依種類分。每個節點帶比對鍵與它所屬分類。
     *
     * @return array<string, list<array{id: int, key: string, title_key: string, version: string|null, scopes: list<string>}>>
     */
    private function targets(): array
    {
        $ancestry = ScopeAncestry::fromDatabase();
        $scopeIds = Scope::whereIn('name', ['language', 'framework', 'packagetool', 'project'])->pluck('id', 'name')
            ->map(fn ($id) => (int) $id)->all();
        $scopesOf = fn ($type) => array_keys(array_filter(
            $scopeIds,
            fn (int $id) => $ancestry->matches($type === null ? null : (int) $type, $id),
        ));

        return [
            Nomen::RES_TECHNIQUE => Technique::all(['id', 'type', 'title', 'version'])->map(fn (Technique $t) => [
                'id' => $t->id,
                'key' => self::normalize($t->label),
                'title_key' => self::normalize($t->title),
                'version' => filled($t->version) ? (string) $t->version : null,
                'scopes' => $scopesOf($t->type),
            ])->all(),
            Nomen::RES_IMPLEMENTATION => Implementation::all(['id', 'type', 'title'])->map(fn (Implementation $i) => [
                'id' => $i->id,
                'key' => self::normalize((string) $i->title),
                'title_key' => self::normalize((string) $i->title),
                'version' => null,
                'scopes' => $scopesOf($i->type),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $targets
     * @return array{0: string|null, 1: list<array<string, mixed>>, 2: string|null} [節點種類, 候選節點, 規則]
     */
    private function match(Nomen $row, array $targets): array
    {
        if (! isset(self::SOURCES[$row->source])) {
            return [null, [], null];
        }

        [$resType, $allowedScopes, $rules] = self::SOURCES[$row->source];
        $pool = array_values(array_filter(
            $targets[$resType],
            fn (array $t) => $allowedScopes === null || array_intersect($allowedScopes, $t['scopes']) !== [],
        ));
        $key = $row->normalized;
        $byKey = fn (string $k) => array_values(array_filter($pool, fn (array $t) => $t['key'] === $k));

        if (($found = $byKey($key)) !== []) {
            return [$resType, $found, 'exact'];
        }

        if (in_array('suffix', $rules, true)) {
            $found = [];
            foreach (self::SUFFIXES as $suffix) {
                $root = str_ends_with($key, $suffix) ? substr($key, 0, -strlen($suffix)) : null;
                if ($root !== null && mb_strlen($root) >= self::MIN_ROOT_LENGTH) {
                    $found = [...$found, ...$byKey($root)];
                } elseif ($root === null && mb_strlen($key) >= self::MIN_ROOT_LENGTH) {
                    $found = [...$found, ...$byKey($key.$suffix)];
                }
            }
            if ($found !== []) {
                return [$resType, $found, 'variant:suffix'];
            }
        }

        if (in_array('version', $rules, true) && preg_match('/^(.*\D)(\d+)$/u', $key, $m) && mb_strlen($m[1]) >= self::MIN_ROOT_LENGTH) {
            $bases = array_filter($pool, fn (array $t) => $t['key'] === $m[1] && $t['version'] === null
                && array_intersect(self::VERSION_ROOT_SCOPES, $t['scopes']) !== []);
            if ($bases !== []) {
                $versioned = array_values(array_filter($pool, fn (array $t) => $t['title_key'] === $m[1] && $t['version'] === $m[2]));

                return [$resType, $versioned !== [] ? $versioned : array_values($bases), 'part:version'];
            }
        }

        // 對照表本來就是跨命名系統、跨分類的（Linguist 的「語言」Procfile 指的是套件工具 Heroku），
        // 所以不受來源的分類限制，查同一種節點的全部
        if (in_array('crosswalk', $rules, true) && isset(self::CROSSWALK[$key])) {
            $found = array_values(array_filter($targets[$resType], fn (array $t) => $t['key'] === self::CROSSWALK[$key]));
            if ($found !== []) {
                return [$resType, $found, 'crosswalk'];
            }
        }

        return [$resType, [], null];
    }

    /**
     * 對上不只一個節點時，選同一個作品裡其他名稱已經對到的那一個；還是分不出來就維持 `ambiguous`。
     *
     * @param  Collection<int, Nomen>  $rows
     * @param  array<int, array{0: string|null, 1: int|null, 2: string}>  $results
     * @param  array<int, array{0: string, 1: list<int>, 2: string}>  $ambiguous
     */
    private function preferContext(Collection $rows, array &$results, array $ambiguous): void
    {
        $byId = $rows->keyBy('id');

        foreach ($ambiguous as $id => [$resType, $candidateIds, $rule]) {
            $row = $byId[$id];
            $seen = $rows->filter(fn (Nomen $other) => $other->id !== $id
                && $other->context_type === $row->context_type && $other->context_id === $row->context_id
                && $results[$other->id][0] === $resType && in_array($results[$other->id][1], $candidateIds, true))
                ->map(fn (Nomen $other) => $results[$other->id][1])->unique()->values();

            if ($seen->count() === 1) {
                $results[$id] = [$resType, $seen->first(), $rule];
            }
        }
    }

    /**
     * GitHub 語言的證據門檻（只看最新一次同步看到的列）。對上了但證據不夠的，節點留著、結果改成 `below_threshold`。
     *
     * @param  Collection<int, Nomen>  $current
     * @param  array<int, array{0: string|null, 1: int|null, 2: string}>  $results
     */
    private function applyLanguageThreshold(Collection $current, array &$results): void
    {
        $languages = $current->where('source', Nomen::SOURCE_GITHUB_LANGUAGE);
        $totals = $languages->groupBy('context_id')->map(fn (Collection $group) => $group->sum('weight'));

        foreach ($languages as $row) {
            if ($results[$row->id][1] === null || $row->weight === null) {
                continue;
            }

            $passes = $row->weight >= self::MIN_LANGUAGE_BYTES && (
                $row->weight >= self::MIN_LANGUAGE_SHARE * $totals[$row->context_id]
                || $languages->contains(fn (Nomen $other) => $other->context_id !== $row->context_id
                    && $other->normalized === $row->normalized
                    && ($other->weight === null || $other->weight >= self::MIN_LANGUAGE_BYTES))
            );

            if (! $passes) {
                $results[$row->id][2] = Nomen::BELOW_THRESHOLD;
            }
        }
    }

    /**
     * topic 對上的節點，同一個 repo 這次同步的語言也撐得起同一個節點時，記成 `corroborated`。
     * 真正撐起邊的是語言那一列，topic 只是旁證；之後讀了套件清單，也從這裡加上。
     *
     * @param  Collection<int, Nomen>  $current
     * @param  array<int, array{0: string|null, 1: int|null, 2: string}>  $results
     */
    private function applyTopicCorroboration(Collection $current, array &$results): void
    {
        $supported = $current->where('source', Nomen::SOURCE_GITHUB_LANGUAGE)
            ->filter(fn (Nomen $row) => $results[$row->id][1] !== null && $results[$row->id][2] !== Nomen::BELOW_THRESHOLD)
            ->map(fn (Nomen $row) => $row->context_id.':'.$results[$row->id][1])
            ->flip();

        foreach ($current->where('source', Nomen::SOURCE_GITHUB_TOPIC) as $row) {
            if ($results[$row->id][1] !== null && isset($supported[$row->context_id.':'.$results[$row->id][1]])) {
                $results[$row->id][2] = Nomen::CORROBORATED;
            }
        }
    }
}
