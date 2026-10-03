<?php

namespace App\Service;

use App\Models\Documentation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 「使用這個述詞的邊」——把四張連結表正規化成同一種形狀，一次查出來並分頁。
 *
 * 為什麼是 query builder 的 UNION，而不是像 `GraphController` 那樣各自 `->get()`
 * 再在 PHP 裡合併：`GraphController` 是刻意全量回傳整張圖（56 個節點、97 條邊），
 * 合併成本可以忽略。這裡不是——`usedBy` 一條述詞就有 84 筆邊，而詳情頁要的是
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
 * 同一個理由，草稿文章也要自己濾：沒登入的請求看不到草稿，連到草稿的邊也不列
 * （`Documentation::viewerCanSeeDrafts()`，2026-09-30）。
 *
 * `subject_title`／`object_title` 是**顯示用的名稱**：技術帶版本（「Vue 3」），規則跟
 * 圖譜節點的 label 是同一條 `Technique::labelFrom()`（2026-10-03）。只取 title 的話，
 * `Vue 3 isVersionOf Vue` 這條邊會顯示成 `Vue isVersionOf Vue`。
 */
class RelationEdgeQuery
{
    /** 一頁最多幾筆。`usedBy` 有 84 筆，預設 25 夠翻，上限擋掉「一次要一萬筆」。 */
    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 25;

    private readonly bool $includeDrafts;

    public function __construct(private readonly Relation $relation)
    {
        $this->includeDrafts = Documentation::viewerCanSeeDrafts();
    }

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
            ->paginate($perPage)
            ->through(fn (object $edge) => $this->withDisplayTitles($edge));
    }

    /**
     * 把 title 換成顯示用的名稱，再拿掉只為了算名稱才多選的 version 欄位。
     *
     * 為什麼在 PHP 裡拼、不在 SQL 裡 `title || ' ' || version`：規則（version 留空就只有
     * title）已經寫在 `Technique::labelFrom()`，SQL 再寫一份就是兩份會各自漂移的規則；
     * 而且這裡只處理分頁後的那一頁（最多 MAX_PER_PAGE 筆），不是全部的邊。
     * version 欄位不留在回應裡：前端拿到的已經是組好的名稱，多給一份原料只會讓呼叫端
     * 有機會再拼一次，變成「Vue 3 3」。
     */
    private function withDisplayTitles(object $edge): object
    {
        $edge->subject_title = Technique::labelFrom($edge->subject_title, $edge->subject_version);
        $edge->object_title = Technique::labelFrom($edge->object_title, $edge->object_version);
        unset($edge->subject_version, $edge->object_version);

        return $edge;
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

        $query = DB::table($table.' as link')
            ->join($subjectTable.' as subject', 'subject.id', '=', 'link.'.$subjectKey)
            ->join($objectTable.' as object', 'object.id', '=', 'link.'.$objectKey)
            ->whereNull('subject.deleted_at')
            ->whereNull('object.deleted_at')
            ->where('link.relation_id', $this->relation->id)
            ->select($this->columns($subjectType, $objectType, $table));

        return $this->hideDrafts($query, ['subject' => $subjectType, 'object' => $objectType]);
    }

    /**
     * `entity_relations` 的分支：主受詞都在 `$table`，由 `entity_type` 決定是哪一張。
     */
    private function entityRelationBranch(string $type, string $table): Builder
    {
        $query = DB::table('entity_relations as link')
            ->join($table.' as subject', 'subject.id', '=', 'link.subject_id')
            ->join($table.' as object', 'object.id', '=', 'link.object_id')
            ->whereNull('subject.deleted_at')
            ->whereNull('object.deleted_at')
            ->where('link.entity_type', $type)
            ->where('link.relation_id', $this->relation->id)
            ->select($this->columns($type, $type, 'entity_relations'));

        return $this->hideDrafts($query, ['subject' => $type, 'object' => $type]);
    }

    /**
     * 主詞或受詞是文章、而這次請求看不到草稿時，只留已發布的那一端。
     *
     * @param  array{subject:string, object:string}  $types  別名 => 實體型別
     */
    private function hideDrafts(Builder $query, array $types): Builder
    {
        if ($this->includeDrafts) {
            return $query;
        }

        foreach ($types as $alias => $type) {
            if ($type === 'documentation') {
                $query->where($alias.'.status', Documentation::STATUS_PUBLISHED);
            }
        }

        return $query;
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
            $this->versionColumn($subjectType, 'subject'),
            DB::raw($this->quoted($objectType).' as object_type'),
            'object.id as object_id',
            'object.title as object_title',
            $this->versionColumn($objectType, 'object'),
            // 這筆邊是從哪張表來的。除錯時分得出「同一對實體的兩條邊」來自不同的
            // 連結表，前端也可以據此決定要連到哪個畫面。
            DB::raw($this->quoted($source).' as source'),
        ];
    }

    /**
     * 只有 techniques 有 version 欄位；documentations／implementations 沒有，那一格補 NULL，
     * 讓六個分支的欄位數與位置還是一樣（見上面 UNION 按位置對齊的提醒）。
     *
     * NULL 明確轉成字串型別：第一個分支（documentation_implementation）兩端都不是技術，
     * 裸的 NULL 在 PostgreSQL 是 unknown 型別，交給 UNION 去推導雖然目前推得出來，
     * 但寫明型別比依賴推導規則可靠。
     */
    private function versionColumn(string $type, string $alias): mixed
    {
        return $type === 'technique'
            ? $alias.'.version as '.$alias.'_version'
            : DB::raw('CAST(NULL AS VARCHAR(255)) as '.$alias.'_version');
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
