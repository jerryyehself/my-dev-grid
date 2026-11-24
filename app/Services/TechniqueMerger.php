<?php

namespace App\Services;

use App\Enums\ResLabelType;
use App\Logging\AdminAudit;
use App\Models\Relation;
use App\Models\ResLabel;
use App\Models\Technique;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 把一筆技術合併進另一筆：舊的那筆（$from）停用、改用留下來的那筆（$into），連結搬過去。
 *
 * 全部在一個 transaction 裡做，任何一步不行就整個回滾，什麼都不改：
 *
 * 1. $from 標為停用，改用 $into（`Technique::deprecate()`）。
 * 2. $from 的正式名（含版本）寫成 $into 的隱藏別名（hidden），同步與名稱比對之後經別名接到 $into。
 *    跟 $into 的正式名或既有別名正規化後相同的就不寫（同一個 res 的同一個字串只能有一種標籤）。
 * 3. $from 在 `technique_implementation`、`documentation_technique`、`entity_relations` 的連結改接到 $into：
 *    - 改接後跟 $into 既有的連結完全相同（同一個另一端、同一個述詞），刪掉 $from 那一列，留下 $into 那一列，
 *      但保留兩列裡較早的 `created_at`（照 `2026_09_30_190200_merge_duplicate_techniques.php` 的前例）：
 *      那是這條邊真正加入的時間，公開成 `/api/graph` 邊的 `added_at`。`created_at` 是 null 代表開始記錄之前
 *      就存在，算最早。三張表都照這條規則。
 *    - $from 那列是後台指定過的（`manually_set_at`）、$into 那列不是時，把指定時間帶到 $into 那列，同步才不會
 *      改它的述詞。
 *    - `entity_relations` 改接後變成自己連自己（例如 $from isVersionOf $into），也刪掉。相關（related 一族）
 *      照只存一個方向的慣例，改接後讓 id 小的當主詞，所以改接的列另外記下原本的兩端。
 * 4. 刪掉的列記整列的值，改寫過的列記改寫前的值（`updated`），都進稽核紀錄（`AdminAudit::techniqueMerged()`），
 *    要還原時查得到。
 *
 * 改用對象的合格檢查（`TechniqueReplacementIsUsable`）在 transaction 外面做，所以進 transaction 後先鎖住兩筆技術
 * 再確認一次 $into 還在、還沒停用：同時送出 A→B 與 B→C 時，後到的那個會等前一個 commit，看到 B 已停用就擋下。
 *
 * 擋下、整個不做（422，訊息在 `replaced_by_id`）的情況：
 * - 同一個另一端，$from 與 $into 用的述詞不同。後台編輯器一次以技術 id 為鍵存整份清單，同一對有兩列會撞唯一索引，
 *   這種要先在後台改成同一個述詞或刪掉一列。
 * - 改接後 $into 的分類不在述詞規定的主詞或受詞分類底下（跟 `RelationFitsLinkedScopes` 同一條規則）。
 * - 改接後上下位與相關違反詞表規則（環、相關又有上下位路徑、上位重複，見 `SemanticRelations::problems()`）。
 *   合併前就已經有的問題不算。
 *
 * 取消停用（`Technique::undeprecate()`）不會把連結搬回來，要還原請照稽核紀錄。
 */
final class TechniqueMerger
{
    /** 有 technique_id 的連結表 => [另一端的欄位, 技術在述詞的哪一端]。 */
    private const PIVOTS = [
        'technique_implementation' => ['implementation_id', 'subject_id'],
        'documentation_technique' => ['documentation_id', 'object_id'],
    ];

    private ?ScopeAncestry $ancestry = null;

    /** @var array<int, Relation|null> */
    private array $relations = [];

    /** @var list<string> */
    private array $conflicts = [];

    /**
     * @return array{moved: array<string, list<int>>, removed: array<string, list<array<string, mixed>>>, updated: array<string, list<array<string, mixed>>>, label_id: int|null}
     *
     * @throws ValidationException
     */
    public function merge(Technique $from, Technique $into): array
    {
        $this->conflicts = [];

        return DB::transaction(function () use ($from, $into) {
            $this->lockAndRecheck($from, $into);

            $before = SemanticRelations::fromDatabase();
            $newProblems = array_diff_key($before->merged($from->id, $into->id)->problems(), $before->problems());
            foreach ($newProblems as [$rule]) {
                $this->conflicts[] = self::problemMessage($rule, $from, $into);
            }

            $summary = ['moved' => [], 'removed' => [], 'updated' => [], 'label_id' => null];
            foreach (self::PIVOTS as $table => [$otherColumn, $techniqueEnd]) {
                $this->movePivotRows($table, $otherColumn, $techniqueEnd, $from, $into, $summary);
            }
            $this->moveEntityRelations($from, $into, $summary);

            if ($this->conflicts !== []) {
                throw ValidationException::withMessages(['replaced_by_id' => array_values(array_unique($this->conflicts))]);
            }

            $from->deprecate($into);
            $summary['label_id'] = $this->addOldNameAsLabel($from, $into);
            AdminAudit::techniqueMerged($from, $into, $summary);

            return $summary;
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function movePivotRows(string $table, string $otherColumn, string $techniqueEnd, Technique $from, Technique $into, array &$summary): void
    {
        foreach (DB::table($table)->where('technique_id', $from->id)->orderBy('id')->get() as $row) {
            $twins = DB::table($table)->where('technique_id', $into->id)->where($otherColumn, $row->{$otherColumn})->get();
            $same = $twins->first(fn (object $twin) => (int) $twin->relation_id === (int) $row->relation_id);

            if ($same !== null) {
                DB::table($table)->where('id', $row->id)->delete();
                $summary['removed'][$table][] = (array) $row;
                $carry = self::earlierCreatedAt($row, $same);
                if (($row->manually_set_at ?? null) !== null && ($same->manually_set_at ?? null) === null) {
                    $carry['manually_set_at'] = $row->manually_set_at;
                }
                $this->updateKeptRow($table, $same, $carry, $summary);

                continue;
            }

            if ($twins->isNotEmpty()) {
                $this->conflicts[] = "「{$into->label}」跟「{$from->label}」連到同一個{$this->noun($table)}（id {$row->{$otherColumn}}）用的述詞不同，請先在後台改成同一個述詞或刪掉其中一條。";

                continue;
            }

            if (! $this->fitsScope((int) $row->relation_id, $techniqueEnd, $from, $into)) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update(['technique_id' => $into->id, 'updated_at' => now()]);
            $summary['moved'][$table][] = (int) $row->id;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function moveEntityRelations(Technique $from, Technique $into, array &$summary): void
    {
        $related = SemanticRelations::predicateFamilies()[SemanticRelations::RELATED];
        $rows = DB::table('entity_relations')->where('entity_type', 'technique')
            ->where(fn ($query) => $query->where('subject_id', $from->id)->orWhere('object_id', $from->id))
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $subject = (int) $row->subject_id === $from->id ? $into->id : (int) $row->subject_id;
            $object = (int) $row->object_id === $from->id ? $into->id : (int) $row->object_id;
            $isRelated = in_array((int) $row->relation_id, $related, true);
            if ($isRelated && $subject > $object) {
                [$subject, $object] = [$object, $subject];
            }

            $duplicate = DB::table('entity_relations')->where('id', '!=', $row->id)
                ->where(['entity_type' => 'technique', 'relation_id' => $row->relation_id])
                ->where(fn ($query) => $query->where(['subject_id' => $subject, 'object_id' => $object])
                    ->when($isRelated, fn ($q) => $q->orWhere(fn ($reversed) => $reversed->where(['subject_id' => $object, 'object_id' => $subject]))))
                ->orderBy('id')
                ->first();

            if ($subject === $object || $duplicate !== null) {
                DB::table('entity_relations')->where('id', $row->id)->delete();
                $summary['removed']['entity_relations'][] = (array) $row;
                if ($duplicate !== null) {
                    $this->updateKeptRow('entity_relations', $duplicate, self::earlierCreatedAt($row, $duplicate), $summary);
                }

                continue;
            }

            $fits = ((int) $row->subject_id !== $from->id || $this->fitsScope((int) $row->relation_id, 'subject_id', $from, $into))
                && ((int) $row->object_id !== $from->id || $this->fitsScope((int) $row->relation_id, 'object_id', $from, $into));
            if (! $fits) {
                continue;
            }

            DB::table('entity_relations')->where('id', $row->id)->update(['subject_id' => $subject, 'object_id' => $object, 'updated_at' => now()]);
            $summary['moved']['entity_relations'][] = (int) $row->id;
            // 改接時兩端可能對調（相關照 id 小的當主詞），只記 id 還原不了方向
            $summary['updated']['entity_relations'][] = ['id' => (int) $row->id, 'subject_id' => (int) $row->subject_id, 'object_id' => (int) $row->object_id];
        }
    }

    /**
     * 鎖住兩筆技術（依 id 排序，兩個合併同時進來也不會互相等成死結），再確認 $into 還在、還沒停用。
     * SQLite 沒有列鎖，`lockForUpdate()` 不起作用；SQLite 的寫入本來就一次只有一個。
     *
     * @throws ValidationException
     */
    private function lockAndRecheck(Technique $from, Technique $into): void
    {
        $locked = Technique::whereKey([$from->id, $into->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $current = $locked->get($into->id);

        if ($current === null || $current->isDeprecated()) {
            throw ValidationException::withMessages(['replaced_by_id' => ["技術「{$into->label}」已刪除或已停用，請改用仍在使用的技術。"]]);
        }
    }

    /**
     * 刪掉 $removed、留下 $kept 時，$kept 要改成的 `created_at`：兩列裡較早的那個。null 代表開始記錄之前就存在，算最早。
     * $kept 已經比較早（或一樣）時回空陣列。
     *
     * @return array<string, string|null>
     */
    private static function earlierCreatedAt(object $removed, object $kept): array
    {
        $removedAt = $removed->created_at ?? null;
        $keptAt = $kept->created_at ?? null;

        if ($keptAt === null || ($removedAt !== null && (string) $removedAt >= (string) $keptAt)) {
            return [];
        }

        return ['created_at' => $removedAt];
    }

    /**
     * 把 $values 寫進留下來的那一列，改寫前的值記進 `$summary['updated']`。沒有要改的就不動。
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $summary
     */
    private function updateKeptRow(string $table, object $kept, array $values, array &$summary): void
    {
        if ($values === []) {
            return;
        }

        DB::table($table)->where('id', $kept->id)->update($values + ['updated_at' => now()]);
        $original = ['id' => (int) $kept->id];
        foreach (array_keys($values) as $column) {
            $original[$column] = $kept->{$column};
        }
        $summary['updated'][$table][] = $original;
    }

    /** $into 的分類在述詞規定的那一端分類底下；不在的話記一筆衝突。述詞已被刪除時照樣搬（邊本來就留著）。 */
    private function fitsScope(int $relationId, string $end, Technique $from, Technique $into): bool
    {
        $relation = $this->relations[$relationId] ??= Relation::withTrashed()->find($relationId);
        if ($relation === null) {
            return true;
        }

        $this->ancestry ??= ScopeAncestry::fromDatabase();
        if ($this->ancestry->matches((int) $into->type, (int) $relation->{$end})) {
            return true;
        }

        $this->conflicts[] = "「{$into->label}」的分類不符合述詞「{$relation->name}」，「{$from->label}」用這個述詞的連結搬不過去。";

        return false;
    }

    private function addOldNameAsLabel(Technique $from, Technique $into): ?int
    {
        $key = NomenResolver::normalize($from->label);
        $taken = $key === '' || $key === NomenResolver::normalize($into->label)
            || ResLabel::where('res_type', 'technique')->where('res_id', $into->id)->where('normalized', $key)->exists();
        if ($taken) {
            return null;
        }

        return ResLabel::create([
            'res_type' => 'technique',
            'res_id' => $into->id,
            'label' => $from->label,
            'label_type' => ResLabelType::Hidden,
            'note' => "合併前的正式名（technique #{$from->id}）",
        ])->id;
    }

    private function noun(string $table): string
    {
        return $table === 'technique_implementation' ? '實作' : '文件';
    }

    private static function problemMessage(string $rule, Technique $from, Technique $into): string
    {
        $what = match ($rule) {
            'cycle' => '上下位會繞成環',
            'related_in_hierarchy' => '標了相關的兩個技術會有上下位路徑',
            'redundant_broader' => '會有重複的上位（已經能經別的上位走到）',
            default => '會有自己連自己的上下位或相關',
        };

        return "把「{$from->label}」合併進「{$into->label}」之後{$what}，請先在後台調整上下位與相關。";
    }
}
