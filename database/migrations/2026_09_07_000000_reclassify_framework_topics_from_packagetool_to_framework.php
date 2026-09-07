<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 這份清單是 SaveReposDataService::FRAMEWORK_BASE_LANGUAGE 在這次修正
     * (2026-09-07,`fix/framework-topic-classification`)當下的 key 快照，
     * 刻意不從程式碼常數動態讀——這支 migration 只負責修正「這次修正之前」
     * 累積的錯誤分類資料，之後 FRAMEWORK_BASE_LANGUAGE 再新增 key 不會、也
     * 不該回頭影響這支已經跑過的 migration。若未來清單新增 key 且舊資料已經
     * 用 packagetool 同步過同名 topic，需要另外補一支新的 migration。
     *
     * @var list<string>
     */
    private const FRAMEWORK_TOPICS_AT_FIX_TIME = [
        'vue', 'vuejs', 'react', 'nextjs', 'nuxt', 'nuxtjs', 'express',
        'angular', 'laravel', 'symfony', 'django', 'flask', 'rails',
        'spring', 'spring-boot',
    ];

    /**
     * Run the migrations.
     *
     * 就地把先前被 SaveReposDataService 誤歸類到 packagetool scope、但其實
     * 是 framework/library 的 Technique row 改成 framework scope——只更新
     * 既有 row 的 `type` 欄位，不刪除重建，讓 row id 保持不變，這樣
     * `technique_implementation`／`entity_relations`／`documentation_technique`
     * 三個關聯表裡任何已經指向這個 id 的 pivot/relation 資料都還有效。
     *
     * 若同一個 title 底下 framework scope 已經存在一筆(例如 Triple 後台手動
     * 建過)，就跳過那筆、保留 packagetool 那筆原樣不動，避免製造出 framework
     * scope 底下同 title 的重複 row——這種情況本來就不是這支 migration
     * 能安全處理的資料狀態，留給人工檢查。
     */
    public function up(): void
    {
        $packagetoolScopeId = DB::table('scopes')->where('name', 'packagetool')->value('id');
        $frameworkScopeId = DB::table('scopes')->where('name', 'framework')->value('id');

        if (! $packagetoolScopeId || ! $frameworkScopeId) {
            // Scope 還沒 seed(例如全新環境是先跑 migration 才跑 seeder)就沒
            // 有 packagetool/framework 可比對，沒東西可修，直接跳過。
            return;
        }

        $alreadyFrameworkTitles = DB::table('techniques')
            ->where('type', $frameworkScopeId)
            ->whereNull('deleted_at')
            ->whereIn('title', self::FRAMEWORK_TOPICS_AT_FIX_TIME)
            ->pluck('title')
            ->all();

        $titlesToReclassify = array_values(array_diff(self::FRAMEWORK_TOPICS_AT_FIX_TIME, $alreadyFrameworkTitles));

        if (empty($titlesToReclassify)) {
            return;
        }

        DB::table('techniques')
            ->where('type', $packagetoolScopeId)
            ->whereNull('deleted_at')
            ->whereIn('title', $titlesToReclassify)
            ->update(['type' => $frameworkScopeId]);
    }

    /**
     * Reverse the migrations.
     *
     * 刻意不做反向回滾：回滾之後沒辦法區分「本來就是這支 migration 改的」
     * 跟「migration 跑完之後才由正常同步流程新建的 framework technique」，
     * 硬回滾反而可能把後者也錯誤打回 packagetool。真的需要撤銷的話用上面
     * 的 title 清單手動反向 UPDATE，並先核對每一筆改動時間。
     */
    public function down(): void
    {
        //
    }
};
