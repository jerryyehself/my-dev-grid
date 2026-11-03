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
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class GraphController extends Controller
{
    /** `nodeId()` 產生的格式：型別、連字號、不帶前導零的正整數 id。要 public：Scramble 產生文件時要讀得到驗證規則。 */
    public const NODE_ID_PATTERN = '/^(documentation|technique|implementation)-[1-9][0-9]*$/';

    /** 沒登入時，未公開文件節點的 `label`。不回標題，只讓人知道這裡有一篇還沒公開的文件。 */
    public const UNPUBLISHED_LABEL = '未公開';

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
     * {id, type, label, created_at, subtype, url, active}, edges as {source, target, predicate,
     * label, relation_id} where source/target reference node ids.
     * created_at is only populated for implementation nodes (from
     * git_repo_created_at) — documentation/technique have no equivalent
     * field and always report null.
     */
    // 回應是手動組的陣列，Scramble 推不出節點的欄位，在這裡寫明給 OpenAPI 文件用。
    #[Endpoint(
        title: '取得知識圖譜',
        description: '回傳文件、技術、實作三種節點，以及它們之間的邊（含述詞名稱），可直接用在力導向圖。`created_at` 只有實作節點有值。未公開的文件也會列出，`active` 為 false，連到它的邊照常回傳；未登入時這類節點的 `label` 固定是「未公開」，`subtype` 與 `url` 為 null。已刪除的文件不會出現，連到它的邊也不會。未登入時不含設為不公開的實作，連到它們的邊也不會出現。',
    )]
    #[Response(200, type: 'array{nodes: list<array{id: string, type: "documentation"|"technique"|"implementation", label: string, created_at: string|null, subtype: string|null, url: string|null, active: bool}>, edges: list<array{source: string, target: string, predicate: string|null, label: string|null, relation_id: int|null}>}')]
    public function index()
    {
        $nodes = collect()
            // 未公開的文件也列出來（`active` 為 false），沒登入時由 nodesFor() 遮掉標題與連結
            ->concat($this->nodesFor(Documentation::with('scope')->get(), 'documentation'))
            ->concat($this->nodesFor(Technique::all(), 'technique'))
            ->concat($this->nodesFor(Implementation::visibleToViewer()->get(), 'implementation'))
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
    #[Endpoint(
        title: '查詢最短路徑',
        description: '以 `start`、`end`（節點 id，格式同 `GET /graph` 的 `id`，例如 `technique-12`；格式不符時回 422）查詢兩個節點之間的最短路徑，只回傳一條。邊可以逆向走，每一步回傳實際走的方向所對應的述詞名稱。路徑可以經過未公開的文件，未登入時它的 `label` 同樣是「未公開」。找不到路徑，或未登入時起訖點是不公開的實作時，`found` 為 false，`nodes` 與 `edges` 為空陣列。',
    )]
    #[Response(200, type: 'array{found: bool, nodes: list<array{id: string, type: "documentation"|"technique"|"implementation", label: string|null}>, edges: list<array{source: string, target: string, predicate: string|null, label: string|null, relation_id: int|null, storedDirection: "forward"|"reverse", hasDefinedReverse: bool}>}')]
    public function path(Request $request)
    {
        // 只收 index() 產生的那種 node id
        $validated = $request->validate([
            'start' => ['required', 'string', 'regex:'.self::NODE_ID_PATTERN],
            'end' => ['required', 'string', 'regex:'.self::NODE_ID_PATTERN],
        ]);
        $start = $validated['start'];
        $end = $validated['end'];

        // 起訖點本身是不公開的專案：當成找不到。未公開的文件不擋，跟一般節點一樣，標題由 labelFor() 遮掉
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
        if ($model instanceof Documentation && ! $this->documentationIsOpenToViewer($model)) {
            return self::UNPUBLISHED_LABEL;
        }

        return $model instanceof Technique ? $model->label : $model->title;
    }

    /**
     * 這次請求能不能看這篇文件的標題與連結：已發布的誰都能看，未公開的要登入。
     * 判斷跟 `Documentation::scopeVisibleToViewer()` 一致，`status` 為 NULL 也算未公開。
     */
    private function documentationIsOpenToViewer(Documentation $documentation): bool
    {
        return $documentation->isPublished() || Documentation::viewerCanSeeDrafts();
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
            // 有網址的連外部網址。其他型別沒有這兩個概念，一律 null。沒登入時，未公開的文件
            // 兩個也都回 null：不給可以點進去的連結（文章頁對訪客本來就會回 404）
            'subtype' => $type === 'documentation' && $this->documentationIsOpenToViewer($model) ? $model->scope?->name : null,
            'url' => $type === 'documentation' && $this->documentationIsOpenToViewer($model) ? $model->url : null,
            // 未公開的文件是 false，其他節點一律 true。登入與否不影響這個值
            'active' => $type !== 'documentation' || $model->isPublished(),
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
        $excluded = $this->hiddenNodeIds() + $this->deletedDocumentationNodeIds();

        return $this->allRawEdges()
            ->reject(fn (array $e) => isset($excluded[$e['source']]) || isset($excluded[$e['target']]))
            ->values();
    }

    /**
     * 這次請求看不到的節點（沒登入時不公開的專案），以 node id 為 key。
     * 這些節點不出現、連到它們的邊也不出現，路徑查詢也不能經過它們。
     *
     * 未公開的文件不在這裡：它的節點和邊照常回傳，只是沒登入時遮掉標題與連結（`labelFor()`、`nodesFor()`）。
     *
     * @return array<string, true>
     */
    private function hiddenNodeIds(): array
    {
        if (Implementation::viewerCanSeeHidden()) {
            return [];
        }

        return Implementation::hidden()
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$this->nodeId('implementation', $id) => true])
            ->all();
    }

    /**
     * 已軟刪除的文件，以 node id 為 key，不管這次請求有沒有登入。
     *
     * 節點清單的查詢受 SoftDeletes 影響，本來就沒有它們；但軟刪除不會動到連結表，
     * 邊是直接讀連結表，不另外排除就會留下指向不存在節點的邊，路徑查詢也會經過它們。
     *
     * @return array<string, true>
     */
    private function deletedDocumentationNodeIds(): array
    {
        return Documentation::onlyTrashed()
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

        // 跟 index() 一樣套用可見性條件：未公開的文件照樣載入，標題由 labelFor() 遮掉
        $models = [
            'documentation' => Documentation::whereIn('id', $idsByType['documentation'])->get()->keyBy('id'),
            'technique' => Technique::whereIn('id', $idsByType['technique'])->get()->keyBy('id'),
            'implementation' => Implementation::visibleToViewer()->whereIn('id', $idsByType['implementation'])->get()->keyBy('id'),
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
