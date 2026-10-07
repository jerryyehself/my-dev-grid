<?php

namespace App\Http\Controllers;

use App\Models\Documentation;
use App\Models\DocumentationImplementationLink;
use App\Models\DocumentationTechniqueLink;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\TechniqueImplementationLink;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class GraphController extends Controller
{
    /**
     * Build a nodes/edges graph across Documentation, Technique and
     * Implementation.
     *
     * Nodes are every row of the three domain entities. Edges come from
     * the three typed pivot tables (documentation<->technique,
     * documentation<->implementation, technique<->implementation) plus the
     * generic entity_relations table (same-type relations, e.g.
     * technique<->technique). Each edge's relation_id is resolved to the
     * Relation's predicate name so the frontend doesn't need a second
     * round trip.
     *
     * Shaped for a force-directed graph frontend: nodes as
     * {id, type, label, created_at}, edges as {source, target, predicate,
     * label, relation_id} where source/target reference node ids.
     * created_at is only populated for implementation nodes (from
     * git_repo_created_at) — documentation/technique have no equivalent
     * field and always report null.
     */
    // 回應是手動組的陣列，Scramble 推不出節點的欄位，在這裡寫明給 OpenAPI 文件用。
    #[Response(200, type: 'array{nodes: list<array{id: string, type: "documentation"|"technique"|"implementation", label: string, created_at: string|null, subtype: string|null, url: string|null}>, edges: list<array{source: string, target: string, predicate: string|null, label: string|null, relation_id: int|null}>}')]
    public function index()
    {
        $nodes = collect()
            ->concat($this->nodesFor(Documentation::visibleToViewer()->with('scope')->get(), 'documentation'))
            ->concat($this->nodesFor(Technique::all(), 'technique'))
            ->concat($this->nodesFor(Implementation::all(), 'implementation'))
            ->values();

        $edges = $this->rawEdges()
            ->map(fn (array $e) => $this->edge($e['source'], $e['target'], $e['relation']))
            ->values();

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
        ]);
    }

    /**
     * Shortest path between two graph nodes (BFS, edges treated as
     * undirected for reachability — "how are these two things related"
     * shouldn't fail just because the real relation happens to point the
     * other way). Each hop reports the predicate for the direction actually
     * travelled: travelling with a relation's stored subject->object
     * direction uses its own name; travelling against it looks up that
     * relation's `reverse_id` and uses the reverse relation's name instead
     * (e.g. walking a `specs` edge backward reports `specifiedBy`, not
     * `specs`). `hasDefinedReverse` is false only if a relation somehow has
     * no `reverse_id` set — in the current seed data every relation has one,
     * so this is a defensive fallback, not the expected case.
     *
     * No k-shortest-paths / multiple alternate routes yet — this returns
     * the single shortest path only. Deliberately scoped down: a small
     * graph like this doesn't need more for a first version, and adding
     * real alternate-path search (e.g. Yen's algorithm) can come later if
     * it turns out to matter.
     */
    // 同 index()：手動組的回應，在這裡寫明形狀。找不到路徑時 found 是 false、nodes／edges 是空陣列。
    #[Response(200, type: 'array{found: bool, nodes: list<array{id: string, type: "documentation"|"technique"|"implementation", label: string|null}>, edges: list<array{source: string, target: string, predicate: string|null, label: string|null, relation_id: int|null, storedDirection: "forward"|"reverse", hasDefinedReverse: bool}>}')]
    public function path(Request $request)
    {
        $validated = $request->validate([
            'start' => ['required', 'string'],
            'end' => ['required', 'string'],
        ]);
        $start = $validated['start'];
        $end = $validated['end'];

        // 起訖點本身是草稿：當成找不到。不擋的話，下面的 nodesByIds() 會把草稿標題回傳出去
        $hidden = $this->hiddenNodeIds();
        if (isset($hidden[$start]) || isset($hidden[$end])) {
            return response()->json(['found' => false, 'nodes' => [], 'edges' => []]);
        }

        $adjacency = $this->buildUndirectedAdjacency($this->rawEdges());

        $hops = $this->shortestPathHops($adjacency, $start, $end);

        if ($hops === null) {
            return response()->json(['found' => false, 'nodes' => [], 'edges' => []]);
        }

        $nodeIds = [$start, ...array_map(fn (array $hop) => $hop['to'], $hops)];
        $relationsById = Relation::all()->keyBy('id');

        return response()->json([
            'found' => true,
            'nodes' => $this->nodesByIds($nodeIds),
            'edges' => collect($hops)->map(fn (array $hop) => $this->pathHopEdge($hop, $relationsById))->values(),
        ]);
    }

    private function nodeId(string $type, int $id): string
    {
        return "{$type}-{$id}";
    }

    /**
     * 技術的版本是獨立的一筆（title 相同、version 填主版號，2026-09-30），標籤要帶版本，
     * 不然圖上會出現兩個一模一樣的「Vue」。規則在 `Technique::label`，這裡不自己拼字串。
     */
    private function labelFor($model): string
    {
        return $model instanceof Technique ? $model->label : $model->title;
    }

    private function nodesFor(Collection $models, string $type): Collection
    {
        return $models->map(fn ($model) => [
            'id' => $this->nodeId($type, $model->id),
            'type' => $type,
            'label' => $this->labelFor($model),
            // 只有 Implementation 有這個欄位（git_repo_created_at，來自 GitHub API），
            // Documentation/Technique 完全沒有對應的時間概念，一律回傳 null——不是
            // 每個節點都有意義的「熱度」資料，前端要誠實處理這個缺口，不是掰一個假時間。
            'created_at' => $type === 'implementation' && $model->git_repo_created_at
                ? $model->git_repo_created_at->format('Y-m-d')
                : null,
            // 文章節點的子類（scope 名稱，例如 post 是自己寫的文章、sourcesite 是官方文件
            // 這類參考資料）與外部網址。前端的節點彈窗靠這兩個決定連去哪：post 連文章頁、
            // 有網址的連外部網址。其他型別沒有這兩個概念，一律 null
            'subtype' => $type === 'documentation' ? $model->scope?->name : null,
            'url' => $type === 'documentation' ? $model->url : null,
        ]);
    }

    /**
     * All raw edges with the Relation model still attached (not yet
     * flattened to the public {source,target,predicate,...} shape) — index()
     * flattens these for the response, path() needs the actual Relation to
     * resolve reverse_id when a hop travels backward.
     */
    private function rawEdges(): Collection
    {
        $hidden = $this->hiddenNodeIds();

        return $this->allRawEdges()
            ->reject(fn (array $e) => isset($hidden[$e['source']]) || isset($hidden[$e['target']]))
            ->values();
    }

    /**
     * 這次請求看不到的節點（沒登入時的草稿文章），以 node id 為 key。
     * 草稿節點不出現、連到它的邊也不出現，路徑查詢也不能經過它。
     *
     * @return array<string, true>
     */
    private function hiddenNodeIds(): array
    {
        if (Documentation::viewerCanSeeDrafts()) {
            return [];
        }

        return Documentation::where(fn ($query) => $query
            ->where('status', '!=', Documentation::STATUS_PUBLISHED)
            ->orWhereNull('status'))
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$this->nodeId('documentation', $id) => true])
            ->all();
    }

    private function allRawEdges(): Collection
    {
        return collect()
            ->concat($this->pivotRawEdges(
                DocumentationTechniqueLink::with('relation')->get(),
                'documentation_id',
                'documentation',
                'technique_id',
                'technique',
            ))
            ->concat($this->pivotRawEdges(
                DocumentationImplementationLink::with('relation')->get(),
                'documentation_id',
                'documentation',
                'implementation_id',
                'implementation',
            ))
            ->concat($this->pivotRawEdges(
                TechniqueImplementationLink::with('relation')->get(),
                'technique_id',
                'technique',
                'implementation_id',
                'implementation',
            ))
            ->concat($this->entityRelationRawEdges())
            ->values();
    }

    /**
     * Turn a collection of pivot link rows (each with a `relation_id`
     * foreign key and a `relation` relationship already loaded) into raw
     * graph edges. $sourceColumn/$targetColumn are the pivot's own FK
     * column names; $sourceType/$targetType are the node type they
     * resolve to.
     */
    private function pivotRawEdges(
        Collection $links,
        string $sourceColumn,
        string $sourceType,
        string $targetColumn,
        string $targetType,
    ): Collection {
        return $links->map(fn ($link) => [
            'source' => $this->nodeId($sourceType, $link->$sourceColumn),
            'target' => $this->nodeId($targetType, $link->$targetColumn),
            'relation' => $link->relation,
        ]);
    }

    private function entityRelationRawEdges(): Collection
    {
        return EntityRelation::with('relation')->get()->map(fn (EntityRelation $entityRelation) => [
            'source' => $this->nodeId($entityRelation->entity_type, $entityRelation->subject_id),
            'target' => $this->nodeId($entityRelation->entity_type, $entityRelation->object_id),
            'relation' => $entityRelation->relation,
        ]);
    }

    private function edge(string $source, string $target, $relation): array
    {
        return [
            'source' => $source,
            'target' => $target,
            'predicate' => optional($relation)->name,
            'label' => optional($relation)->name,
            'relation_id' => optional($relation)->id,
        ];
    }

    /**
     * node id -> list of {to, relation, direction}. Each raw edge produces
     * two adjacency entries (source->target and target->source) so BFS can
     * walk it either way; `direction` records whether that particular
     * traversal matches the relation's own stored subject->object
     * direction ('forward') or goes against it ('reverse') — path() uses
     * this to pick the right predicate name per hop.
     */
    private function buildUndirectedAdjacency(Collection $edges): array
    {
        $adjacency = [];
        foreach ($edges as $e) {
            $adjacency[$e['source']][] = ['to' => $e['target'], 'relation' => $e['relation'], 'direction' => 'forward'];
            $adjacency[$e['target']][] = ['to' => $e['source'], 'relation' => $e['relation'], 'direction' => 'reverse'];
        }

        return $adjacency;
    }

    /**
     * Breadth-first search over the undirected adjacency list. Returns the
     * ordered list of hops taken (each {from, to, relation, direction}) —
     * empty array when start === end (trivial, zero hops) — or null when
     * genuinely unreachable, including when $start or $end isn't in the
     * adjacency at all (a made-up id, or a real node with zero edges).
     */
    private function shortestPathHops(array $adjacency, string $start, string $end): ?array
    {
        if ($start === $end) {
            return [];
        }

        $visited = [$start => true];
        $cameFrom = [];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $link) {
                if (isset($visited[$link['to']])) {
                    continue;
                }
                $visited[$link['to']] = true;
                $cameFrom[$link['to']] = ['from' => $current, 'link' => $link];
                if ($link['to'] === $end) {
                    return $this->reconstructHops($cameFrom, $start, $end);
                }
                $queue[] = $link['to'];
            }
        }

        return null;
    }

    private function reconstructHops(array $cameFrom, string $start, string $end): array
    {
        $hops = [];
        $cursor = $end;
        while ($cursor !== $start) {
            $step = $cameFrom[$cursor];
            $hops[] = [
                'from' => $step['from'],
                'to' => $cursor,
                'relation' => $step['link']['relation'],
                'direction' => $step['link']['direction'],
            ];
            $cursor = $step['from'];
        }

        return array_reverse($hops);
    }

    /**
     * @param  Collection<int, Relation>  $relationsById
     */
    private function pathHopEdge(array $hop, Collection $relationsById): array
    {
        $relation = $hop['relation'];

        if ($hop['direction'] === 'forward' || ! $relation) {
            return [
                'source' => $hop['from'],
                'target' => $hop['to'],
                'predicate' => optional($relation)->name,
                'label' => optional($relation)->name,
                'relation_id' => optional($relation)->id,
                'storedDirection' => $hop['direction'],
                'hasDefinedReverse' => true,
            ];
        }

        // 逆向走：預設 relation 名稱本身用不上（那是正向的語意），要找它的
        // reverse_id 對應的真正反向關係名稱。目前種子資料裡每個 relation 都有
        // 定義 reverse_id，所以 $reverseRelation 理論上不會是 null——留著這個
        // 分支是防呆，不是預期會真的走到。
        $reverseRelation = $relation->reverse_id ? $relationsById->get($relation->reverse_id) : null;

        return [
            'source' => $hop['from'],
            'target' => $hop['to'],
            'predicate' => $reverseRelation?->name ?? $relation->name,
            'label' => $reverseRelation?->name ?? $relation->name,
            'relation_id' => $reverseRelation?->id ?? $relation->id,
            'storedDirection' => 'reverse',
            'hasDefinedReverse' => $reverseRelation !== null,
        ];
    }

    private function nodesByIds(array $nodeIds): array
    {
        $idsByType = ['documentation' => [], 'technique' => [], 'implementation' => []];
        foreach ($nodeIds as $nodeId) {
            [$type, $rawId] = explode('-', $nodeId, 2);
            $idsByType[$type][] = (int) $rawId;
        }

        $models = [
            'documentation' => Documentation::whereIn('id', $idsByType['documentation'])->get()->keyBy('id'),
            'technique' => Technique::whereIn('id', $idsByType['technique'])->get()->keyBy('id'),
            'implementation' => Implementation::whereIn('id', $idsByType['implementation'])->get()->keyBy('id'),
        ];

        return collect($nodeIds)->map(function (string $nodeId) use ($models) {
            [$type, $rawId] = explode('-', $nodeId, 2);
            $model = $models[$type]->get((int) $rawId);

            return [
                'id' => $nodeId,
                'type' => $type,
                'label' => $model ? $this->labelFor($model) : null,
            ];
        })->values()->all();
    }
}
