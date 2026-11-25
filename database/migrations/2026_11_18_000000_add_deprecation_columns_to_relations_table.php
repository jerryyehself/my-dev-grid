<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 述詞的棄用：已經被邊用到的述詞不能刪（刪了邊會指著查不到的述詞），要換掉時改標為棄用，
 * 並可指定改用哪一條。
 *
 * - deprecated_at：棄用的時間，null 代表仍在使用。用時間而不是布林，順便記下何時棄用。
 * - replaced_by_id：棄用後改用的述詞。外鍵刻意不設 onDelete（預設 NO ACTION，等於擋刪），
 *   跟同表的 reverse_id 一樣：設成 set null 的話，硬刪除會默默抹掉「改用誰」。
 *
 * 只新增可 null 的欄位、不回填：既有的述詞一開始都是使用中。migrate Job 在新版程式接流量
 * 之前跑，舊版程式不讀這兩欄，`RelationResource` 是白名單，新欄位也不會出現在舊版的 API。
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

        Schema::table('relations', function (Blueprint $table) {
            $table->timestamp('deprecated_at')->nullable()->comment('棄用時間，null 代表仍在使用');
            $table->foreignId('replaced_by_id')->nullable()->constrained('relations')->comment('棄用後改用的述詞');
        });
    }

    /**
     * 拿掉兩欄時，已經標的棄用與改用對象會一起消失；正式環境要退結構時，先備份資料庫，
     * 或改用新的 migration 往前修。
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        $this->deferSqliteForeignKeys();

        Schema::table('relations', function (Blueprint $table) {
            $table->dropForeign(['replaced_by_id']);
            $table->dropColumn(['deprecated_at', 'replaced_by_id']);
        });
    }

    /**
     * SQLite（CI 與本機）加或拿掉外鍵時，Laravel 會整張表重建：建暫存表、複製、刪掉舊表、改名。
     * 它會先下 `PRAGMA foreign_keys = OFF`，但在 transaction 裡（例如測試直接呼叫 `up()`／`down()`）
     * 這句沒有作用；表裡有互相指著的述詞（reverse_id）時，刪舊表那一步就會撞外鍵。`artisan migrate`
     * 在 SQLite 上不包 transaction（Laravel 只替 PostgreSQL、SQL Server 的結構變更包），所以那時
     * 這句有作用，延後檢查只是多一層保險。改成把外鍵檢查延到 commit 才做，那時表已經改名回來，
     * 參照都對得上。PostgreSQL 不重建表，不需要。
     */
    private function deferSqliteForeignKeys(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
    }
};
