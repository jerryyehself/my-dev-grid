<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 文章的匯入來源（#113）：文章可以從另一個 repo（文章來源 repo）的 Markdown 檔匯入，
 * 這幾欄記錄一篇文章是從哪裡、哪個版本匯入的。
 *
 * - source_repo＋source_key：重新匯入時用來對上同一篇文章（唯一索引在下一支 migration）。
 * - source_path、source_blob_sha、source_commit_sha：來源檔案的位置與版本，判斷檔案有沒有變。
 * - source_body_hash：匯入時寫進 `body` 的內容的雜湊；之後 `body` 對不上，就是後台改過。
 *
 * 全部 nullable、沒有回填：手寫的文章與外部官方文件本來就沒有來源；migrate Job 在新版程式
 * 接流量之前跑，舊版程式不讀這幾欄，照常運作。
 *
 * 刻意不加進 `Documentation::$fillable`、`DocumentationResource` 與文章的 FormRequest：
 * 只有匯入程式會寫這幾欄，不透過 API 公開，也不能從 API 修改。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 加欄位要拿 ACCESS EXCLUSIVE，排隊等鎖時會擋住後面的查詢；等太久就放棄這次部署
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('documentations', function (Blueprint $table) {
            $table->string('source_repo')->nullable()->comment('匯入來源的 repo');
            $table->string('source_key')->nullable()->comment('來源文章的 slug（front matter），同一個 repo 內唯一');
            $table->text('source_path')->nullable()->comment('來源檔案在 repo 裡的路徑');
            $table->char('source_blob_sha', 40)->nullable()->comment('匯入時來源檔案的 git blob SHA-1');
            $table->char('source_commit_sha', 40)->nullable()->comment('匯入時來源 repo 的 commit SHA-1');
            $table->timestamp('source_imported_at')->nullable()->comment('最後一次匯入的時間');
            $table->char('source_body_hash', 64)->nullable()->comment('匯入時寫進 body 的內容的 SHA-256（hex）');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('documentations', function (Blueprint $table) {
            $table->dropColumn([
                'source_repo',
                'source_key',
                'source_path',
                'source_blob_sha',
                'source_commit_sha',
                'source_imported_at',
                'source_body_hash',
            ]);
        });
    }
};
