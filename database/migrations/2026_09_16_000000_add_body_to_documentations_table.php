<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 文章內文欄位（D-46）。
     *
     * 存的是 Markdown 原文,不是渲染後的 HTML——渲染在前端走 mdast 產生 Vue vnode（D-47）。
     *
     * 為什麼不借用既有的 `note`:那一欄的語意是「註解」,是針對這筆資料本身的備註,
     * 跟文章內文是兩回事。覆載一個既有欄位省不了多少事,卻讓兩種語意混在同一格。
     *
     * 為什麼不是檔案:部署是 Cloud Run `--min-instances=0 --memory=512Mi`,
     * 容器的可寫檔案系統是記憶體、而且實例回收就沒了,所以「寫成 .md 檔」實際上
     * 等於每次讀寫都打一次 GCS API,而且檔案寫入跟這一列的寫入不在同一個 transaction。
     * 完整的取捨記在 D-46。
     *
     * nullable:現有的 5 筆 Documentation 全是 sourcesite（0010,外部官方文件）,
     * 本來就沒有內文,不該被逼著填一個空字串。
     */
    public function up(): void
    {
        Schema::table('documentations', function (Blueprint $table) {
            $table->text('body')->comment('內文（Markdown 原文）')->nullable()->after('uri');
        });
    }

    public function down(): void
    {
        Schema::table('documentations', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
};
