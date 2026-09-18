<?php

namespace App\Service;

use App\Models\Relation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 「使用這個述詞的邊」——把四張連結表正規化成同一種形狀，一次查出來並分頁。
 *
 * 為什麼是 query builder 的 UNION，而不是像 `GraphController` 那樣各自 `->get()`
 * 再在 PHP 裡合併：`GraphController` 是刻意全量回傳整張圖（56 個節點、97 條邊），
 * 合併成本可以忽略。這裡不是——`uses` 一條述詞就有 84 筆邊，而詳情頁要的是
 * **分頁**。在 PHP 裡合併再切頁，等於每次翻頁都把全部邊撈進記憶體，那正是
 * 分頁要避免的事。UNION 之後包一層 `fromSub`，`LIMIT`/`OFFSET` 與 `count(*)`
 * 都落在資料庫裡。
 *
 * 四張表的形狀不一樣，所以要正規化：
 * - 三張 pivot（`documentation_implementation` / `documentation_technique` /
 *   `technique_implementation`）的主受詞型別由**表本身**決定，各出一個分支。
 * - `entity_relations` 的主受詞是**同一族**，由 `entity_type` 欄位決定指向哪張表
 *   （那一欄沒有 DB 層外鍵，完整性在 Model 層檢查），所以三種 entity_type 各出
 *   一個分支，共六個。
 *
 * 實體都有 SoftDeletes，這裡是裸的 query builder、吃不到全域 scope，所以每個分支
 * 都自己 `whereNull('deleted_at')`——否則已刪除的實體會從邊的清單裡冒出來。
 */
class RelationEdgeQuery
{
    /** 一頁最多幾筆。`uses` 有 84 筆，預設 25 夠翻，上限擋掉「一次要一萬筆」。 */
    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 25;

    public function __construct(private readonly Relation $relation) {}

    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage ?: self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));

        return DB::query()
            ->fromSub($this->union(), 'edges')
            // 穩定排序。沒有這個的話，翻頁在不同資料庫／不同執行計畫下順序可能改變，
            // 同一筆邊會在兩頁裡出現或整個漏掉。
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->orderBy('object_type')
            ->orderBy('object_id')
            ->paginate($perPage);
    }

    /**
     * 這條述詞自己的邊有幾筆（不含反向那條的）。
     *
     * 跟 `Relation::isReferenced()` 的差別要講清楚：那個方法**包含反向**的引用，
     * 因為鎖不鎖定是看「這個名字有沒有正在被邊使用」，而邊只存單向。這裡要的是
     * 清單本身的筆數，所以只算自己的。
     */
    public function count(): int
    {
        return DB::query()->fromSub($this->union(), 'edges')->count();
    }

    private function union(): Builder
    {
        $branches = collect([
            $this->pivotBranch(
                'documentation_implementation',
                ['documentation', 'documentations', 'documentation_id'],
                ['implementation', 'implementations', 'implementation_id'],
            ),
            $this->pivotBranch(
                'documentation_technique',
                ['documentation', 'documentations', 'documentation_id'],
                ['technique', 'techniques', 'technique_id'],
            ),
            $this->pivotBranch(
                'technique_implementation',
                ['technique', 'techniques', 'technique_id'],
                ['implementation', 'implementations', 'implementation_id'],
            ),
            $this->entityRelationBranch('documentation', 'documentations'),
            $this->entityRelationBranch('technique', 'techniques'),
            $this->entityRelationBranch('implementation', 'implementations'),
        ]);

        /** @var Builder $query */
        $query = $branches->shift();

        // unionAll 而不是 union：六個分支的來源表互斥，不可能產生重複列，
        // 讓資料庫再做一次 DISTINCT 只是白花錢。
        $branches->each(fn (Builder $branch) => $query->unionAll($branch));

        return $query;
    }

    /**
     * 三張 pivot 表共用的形狀：主受詞型別由表本身決定。
     *
     * @param  array{0:string,1:string,2:string}  $subject  [型別, 資料表, 外鍵欄位]
     * @param  array{0:string,1:string,2:string}  $object  同上
     */
    private function pivotBranch(string $table, array $subject, array $object): Builder
    {
        [$subjectType, $subjectTable, $subjectKey] = $subject;
        [$objectType, $objectTable, $objectKey] = $object;

        return DB::table($table.' as link')
            ->join($subjectTable.' as subject', 'subject.id', '=', 'link.'.$subjectKey)
            ->join($objectTable.' as object', 'object.id', '=', 'link.'.$objectKey)
            ->whereNull('subject.deleted_at')
            ->whereNull('object.deleted_at')
            ->where('link.relation_id', $this->relation->id)
            ->select($this->columns($subjectType, $objectType, $table));
    }

    /**
     * `entity_relations` 的分支：主受詞都在 `$table`，由 `entity_type` 決定是哪一張。
     */
    private function entityRelationBranch(string $type, string $table): Builder
    {
        return DB::table('entity_relations as link')
            ->join($table.' as subject', 'subject.id', '=', 'link.subject_id')
            ->join($table.' as object', 'object.id', '=', 'link.object_id')
            ->whereNull('subject.deleted_at')
            ->whereNull('object.deleted_at')
            ->where('link.entity_type', $type)
            ->where('link.relation_id', $this->relation->id)
            ->select($this->columns($type, $type, 'entity_relations'));
    }

    /**
     * 每個分支都要選一模一樣的欄位、一模一樣的順序——UNION 是按位置對齊的，
     * 順序錯了不會報錯，只會把值放進別的欄位。
     *
     * @return array<int, mixed>
     */
    private function columns(string $subjectType, string $objectType, string $source): array
    {
        return [
            DB::raw($this->quoted($subjectType).' as subject_type'),
            'subject.id as subject_id',
            'subject.title as subject_title',
            DB::raw($this->quoted($objectType).' as object_type'),
            'object.id as object_id',
            'object.title as object_title',
            // 這筆邊是從哪張表來的。除錯時分得出「同一對實體的兩條邊」來自不同的
            // 連結表，前端也可以據此決定要連到哪個畫面。
            DB::raw($this->quoted($source).' as source'),
        ];
    }

    /**
     * 這些值全部是本類別裡寫死的識別字（型別名、表名），不是使用者輸入；
     * 仍然跑一次白名單檢查，免得日後有人把外部字串接進來而沒人察覺。
     */
    private function quoted(string $literal): string
    {
        if (! preg_match('/^[a-z_]+$/', $literal)) {
            throw new \InvalidArgumentException("Refusing to inline a non-identifier literal: {$literal}");
        }

        return "'".$literal."'";
    }
}
