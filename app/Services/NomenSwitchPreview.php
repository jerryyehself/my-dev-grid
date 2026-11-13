<?php

namespace App\Services;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Nomen;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 影子報表：如果同步與文章匯入改成照 `nomens` 的解析結果寫邊，跟現在比會差在哪裡。唯讀，不寫任何資料。
 *
 * 只看最新一次同步、每篇文章最新一次匯入讀到的列（`NomenResolver::current()`）。切換後的規則：
 * - 專案的 `usedBy` 邊：只有 GitHub 語言對上、而且過了證據門檻的節點撐得起；topic 只當旁證，
 *   撐不起邊。撐不起的既有邊不會被刪，只是不再更新 `last_seen_at`，14 天後變成過期。
 *   跟現在的同步一樣，有版本的節點撐得起時，同名、沒有版本的那一筆也算撐得起。
 * - 文章的連結：front matter 的名稱對上的節點，文章還沒連過去的，會新增連結。
 *   這裡沒有檢查分類是否符合述詞（`ArticleAboutLinker` 會），實際切換時可能少幾筆。
 */
final class NomenSwitchPreview
{
    /**
     * @return array{
     *     rows: int,
     *     projects: list<array{title: string, keep: list<string>, stop: list<string>, add: list<string>}>,
     *     articles: list<array{title: string, linked: list<string>, add: list<string>}>,
     *     pending: list<array{source: string, string: string, resolution: string, contexts: int}>,
     *     concepts: list<array{string: string, contexts: int}>
     * }
     */
    public function build(): array
    {
        $rows = Nomen::all();
        $current = NomenResolver::current($rows);

        return [
            'rows' => $rows->count(),
            'projects' => $this->projects($current),
            'articles' => $this->articles($current),
            'pending' => $this->pending($current),
            'concepts' => $this->concepts($current),
        ];
    }

    /**
     * @param  Collection<int, Nomen>  $current
     * @return list<array{title: string, keep: list<string>, stop: list<string>, add: list<string>}>
     */
    private function projects(Collection $current): array
    {
        $usedBy = Relation::where('name', 'usedBy')->value('id');
        $techniques = Technique::all(['id', 'title', 'version'])->keyBy('id');
        $label = fn (int $id) => $techniques[$id]->label;
        $result = [];

        $contexts = $current->whereIn('source', [Nomen::SOURCE_GITHUB_LANGUAGE, Nomen::SOURCE_GITHUB_TOPIC])->pluck('context_id')->unique();
        foreach (Implementation::whereIn('id', $contexts)->orderBy('title')->get(['id', 'title']) as $project) {
            $supported = $current->where('source', Nomen::SOURCE_GITHUB_LANGUAGE)->where('context_id', $project->id)
                ->filter(fn (Nomen $row) => $row->isUsable() && $row->res_type === Nomen::RES_TECHNIQUE && isset($techniques[$row->res_id]))
                ->pluck('res_id')->unique()->values()->all();
            $versionedTitles = collect($supported)->filter(fn (int $id) => filled($techniques[$id]->version))
                ->map(fn (int $id) => mb_strtolower($techniques[$id]->title))->all();

            $edges = $usedBy === null ? [] : DB::table('technique_implementation')
                ->where('implementation_id', $project->id)->where('relation_id', $usedBy)
                ->pluck('technique_id')->map(fn ($id) => (int) $id)->filter(fn (int $id) => isset($techniques[$id]))->unique()->values()->all();

            $isSupported = fn (int $id) => in_array($id, $supported, true)
                || (blank($techniques[$id]->version) && in_array(mb_strtolower($techniques[$id]->title), $versionedTitles, true));

            $result[] = [
                'title' => $project->title,
                'keep' => $this->labels(array_filter($edges, $isSupported), $label),
                'stop' => $this->labels(array_filter($edges, fn (int $id) => ! $isSupported($id)), $label),
                'add' => $this->labels(array_diff($supported, $edges), $label),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Nomen>  $current
     * @return list<array{title: string, linked: list<string>, add: list<string>}>
     */
    private function articles(Collection $current): array
    {
        $techniques = Technique::all(['id', 'title', 'version'])->keyBy('id');
        $projects = Implementation::all(['id', 'title'])->keyBy('id');
        $kinds = [
            Nomen::SOURCE_ABOUT_TECHNIQUES => [Nomen::RES_TECHNIQUE, 'documentation_technique', 'technique_id', fn (int $id) => $techniques[$id]->label ?? null],
            Nomen::SOURCE_ABOUT_PROJECTS => [Nomen::RES_IMPLEMENTATION, 'documentation_implementation', 'implementation_id', fn (int $id) => $projects[$id]->title ?? null],
        ];
        $result = [];

        $contexts = $current->whereIn('source', array_keys($kinds))->pluck('context_id')->unique();
        foreach (Documentation::whereIn('id', $contexts)->orderBy('title')->get(['id', 'title']) as $documentation) {
            $linked = [];
            $add = [];
            foreach ($kinds as $source => [$resType, $table, $column, $label]) {
                $existing = DB::table($table)->where('documentation_id', $documentation->id)->pluck($column)->map(fn ($id) => (int) $id)->all();
                $targets = $current->where('source', $source)->where('context_id', $documentation->id)
                    ->filter(fn (Nomen $row) => $row->isUsable() && $row->res_type === $resType && $label($row->res_id) !== null)
                    ->pluck('res_id')->unique()->all();

                $linked = [...$linked, ...$this->labels(array_intersect($targets, $existing), $label)];
                $add = [...$add, ...$this->labels(array_diff($targets, $existing), $label)];
            }

            $result[] = ['title' => $documentation->title, 'linked' => $linked, 'add' => $add];
        }

        return $result;
    }

    /**
     * 對不上、對上不只一個、證據不夠的名稱，以及各出現在幾個作品。
     *
     * @param  Collection<int, Nomen>  $current
     * @return list<array{source: string, string: string, resolution: string, contexts: int}>
     */
    private function pending(Collection $current): array
    {
        return $current->whereIn('resolution', [Nomen::UNRESOLVED, Nomen::AMBIGUOUS, Nomen::BELOW_THRESHOLD])
            ->groupBy(fn (Nomen $row) => $row->source."\0".$row->normalized."\0".$row->resolution)
            ->map(fn (Collection $group) => [
                'source' => $group->first()->source,
                'string' => $group->sortBy('id')->first()->string,
                'resolution' => $group->first()->resolution,
                'contexts' => $group->count(),
            ])
            ->sortBy([['source', 'asc'], ['contexts', 'desc'], ['string', 'asc']])
            ->values()->all();
    }

    /**
     * 文章 `about_techniques` 裡對不上任何技術、而且出現在 2 篇以上文章的名稱：之後開放自動建立概念節點時，
     * 這些會長成新的節點。先列出來，讓人看看有沒有其實不是概念的（人名、書名、組織）。
     *
     * @param  Collection<int, Nomen>  $current
     * @return list<array{string: string, contexts: int}>
     */
    private function concepts(Collection $current): array
    {
        return $current->where('source', Nomen::SOURCE_ABOUT_TECHNIQUES)->where('resolution', Nomen::UNRESOLVED)
            ->groupBy('normalized')
            ->filter(fn (Collection $group) => $group->pluck('context_id')->unique()->count() >= 2)
            ->map(fn (Collection $group) => ['string' => $group->sortBy('id')->first()->string, 'contexts' => $group->pluck('context_id')->unique()->count()])
            ->sortBy('string')->values()->all();
    }

    /**
     * @param  iterable<int>  $ids
     * @return list<string>
     */
    private function labels(iterable $ids, callable $label): array
    {
        $labels = [];
        foreach ($ids as $id) {
            $labels[] = $label($id);
        }
        sort($labels);

        return $labels;
    }
}
