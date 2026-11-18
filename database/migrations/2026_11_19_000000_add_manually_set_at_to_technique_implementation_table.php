<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 技術與專案之間的邊，記下站主在後台指定述詞的時間。
 *
 * GitHub 同步依證據決定述詞：程式裡有證據（語言統計、套件清單）寫「使用」（usedBy），只有 topic
 * 寫「關於」（subjectOfProject），之後看到程式證據會把「關於」升成「使用」。站主也可能把邊改成這兩個述詞
 * 之一，光看述詞分不出是誰定的；沒有這一欄，站主改成「關於」的邊，隔天同步看到程式證據就會被升回「使用」。
 * 有值時，同步不改這一對專案與技術的述詞，也不在旁邊另長一列（見 `SaveReposDataService`）。
 *
 * - 用時間而不是布林，跟 `relations.deprecated_at` 同一個做法，順便記下何時改的。
 * - 只新增可 null 的欄位、不回填：既有的邊都還沒被站主指定過。migrate Job 在新版程式接流量之前跑，
 *   舊版程式不讀這一欄，照常運作。
 * - 不加索引：同步依 (implementation_id, technique_id) 查，唯一索引的前兩欄已經涵蓋。
 * - 這一欄沒有外鍵，SQLite 加欄位不會重建整張表。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 加欄位要拿 ACCESS EXCLUSIVE，排隊等鎖時會擋住後面的查詢；等太久就放棄這次部署
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('technique_implementation', function (Blueprint $table) {
            $table->timestamp('manually_set_at')->nullable()
                ->comment('站主在後台指定這條邊述詞的時間；有值時，同步不改、不在旁邊另長這一對專案與技術的邊');
        });
    }

    /**
     * 拿掉這一欄時，站主指定過的標記會一起消失，之後同步會把那些邊當成自己管的。只給本機用；
     * 正式環境要退的話，寫新的 migration 往前修。
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('technique_implementation', function (Blueprint $table) {
            $table->dropColumn('manually_set_at');
        });
    }
};
