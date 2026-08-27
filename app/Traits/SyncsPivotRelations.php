<?php

namespace App\Traits;

trait SyncsPivotRelations
{
    /**
     * Convert a validated [{id, relation_id}, ...] array into the
     * [id => ['relation_id' => ...]] shape belongsToMany::sync() expects.
     */
    protected function pivotSyncData(array $items): array
    {
        return collect($items)
            ->mapWithKeys(fn (array $item) => [$item['id'] => ['relation_id' => $item['relation_id']]])
            ->all();
    }
}
