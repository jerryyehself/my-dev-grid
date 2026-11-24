<?php

namespace App\Services;

use App\Models\EntityRelation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Support\Collection;

/**
 * 詞表裡技術之間的上下位與相關（SKOS 的 broader、narrower、related）。
 *
 * 哪些述詞算：名稱是 broader／narrower／related 的述詞，加上沿 `relations.parent_class` 掛在它們底下的子述詞
 * （例如把 isVersionOf 掛到 broader 底下之後，「Vue 3 isVersionOf Vue」就算一條上下位）。邊只存一個方向：
 * 上下位存 broader（下位指向上位），narrower 是反著走時的名稱；萬一有 narrower 的邊，這裡反過來當成 broader 讀。
 *
 * 只看 `entity_relations` 裡 `entity_type` 是 technique 的邊，兩端都要是還在（沒有軟刪除）的技術。
 * 一次讀進記憶體：技術是幾十筆、邊是幾百條的量級。
 */
final class SemanticRelations
{
    public const BROADER = 'broader';

    public const NARROWER = 'narrower';

    public const RELATED = 'related';

    /**
     * @param  array<int, list<int>>  $broader  下位 id => 直接上位的 id
     * @param  array<int, list<int>>  $related  id => 相關的 id（兩個方向都記）
     */
    public function __construct(private readonly array $broader, private readonly array $related) {}

    public static function fromDatabase(): self
    {
        $families = self::predicateFamilies();
        $relationIds = array_merge(...array_values($families));
        $techniqueIds = array_flip(Technique::pluck('id')->map(fn ($id) => (int) $id)->all());

        $broader = [];
        $related = [];
        $edges = $relationIds === [] ? collect() : EntityRelation::where('entity_type', 'technique')
            ->whereIn('relation_id', $relationIds)
            ->get(['subject_id', 'object_id', 'relation_id']);

        foreach ($edges as $edge) {
            $subject = (int) $edge->subject_id;
            $object = (int) $edge->object_id;
            if (! isset($techniqueIds[$subject], $techniqueIds[$object])) {
                continue;
            }

            $relationId = (int) $edge->relation_id;
            if (in_array($relationId, $families[self::BROADER], true)) {
                $broader[$subject][] = $object;
            } elseif (in_array($relationId, $families[self::NARROWER], true)) {
                $broader[$object][] = $subject;
            } else {
                $related[$subject][] = $object;
                $related[$object][] = $subject;
            }
        }

        return new self(array_map(self::uniqueIds(...), $broader), array_map(self::uniqueIds(...), $related));
    }

    /**
     * 三種述詞各自的 id，含沿 `parent_class` 往下的所有子述詞。找不到那個名稱的述詞時是空的。
     * 子述詞同時掛在兩種底下（只有直接改資料庫才會發生）時，先算 broader、再算 narrower、最後算 related。
     *
     * @return array{broader: list<int>, narrower: list<int>, related: list<int>}
     */
    public static function predicateFamilies(): array
    {
        $relations = Relation::all(['id', 'name', 'parent_class']);
        $childrenOf = $relations->toBase()->groupBy(fn (Relation $relation) => (int) $relation->parent_class);
        $claimed = [];
        $families = [];

        foreach ([self::BROADER, self::NARROWER, self::RELATED] as $name) {
            $root = $relations->firstWhere('name', $name);
            $ids = $root === null ? [] : self::descendantIds((int) $root->id, $childrenOf);
            $families[$name] = array_values(array_diff($ids, $claimed));
            $claimed = [...$claimed, ...$families[$name]];
        }

        return $families;
    }

    /**
     * 直接連到 $id 的技術（上位、下位、相關都算），不含自己。
     *
     * @return list<int>
     */
    public function neighbours(int $id): array
    {
        $narrower = array_keys(array_filter($this->broader, fn (array $parents) => in_array($id, $parents, true)));

        $all = [...($this->broader[$id] ?? []), ...$narrower, ...($this->related[$id] ?? [])];

        return self::uniqueIds(array_values(array_filter($all, fn (int $other) => $other !== $id)));
    }

    /** @return array<int, list<int>> 下位 id => 直接上位的 id */
    public function broader(): array
    {
        return $this->broader;
    }

    /**
     * 每一對相關只列一次，id 小的在前。
     *
     * @return list<array{0: int, 1: int}>
     */
    public function relatedPairs(): array
    {
        $pairs = [];
        foreach ($this->related as $id => $others) {
            foreach ($others as $other) {
                $pairs[min($id, $other).'-'.max($id, $other)] = [min($id, $other), max($id, $other)];
            }
        }

        return array_values($pairs);
    }

    /**
     * 把 $from 合併進 $into 之後的樣子（不寫資料庫）：$from 的邊全部改接到 $into，改接後自己連自己的拿掉，
     * 重複的只留一條。跟 `TechniqueMerger` 搬 entity_relations 的做法一致。
     */
    public function merged(int $from, int $into): self
    {
        $swap = fn (int $id) => $id === $from ? $into : $id;
        $rewire = function (array $edges) use ($swap) {
            $result = [];
            foreach ($edges as $id => $others) {
                foreach ($others as $other) {
                    [$a, $b] = [$swap($id), $swap($other)];
                    if ($a !== $b) {
                        $result[$a][] = $b;
                    }
                }
            }

            return array_map(self::uniqueIds(...), $result);
        };

        return new self($rewire($this->broader), $rewire($this->related));
    }

    /**
     * 違反詞表規則的地方，key 是可以拿來比對前後差異的字串，值是 [規則, 牽涉的技術 id]。規則：
     *
     * - `self`：自己連自己（qSKOS `rrc`）。
     * - `cycle`：上下位繞成環（qSKOS `chr`）。值是環上的所有技術。
     * - `related_in_hierarchy`：兩個技術標了相關，又有上下位路徑（SKOS S27：related 跟 broaderTransitive 互斥；qSKOS `rc`）。
     * - `redundant_broader`：已經可以經別的上位間接走到的上位，又直接標了一次（qSKOS `hr`）。
     *
     * @return array<string, array{0: string, 1: list<int>}>
     */
    public function problems(): array
    {
        $problems = [];
        $reach = [];
        $nodes = array_unique([...array_keys($this->broader), ...array_merge([], ...array_values($this->broader))]);
        foreach ($nodes as $id) {
            $reach[$id] = $this->ancestors($id);
        }

        foreach ($this->broader as $narrower => $parents) {
            if (in_array($narrower, $parents, true)) {
                $problems["self:{$narrower}"] = ['self', [$narrower]];
            }
        }
        foreach ($this->related as $id => $others) {
            if (in_array($id, $others, true)) {
                $problems["self:{$id}"] = ['self', [$id]];
            }
        }

        // 環上的技術互相走得到；只有自己連自己的那種算在 self，不算環
        foreach ($reach as $id => $ancestors) {
            $cycle = array_keys(array_filter($ancestors, fn ($_, int $other) => isset($reach[$other][$id]), ARRAY_FILTER_USE_BOTH));
            if (count(array_unique([...$cycle, $id])) >= 2 && in_array($id, $cycle, true)) {
                sort($cycle);
                $problems['cycle:'.implode(',', $cycle)] = ['cycle', $cycle];
            }
        }

        foreach ($this->relatedPairs() as [$a, $b]) {
            if ($a !== $b && (isset($reach[$a][$b]) || isset($reach[$b][$a]))) {
                $problems["related_in_hierarchy:{$a}-{$b}"] = ['related_in_hierarchy', [$a, $b]];
            }
        }

        foreach ($this->broader as $narrower => $parents) {
            foreach ($parents as $parent) {
                $viaOthers = array_filter($parents, fn (int $other) => $other !== $parent && $other !== $narrower && isset($reach[$other][$parent]));
                if ($parent !== $narrower && $viaOthers !== []) {
                    $problems["redundant_broader:{$narrower}-{$parent}"] = ['redundant_broader', [$narrower, $parent]];
                }
            }
        }

        return $problems;
    }

    /**
     * 沿 broader 往上走得到的所有技術（不含起點，除非繞了一圈回來）。
     *
     * @return array<int, true>
     */
    private function ancestors(int $id): array
    {
        $seen = [];
        $queue = $this->broader[$id] ?? [];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($queue, ...($this->broader[$current] ?? []));
        }

        return $seen;
    }

    /**
     * @param  Collection<array-key, Collection<int, Relation>>  $childrenOf  父述詞 id => 子述詞
     * @return list<int>
     */
    private static function descendantIds(int $rootId, Collection $childrenOf): array
    {
        $ids = [];
        $queue = [$rootId];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            foreach ($childrenOf->get($id, collect()) as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function uniqueIds(array $ids): array
    {
        return array_values(array_unique($ids));
    }
}
