<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026-09-09 補的 follow-up：`vue3` 是 `my-dev-grid` 自己在 GitHub 上實際
     * 掛的 topic（不是 `vue`），沒被 2026-09-07 那支 migration 的快照涵蓋到
     * （那支只認得 FRAMEWORK_BASE_LANGUAGE 當時的 key），所以之前每次同步都
     * 把它誤歸類進 packagetool。這支只處理 `vue3` 這一筆，其餘 topic 已經在
     * 前一支處理過，不重複列。
     */
    private const TOPIC = 'vue3';

    /**
     * Run the migrations.
     *
     * 邏輯跟 2026_09_07 那支一致：就地把 packagetool scope 底下 title 為
     * `vue3` 的 Technique row 改成 framework scope，只更新 `type` 欄位、不刪
     * 除重建，讓既有 `technique_implementation`／`entity_relations`／
     * `documentation_technique` pivot 對這個 row id 的參照維持有效。若
     * framework scope 底下已經有一筆同名 row（例如 Triple 後台手動建過），
     * 就跳過、保留 packagetool 那筆原樣不動，避免製造重複 row。
     */
    public function up(): void
    {
        $packagetoolScopeId = DB::table('scopes')->where('name', 'packagetool')->value('id');
        $frameworkScopeId = DB::table('scopes')->where('name', 'framework')->value('id');

        if (! $packagetoolScopeId || ! $frameworkScopeId) {
            return;
        }

        $alreadyFramework = DB::table('techniques')
            ->where('type', $frameworkScopeId)
            ->whereNull('deleted_at')
            ->where('title', self::TOPIC)
            ->exists();

        if ($alreadyFramework) {
            return;
        }

        DB::table('techniques')
            ->where('type', $packagetoolScopeId)
            ->whereNull('deleted_at')
            ->where('title', self::TOPIC)
            ->update(['type' => $frameworkScopeId]);
    }

    /**
     * Reverse the migrations.
     *
     * 刻意不做反向回滾，理由跟 2026_09_07 那支一致：回滾後沒辦法區分「這支
     * migration 改的」跟「migration 跑完後由正常同步流程新建的 framework
     * technique」，硬回滾可能誤傷後者。
     */
    public function down(): void
    {
        //
    }
};
