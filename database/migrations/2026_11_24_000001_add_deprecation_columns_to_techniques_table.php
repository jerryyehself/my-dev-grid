<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 技術的停用：在公開 API 出現過的技術有固定的識別碼，要換掉時不刪除，改標為停用，
 * 並可指定改用哪一筆（合併重複的技術時，舊的那筆指到留下來的那筆）。照述詞的前例
 * （`2026_11_18_000000_add_deprecation_columns_to_relations_table.php`）加同樣的兩欄。
 *
 * - deprecated_at：停用的時間，null 代表仍在使用。用時間而不是布林，順便記下何時停用。
 * - replaced_by_id：停用後改用的技術。外鍵刻意不設 onDelete（預設 NO ACTION，等於擋刪）：
 *   設成 set null 的話，硬刪除會默默抹掉「改用誰」。技術平常是軟刪除，不受影響。
 *
 * 只新增可 null 的欄位、不回填：既有的技術一開始都是使用中。migrate Job 在新版程式接流量
 * 之前跑，舊版程式不讀這兩欄；`TechniqueResource` 與 `/api/graph` 的節點都是逐欄挑出來的，
 * 新欄位不會出現在舊版的 API。
 *
 * 刻意不加索引：這張表只有幾十列，`where replaced_by_id = ?` 全表掃描也很快，不值得另開
 * 一支 `->online()` 的 migration。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 加欄位要拿 ACCESS EXCLUSIVE，排隊等鎖時會擋住後面的查詢；等太久就放棄這次部署
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        $this->deferSqliteForeignKeys();

        Schema::table('techniques', function (Blueprint $table) {
            $table->timestamp('deprecated_at')->nullable()->comment('停用時間，null 代表仍在使用');
            $table->foreignId('replaced_by_id')->nullable()->constrained('techniques')->comment('停用後改用的技術');
        });
    }

    /**
     * 拿掉兩欄時，已經標的停用與改用對象會一起消失；正式環境要退結構時，先備份資料庫，
     * 或改用新的 migration 往前修。
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        $this->deferSqliteForeignKeys();

        Schema::table('techniques', function (Blueprint $table) {
            $table->dropForeign(['replaced_by_id']);
            $table->dropColumn(['deprecated_at', 'replaced_by_id']);
        });
    }

    /**
     * SQLite（CI 與本機）加或拿掉外鍵時，Laravel 會整張表重建：建暫存表、複製、刪掉舊表、改名。
     * 它會先下 `PRAGMA foreign_keys = OFF`，但在 transaction 裡（例如測試）這句沒有作用；表裡有
     * 互相指著的技術（replaced_by_id）時，刪舊表那一步就會撞外鍵。改成把外鍵檢查延到 commit 才做，
     * 那時表已經改名回來，參照都對得上。PostgreSQL 不重建表，不需要。
     */
    private function deferSqliteForeignKeys(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
    }
};
