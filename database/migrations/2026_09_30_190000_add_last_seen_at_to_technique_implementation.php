<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「專案用了哪個技術」這條邊最後一次被 GitHub 同步看到的時間（2026-09-30 使用者同意）。
 *
 * 同步只新增、不刪邊，專案從 Vue 2 升到 Vue 3 之後兩條邊都會留著——歷史就這樣累積下來。
 * 但光靠 created_at（第一次看到）分不出哪個還在用：每次同步到就更新這一欄，很久沒更新的
 * 就是以前用過的版本。
 *
 * 只加可為 null 的欄位，舊版程式看不到也能照常運作。既有的邊留 null，代表「開始記錄之前
 * 就有的」，下一次同步會填上；不拿 created_at 回填，那會把「第一次看到」冒充成「最後一次看到」。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technique_implementation', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('relation_id')
                ->comment('最後一次被 GitHub 同步看到的時間；null＝開始記錄前就有、或手動建立');
        });
    }

    public function down(): void
    {
        Schema::table('technique_implementation', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
