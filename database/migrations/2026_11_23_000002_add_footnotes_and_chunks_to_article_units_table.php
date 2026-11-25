<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `article_units` 加三欄：容器標記、歸給這個單位的腳註、太長單位的取用子片段（規則見 `ArticleUnitSplitter`）。
 *
 * - 腳註與子片段存成 JSON，不另開資料表：兩者都只跟著所屬單位一起讀寫，沒有要單獨查詢或建索引的需求；
 *   同一個腳註被兩個單位引用時兩邊各存一份，正是要的結果。子片段不寫成獨立的單位列，
 *   因為引用與心智圖一律指回原單位，子片段只是檢索內部的切法。
 * - 子片段在匯入時切好存起來，不在讀取時才算：檢索讀到的片段與 ID 跟這次匯入的內容是同一版，
 *   之後調整上限或切法，要等重新匯入才會換，不會讀到一半變了。
 * - 用 `json` 不用 `jsonb`：`jsonb` 會重排 key，Eloquent 比對新舊值時順序不同就當成有變，
 *   內容沒改的單位每次匯入都會被重寫。
 * - 只做向下相容的新增：兩個 JSON 欄位可以是 null，容器標記有常數預設值 false。舊版程式寫入時不帶這三欄，
 *   照預設值；沒有東西讀這三欄。PostgreSQL 加常數預設值的欄位不重寫整張表。
 * - 不回填：既有的列在下次匯入時由 `ArticleUnitSync` 寫上。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 加欄位要拿 ACCESS EXCLUSIVE，排隊等鎖時會擋住後面對這張表的讀寫；等太久就放棄這次部署
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('article_units', function (Blueprint $table) {
            $table->boolean('is_container')->default(false)
                ->comment('H2 底下有 H3、自己幾乎沒有內容：只當容器，不當取用單位');
            $table->json('footnotes')->nullable()
                ->comment('歸給這個單位的腳註定義（標籤、原文與原本的行號）；沒有是空值');
            $table->json('chunks')->nullable()
                ->comment('內容太長時切出的取用子片段（ID 是 unit_id~N）；沒切是空值');
        });
    }

    /**
     * 拿掉這三欄時，腳註歸屬與子片段一起消失，單位的 `content` 仍然是腳註定義搬走之後的內容；
     * 回到舊版程式後下次匯入會把定義寫回原位。只給本機用；正式環境要退的話，寫新的 migration 往前修。
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('article_units', function (Blueprint $table) {
            $table->dropColumn(['is_container', 'footnotes', 'chunks']);
        });
    }
};
