<?php

namespace App\Traits;

use App\Logging\AdminAudit;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait SyncsPivotRelations
{
    /**
     * 把已驗證的 [{id, relation_id}, ...] 陣列，轉換成 belongsToMany::sync() 預期的
     * [id => ['relation_id' => ...]] 格式。
     */
    protected function pivotSyncData(array $items): array
    {
        return collect($items)
            ->mapWithKeys(fn (array $item) => [$item['id'] => ['relation_id' => $item['relation_id']]])
            ->all();
    }

    /**
     * sync() 並記下稽核紀錄。pivot 沒有自己的 model，sync() 不會觸發任何 model 事件，
     * AdminAudit 的 observer 看不到，所以在這裡把 sync() 回傳的增減交給它。
     *
     * 技術與實作之間的邊（`technique_implementation`），這次新增或改了述詞的列標成站主指定（`manually_set_at`），
     * 不然從編輯頁改的邊隔天又被 GitHub 同步改回去（見 `SaveReposDataService`）。述詞沒變、只是跟著整份清單
     * 重送的列不標：sync() 對既有的列一律下 UPDATE，從回傳值分不出哪些真的改了，所以先記下原本的述詞來比。
     */
    protected function syncPivot(BelongsToMany $relation, array $items): void
    {
        $data = $this->pivotSyncData($items);
        $before = $this->ownerSetsPredicates($relation) ? $this->currentRelationIds($relation) : null;

        AdminAudit::pivotSynced($relation, $relation->sync($data));

        if ($before === null) {
            return;
        }

        $changed = collect($data)
            ->filter(fn (array $pivot, int|string $relatedId) => ($before[$relatedId] ?? []) !== [(int) $pivot['relation_id']])
            ->keys()
            ->all();

        if ($changed !== []) {
            $relation->newPivotQuery()
                ->whereIn($relation->getQualifiedRelatedPivotKeyName(), $changed)
                ->update(['manually_set_at' => now()]);
        }
    }

    /** 只有技術與實作之間的邊由 GitHub 同步管理，其他連結表沒有 `manually_set_at`。 */
    private function ownerSetsPredicates(BelongsToMany $relation): bool
    {
        return $relation->getTable() === 'technique_implementation';
    }

    /** @return array<int|string, list<int>> 另一端的 id => 這一對目前的述詞 id */
    private function currentRelationIds(BelongsToMany $relation): array
    {
        return $relation->newPivotQuery()
            ->get([$relation->getRelatedPivotKeyName(), 'relation_id'])
            ->groupBy($relation->getRelatedPivotKeyName())
            ->map(fn ($rows) => $rows->pluck('relation_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
            ->all();
    }
}
