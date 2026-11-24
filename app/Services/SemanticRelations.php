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
