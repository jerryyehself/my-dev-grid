<?php

namespace App\Services;

use App\Logging\AdminAudit;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 站主在後台指定一對專案與技術的述詞，或把它交還給 GitHub 同步。
 *
 * 同步依證據決定述詞（`usedBy` 或 `subjectOfProject`，見 `SaveReposDataService`）。站主改過的邊記下
 * `technique_implementation.manually_set_at`，同步就不再改這一對的述詞、也不在旁邊另長一列。
 * 交還之後清掉這一欄，下一次同步照證據處理（只升不降）。
 */
final class ManualTechniqueEdges
{
    /**
     * 把這一對的邊改成 $relationId，並標成站主指定。
     *
     * 改哪一列，依序找：站主指定過的列、由同步管理的列（述詞沿改用解析後是 `usedBy` 或 `subjectOfProject`）、
     * 這一對唯一的一列。站主指定過的優先，因為站主可能已經把同步的邊改成別的述詞（例如 `assists`），
     * 它就不再是「同步管理的述詞」，但仍是同一條邊。同一層有兩列以上時分不出要改哪一條，回 409，
     * 請站主在編輯頁處理。
     *
     * @throws ValidationException 同一對已經有另一列是這個述詞（唯一索引不允許）
     */
    public function set(Implementation $implementation, Technique $technique, int $relationId): void
    {
        $rows = $this->rows($implementation, $technique);
        abort_if($rows->isEmpty(), 404, '這個專案與技術之間沒有邊。');

        $target = $this->target($rows);

        if ($rows->contains(fn (object $row) => $row->id !== $target->id && (int) $row->relation_id === $relationId)) {
            throw ValidationException::withMessages([
                'relation_id' => '這個專案與技術之間已經有另一條邊用這個關係。',
            ]);
        }

        $now = now();
        DB::table('technique_implementation')->where('id', $target->id)->update([
            'relation_id' => $relationId,
            'manually_set_at' => $now,
            'updated_at' => $now,
        ]);

        AdminAudit::pivotSynced($implementation->techniques(), ['updated' => [$technique->id]]);
    }

    /**
     * 清掉這一對的站主指定標記，交還給同步。這一對沒有任何邊回 404；本來就沒有標記的話什麼都不做。
     */
    public function release(Implementation $implementation, Technique $technique): void
    {
        $rows = $this->rows($implementation, $technique);
        abort_if($rows->isEmpty(), 404, '這個專案與技術之間沒有邊。');

        $flagged = $rows->whereNotNull('manually_set_at')->pluck('id');
        if ($flagged->isEmpty()) {
            return;
        }

        DB::table('technique_implementation')->whereIn('id', $flagged)->update([
            'manually_set_at' => null,
            'updated_at' => now(),
        ]);

        AdminAudit::pivotSynced($implementation->techniques(), ['updated' => [$technique->id]]);
    }

    /** @return Collection<int, object> */
    private function rows(Implementation $implementation, Technique $technique): Collection
    {
        return DB::table('technique_implementation')
            ->where(['implementation_id' => $implementation->id, 'technique_id' => $technique->id])
            ->orderBy('id')
            ->get(['id', 'relation_id', 'manually_set_at'])
            ->each(function (object $row) {
                $row->id = (int) $row->id;
            });
    }

    /** @param  Collection<int, object>  $rows */
    private function target(Collection $rows): object
    {
        $synced = [
            ...$this->idsResolvingToName('usedBy'),
            ...$this->idsResolvingToName('subjectOfProject'),
        ];

        $tiers = [
            $rows->whereNotNull('manually_set_at'),
            $rows->filter(fn (object $row) => in_array((int) $row->relation_id, $synced, true)),
            $rows,
        ];

        foreach ($tiers as $candidates) {
            if ($candidates->count() === 1) {
                return $candidates->first();
            }
            abort_if($candidates->count() > 1, 409, '這個專案與技術之間有多條邊，分不出要改哪一條，請在編輯頁處理。');
        }

        abort(404, '這個專案與技術之間沒有邊。');
    }

    /** @return list<int> */
    private function idsResolvingToName(string $name): array
    {
        $relation = Relation::resolveByName($name);

        return $relation === null ? [] : Relation::idsResolvingTo($relation->id);
    }
}
