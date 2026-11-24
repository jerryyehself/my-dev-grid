<?php

namespace App\Services;

use App\Logging\AdminAudit;
use App\Models\EntityRelation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 後台新增、刪除技術之間的上下位（broader）與相關（related），存進 `entity_relations`。
 *
 * 只存一個方向，跟 `SemanticRelations` 讀的方式一致：
 * - 上下位存「下位 broader 上位」一列；narrower 是反著讀的名稱，不另外存，所以新增、刪除 broader 時
 *   反方向的 narrower 一起出現、一起消失。萬一有直接寫進資料庫的 narrower 列，刪除時一併刪掉。
 * - 相關一對只存一列，id 小的當主詞（跟 `TechniqueMerger` 改接相關時同一個慣例）。
 *
 * 新增時擋下、整個不寫（422，訊息的 key 是路由上另一端的參數名 `broader` 或 `related`）：
 * - 連到自己（qSKOS `rrc`）。
 * - 述詞 broader／related 不存在、已棄用，或同名的不只一筆：判斷不出要用哪一個，不猜。
 * - 任一端已停用。刪除不擋：停用的技術仍然可以把舊的邊拿掉。
 * - 任一端的分類不在述詞規定的主詞或受詞分類底下（跟 `RelationFitsLinkedScopes` 同一條規則）。
 * - 加上這條之後，`SemanticRelations::problems()` 多出原本沒有的問題：上下位繞成環、標了相關的兩個技術
 *   有上下位路徑（SKOS S27，兄弟之間沒有上下位路徑，可以標相關）、上位重複（已經能經別的上位走到）。
 *   原本就有的問題不算，跟合併的判斷一樣。
 *
 * 判斷要讀整張上下位與相關，所以在 transaction 裡先鎖住三個述詞（`lockPredicates()`）、再鎖兩端的技術，
 * 然後才讀、才寫：同時送出的兩個新增（例如 A broader B 與 B broader A）會排隊，後到的看得到前一個寫進去的邊。
 * 刪除與合併技術（`TechniqueMerger::merge()`）也照同一個順序拿同一組鎖：述詞 → 技術，兩者都依 id 排序。
 * 合併會改接邊、也會讀整張上下位判斷有沒有新問題，所以它跟一次兩端不重疊的新增也要排隊，不然兩邊各自
 * 檢查都通過、合起來卻繞成環；刪除鎖兩端，才不會刪到合併剛改接到別的技術上的那一列。
 * SQLite 沒有列鎖，`lockForUpdate()` 不起作用；SQLite 的寫入本來就一次只有一個。
 *
 * 這個順序不會死結的前提：`entity_relations` 的 `subject_id`／`object_id` 沒有外鍵（只有 `relation_id` 有）。
 * 合併改寫 `subject_id`／`object_id` 時，PostgreSQL 因此不會去對 techniques 拿 FOR KEY SHARE，跟這裡的
 * FOR UPDATE 交叉等待。之後如果替這兩欄加外鍵，要重新檢查合併、新增、刪除拿鎖的順序。
 *
 * 只動名稱正好是 broader、narrower、related 的列。掛在它們底下的子述詞（例如 isVersionOf）由同步管理，
 * 這裡不新增也不刪除；它們推出來的上下位照樣算進環與 S27 的判斷。
 */
final class SemanticTechniqueEdges
{
    /**
     * 新增一條上下位（$subject 掛到 $object 底下）或相關。已經有同一條時什麼都不做。
     *
     * @param  string  $kind  `SemanticRelations::BROADER` 或 `SemanticRelations::RELATED`
     * @return bool 這次有沒有新增
     *
     * @throws ValidationException
     */
    public function add(string $kind, Technique $subject, Technique $object): bool
    {
        return DB::transaction(function () use ($kind, $subject, $object) {
            $predicates = self::lockPredicates();
            [$subject, $object] = $this->lockTechniques($subject, $object);

            $predicate = $this->usablePredicate($kind, $predicates);
            $this->assertEndsAreUsable($kind, $predicate, $subject, $object);

            if ($this->rows($kind, $subject->id, $object->id, $predicates)->isNotEmpty()) {
                return false;
            }

            $before = SemanticRelations::fromDatabase();
            $newProblems = array_diff_key($before->with($kind, $subject->id, $object->id)->problems(), $before->problems());
            if ($newProblems !== []) {
                $messages = array_map(fn (array $problem) => self::problemMessage($problem[0], $kind, $subject, $object), array_values($newProblems));
                throw ValidationException::withMessages([$kind => array_values(array_unique($messages))]);
            }

            [$storedSubject, $storedObject] = $kind === SemanticRelations::RELATED
                ? [min($subject->id, $object->id), max($subject->id, $object->id)]
                : [$subject->id, $object->id];
            $row = EntityRelation::create([
                'entity_type' => 'technique',
                'subject_id' => $storedSubject,
                'object_id' => $storedObject,
                'relation_id' => $predicate->id,
            ]);

            AdminAudit::semanticRelationChanged('attach', $subject, $kind, $object, [(array) DB::table('entity_relations')->find($row->id)]);

            return true;
        });
    }

    /**
     * 刪掉 $subject 與 $object 之間這種直接標的邊（上下位只刪 $subject 在下的那個方向）。沒有可刪的回 404。
     *
     * 跟新增一樣先鎖述詞、再鎖兩端，鎖住之後才找要刪的列：同時有合併把邊改接到別的技術時，等合併 commit 後
     * 再找，不會照改接前讀到的 id 刪掉已經改接的列。兩端在路由綁定之後才被刪除的回 404。
     *
     * @param  string  $kind  `SemanticRelations::BROADER` 或 `SemanticRelations::RELATED`
     */
    public function remove(string $kind, Technique $subject, Technique $object): void
    {
        DB::transaction(function () use ($kind, $subject, $object) {
            $predicates = self::lockPredicates();
            [$subject, $object] = $this->lockTechniques($subject, $object);
            $rows = $this->rows($kind, $subject->id, $object->id, $predicates);
            abort_if($rows->isEmpty(), 404, $kind === SemanticRelations::BROADER
                ? '這兩個技術之間沒有直接標的上下位（由子述詞推出的不在這裡刪）。'
                : '這兩個技術之間沒有直接標的相關（由子述詞推出的不在這裡刪）。');

            DB::table('entity_relations')->whereIn('id', $rows->pluck('id'))->delete();

            AdminAudit::semanticRelationChanged('detach', $subject, $kind, $object, $rows->map(fn (object $row) => (array) $row)->values()->all());
        });
    }

    /**
     * 鎖住三個述詞，同時進來的新增、刪除、合併技術排隊做。同名的有好幾筆時全部鎖住，交給 `usablePredicate()` 判斷。
     *
     * 要在 transaction 裡、鎖任何技術之前呼叫（順序見類別說明）。`TechniqueMerger` 也呼叫這一個，
     * 所以三種寫入拿的是同一組列、同一個順序。
     *
     * @return Collection<array-key, EloquentCollection<int, Relation>> 述詞名稱 => 那個名稱的述詞（未軟刪除）
     */
    public static function lockPredicates(): Collection
    {
        return Relation::whereIn('name', [SemanticRelations::BROADER, SemanticRelations::NARROWER, SemanticRelations::RELATED])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('name')
            ->toBase();
    }

    /**
     * 鎖住兩端（依 id 排序，跟 `TechniqueMerger` 同一個順序，不會互相等成死結），重讀停用狀態。
     * 路由綁定之後才被軟刪除的，這時候已經讀不到，回 404。新增與刪除都用。
     *
     * @return array{0: Technique, 1: Technique}
     */
    private function lockTechniques(Technique $subject, Technique $object): array
    {
        $locked = Technique::whereKey([$subject->id, $object->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $fresh = [$locked->get($subject->id), $locked->get($object->id)];
        abort_if(in_array(null, $fresh, true), 404, '技術已刪除。');

        return $fresh;
    }

    /**
     * @param  Collection<array-key, EloquentCollection<int, Relation>>  $predicates
     *
     * @throws ValidationException
     */
    private function usablePredicate(string $kind, Collection $predicates): Relation
    {
        $candidates = $predicates->get($kind, collect());
        $predicate = $candidates->count() === 1 ? $candidates->first() : null;

        if ($predicate === null || $predicate->isDeprecated()) {
            throw ValidationException::withMessages([$kind => "述詞「{$kind}」不存在、已棄用或不只一筆，無法新增。"]);
        }

        return $predicate;
    }

    /** @throws ValidationException */
    private function assertEndsAreUsable(string $kind, Relation $predicate, Technique $subject, Technique $object): void
    {
        if ($subject->id === $object->id) {
            throw ValidationException::withMessages([$kind => "「{$subject->label}」不能連到自己。"]);
        }

        foreach ([$subject, $object] as $technique) {
            if ($technique->isDeprecated()) {
                throw ValidationException::withMessages([$kind => "技術「{$technique->label}」已停用，不能新增上下位或相關。"]);
            }
        }

        $ancestry = ScopeAncestry::fromDatabase();
        foreach ([[$subject, (int) $predicate->subject_id], [$object, (int) $predicate->object_id]] as [$technique, $required]) {
            if (! $ancestry->matches((int) $technique->type, $required)) {
                throw ValidationException::withMessages([$kind => "「{$technique->label}」的分類不符合述詞「{$predicate->name}」。"]);
            }
        }
    }

    /**
     * $subject 與 $object 之間直接標的這種邊。上下位：$subject broader $object，或反著存的 $object narrower $subject。
     * 相關：兩個方向都算。
     *
     * @param  Collection<array-key, EloquentCollection<int, Relation>>  $predicates
     * @return Collection<int, \stdClass>
     */
    private function rows(string $kind, int $subject, int $object, Collection $predicates): Collection
    {
        $ids = fn (string $name) => $predicates->get($name, collect())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $pair = fn ($query, int $from, int $to, array $relationIds) => $query->orWhere(
            fn ($q) => $q->where(['subject_id' => $from, 'object_id' => $to])->whereIn('relation_id', $relationIds)
        );

        return DB::table('entity_relations')
            ->where('entity_type', 'technique')
            ->where(function ($query) use ($kind, $subject, $object, $ids, $pair) {
                if ($kind === SemanticRelations::BROADER) {
                    $pair($query, $subject, $object, $ids(SemanticRelations::BROADER));
                    $pair($query, $object, $subject, $ids(SemanticRelations::NARROWER));
                } else {
                    $pair($query, $subject, $object, $ids(SemanticRelations::RELATED));
                    $pair($query, $object, $subject, $ids(SemanticRelations::RELATED));
                }
            })
            ->orderBy('id')
            ->get();
    }

    private static function problemMessage(string $rule, string $kind, Technique $subject, Technique $object): string
    {
        $edge = $kind === SemanticRelations::BROADER
            ? "把「{$subject->label}」掛到「{$object->label}」底下"
            : "把「{$subject->label}」與「{$object->label}」標為相關";

        $what = match ($rule) {
            'cycle' => '上下位會繞成環',
            'related_in_hierarchy' => '標了相關的兩個技術會有上下位路徑（相關不能跟上下位重疊，兄弟之間才可以）',
            'redundant_broader' => '會有重複的上位（已經能經別的上位走到）',
            default => '會有自己連自己的上下位或相關',
        };

        return "{$edge}之後{$what}。";
    }
}
