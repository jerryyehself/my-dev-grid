<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 合併重複的技術，並把版本黏在名稱上的技術轉成「版本」（2026-09-30 使用者同意）。
 *
 * 起因：專案頁的技術標籤出現 PHP／php、Vue／vue 兩兩重複。GitHub 的 languages API 給
 * `PHP`、topic 給 `php`，舊的同步程式照 (scope, title) 找技術，大小寫或類別不同就各建一筆。
 * 同步程式現在已經改成權威控制（SaveReposDataService::AUTHORITY），這支只處理已經存在的資料：
 *
 * 1. 同一個標準名稱＋版本的技術合併成一筆（survivor），其他筆的邊全部改接到 survivor，
 *    改接後跟 survivor 既有的邊重複的就刪掉（保留較早的 created_at），最後軟刪除重複的那筆。
 * 2. survivor 的 title／scope／version 改成權威表的標準值（例如 vue → Vue、framework）。
 * 3. 有版本的技術（例如 vue3 → Vue 版本 3），確保有版本留空的那一筆，並用 isVersionOf 連過去；
 *    原本掛在版本那筆上的 requires 搬到版本留空那筆（requires JavaScript 是「Vue」的事）。
 *
 * 專案連到「Vue」又連到「Vue 3」的邊不刪：同步程式之後不會再這樣建，但既有的那條邊的
 * created_at 可能早於 Vue 3，是真的歷史，不能因為「推得出來」就丟掉。
 *
 * AUTHORITY 是 2026-09-30 SaveReposDataService::AUTHORITY 的快照，刻意不跟著那邊改——
 * 同一個慣例見 2026_09_07_000000_reclassify_framework_topics_from_packagetool_to_framework.php。
 *
 * 可以完整退回：每一筆改過、刪過、新建的資料都先記進 technique_merge_backups，down() 照著
 * 還原，最後把那張表刪掉。全新的資料庫（沒有技術）什麼都不做。
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string|null}> 小寫 title → [標準名稱, scope, 版本] */
    public const AUTHORITY = [
        'php' => ['PHP', 'language', null],
        'python' => ['Python', 'language', null],
        'vue' => ['Vue', 'framework', null],
        'vuejs' => ['Vue', 'framework', null],
        'vue3' => ['Vue', 'framework', '3'],
        'nuxt' => ['Nuxt', 'framework', null],
        'nuxtjs' => ['Nuxt', 'framework', null],
        'nuxt3' => ['Nuxt', 'framework', '3'],
        'bootstrap5' => ['Bootstrap', 'packagetool', '5'],
    ];

    private const BACKUP = 'technique_merge_backups';

    /** 有 technique_id 的 pivot 表，以及各自除了 technique_id 以外組成唯一鍵的欄位 */
    private const PIVOTS = [
        'technique_implementation' => ['implementation_id', 'relation_id'],
        'documentation_technique' => ['documentation_id', 'relation_id'],
    ];

    public function up(): void
    {
        Schema::create(self::BACKUP, function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 50);
            $table->unsignedBigInteger('row_id');
            $table->string('action', 10)->comment('updated / deleted / created');
            $table->json('original')->nullable();
        });

        if (! DB::table('techniques')->exists()) {
            return;
        }

        DB::transaction(function () {
            $scopeIds = DB::table('scopes')->whereNull('deleted_at')->pluck('id', 'name');
            $scopeNames = $scopeIds->flip();

            $groups = DB::table('techniques')->whereNull('deleted_at')->orderBy('id')->get()
                ->map(function ($t) use ($scopeNames) {
                    [$title, $scope, $version] = $t->version === null && isset(self::AUTHORITY[mb_strtolower($t->title)])
                        ? self::AUTHORITY[mb_strtolower($t->title)]
                        : [$t->title, $scopeNames[$t->type] ?? null, $t->version];
                    $t->canonical = compact('title', 'scope', 'version');

                    return $t;
                })
                ->groupBy(fn ($t) => mb_strtolower($t->canonical['title']).'|'.($t->canonical['version'] ?? ''));

            foreach ($groups as $group) {
                $canonical = $group->first()->canonical;
                // 標準類別跟標準寫法都對的優先，其次類別對的，再來最早建的
                $survivor = $group->sortBy(fn ($t) => [
                    ($scopeNames[$t->type] ?? null) === $canonical['scope'] ? 0 : 1,
                    $t->title === $canonical['title'] ? 0 : 1,
                    $t->id,
                ])->first();

                foreach ($group->where('id', '!=', $survivor->id) as $loser) {
                    $this->repoint($loser->id, $survivor->id);
                    $this->update('techniques', $loser->id, ['deleted_at' => now()]);
                }

                $wanted = [
                    'title' => $canonical['title'],
                    'type' => $scopeIds[$canonical['scope']] ?? $survivor->type,
                    'version' => $canonical['version'],
                ];
                if ($wanted !== ['title' => $survivor->title, 'type' => $survivor->type, 'version' => $survivor->version]) {
                    $this->update('techniques', $survivor->id, $wanted + ['updated_at' => now()]);
                }
            }

            $this->linkVersionsToTheirBase();
        });
    }

    /** 把 $from 這筆技術的所有邊改接到 $to；改接後重複的邊刪掉，留下較早的 created_at */
    private function repoint(int $from, int $to): void
    {
        foreach (self::PIVOTS as $table => $keys) {
            foreach (DB::table($table)->where('technique_id', $from)->get() as $row) {
                $twin = DB::table($table)->where('technique_id', $to)
                    ->where(collect($keys)->mapWithKeys(fn ($k) => [$k => $row->$k])->all())
                    ->first();

                if ($twin) {
                    if ($row->created_at < $twin->created_at) {
                        $this->update($table, $twin->id, ['created_at' => $row->created_at]);
                    }
                    $this->delete($table, $row);
                } else {
                    $this->update($table, $row->id, ['technique_id' => $to]);
                }
            }
        }

        foreach (['subject_id', 'object_id'] as $side) {
            foreach (DB::table('entity_relations')->where('entity_type', 'technique')->where($side, $from)->get() as $row) {
                $moved = (array) $row;
                $moved[$side] = $to;
                $this->moveEntityRelation($row, $moved);
            }
        }
    }

    /** 有版本的技術：確保有版本留空的那一筆、連上 isVersionOf，並把 requires 搬過去 */
    private function linkVersionsToTheirBase(): void
    {
        $isVersionOf = DB::table('relations')->where('name', 'isVersionOf')->whereNull('deleted_at')->value('id');
        $requires = DB::table('relations')->where('name', 'requires')->whereNull('deleted_at')->value('id');
        if (! $isVersionOf) {
            return;
        }

        foreach (DB::table('techniques')->whereNull('deleted_at')->whereNotNull('version')->get() as $version) {
            $base = DB::table('techniques')->whereNull('deleted_at')->whereNull('version')
                ->whereRaw('lower(title) = ?', [mb_strtolower($version->title)])->orderBy('id')->first();

            $baseId = $base?->id ?? $this->insert('techniques', [
                'type' => $version->type,
                'title' => $version->title,
                'version' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $edge = ['entity_type' => 'technique', 'subject_id' => $version->id, 'object_id' => $baseId, 'relation_id' => $isVersionOf];
            if (! DB::table('entity_relations')->where($edge)->exists()) {
                $this->insert('entity_relations', $edge + ['created_at' => now(), 'updated_at' => now()]);
            }

            if ($requires) {
                foreach (DB::table('entity_relations')->where(['entity_type' => 'technique', 'subject_id' => $version->id, 'relation_id' => $requires])->get() as $row) {
                    $moved = (array) $row;
                    $moved['subject_id'] = $baseId;
                    $this->moveEntityRelation($row, $moved);
                }
            }
        }
    }

    /** entity_relations 改接：改完變成自己連自己、或跟既有的重複，就刪掉 */
    private function moveEntityRelation(object $row, array $moved): void
    {
        $duplicate = DB::table('entity_relations')->where('id', '!=', $row->id)->where([
            'entity_type' => $moved['entity_type'],
            'subject_id' => $moved['subject_id'],
            'object_id' => $moved['object_id'],
            'relation_id' => $moved['relation_id'],
        ])->exists();

        if ($duplicate || (int) $moved['subject_id'] === (int) $moved['object_id']) {
            $this->delete('entity_relations', $row);
        } else {
            $this->update('entity_relations', $row->id, ['subject_id' => $moved['subject_id'], 'object_id' => $moved['object_id']]);
        }
    }

    private function update(string $table, int $id, array $values): void
    {
        $this->remember($table, $id, 'updated', DB::table($table)->where('id', $id)->first());
        DB::table($table)->where('id', $id)->update($values);
    }

    private function delete(string $table, object $row): void
    {
        $this->remember($table, $row->id, 'deleted', $row);
        DB::table($table)->where('id', $row->id)->delete();
    }

    private function insert(string $table, array $values): int
    {
        $id = DB::table($table)->insertGetId($values);
        $this->remember($table, $id, 'created', null);

        return $id;
    }

    private function remember(string $table, int $id, string $action, ?object $original): void
    {
        DB::table(self::BACKUP)->insert([
            'table_name' => $table,
            'row_id' => $id,
            'action' => $action,
            'original' => $original ? json_encode($original) : null,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::BACKUP)) {
            return;
        }

        DB::transaction(function () {
            // 倒著做：後來的改動先退，同一筆改過兩次的會退回最早的樣子
            foreach (DB::table(self::BACKUP)->orderByDesc('id')->get() as $entry) {
                $original = $entry->original ? (array) json_decode($entry->original) : null;

                match ($entry->action) {
                    'created' => DB::table($entry->table_name)->where('id', $entry->row_id)->delete(),
                    'deleted' => DB::table($entry->table_name)->insert($original),
                    'updated' => DB::table($entry->table_name)->where('id', $entry->row_id)->update($original),
                };
            }
        });

        Schema::drop(self::BACKUP);
    }
};
