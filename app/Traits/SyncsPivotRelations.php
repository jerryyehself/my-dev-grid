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
     */
    protected function syncPivot(BelongsToMany $relation, array $items): void
    {
        AdminAudit::pivotSynced($relation, $relation->sync($this->pivotSyncData($items)));
    }
}
