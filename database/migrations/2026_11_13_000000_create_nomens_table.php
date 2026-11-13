<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 新增 `nomens`：記錄同步與文章匯入讀到的每個名稱字串（GitHub 語言、topic、文章 front matter），
 * 以及它在哪個作品出現、對上了哪個節點。
 *
 * 參考 IFLA LRM 的 Nomen（名稱與它指稱的 res 之間的關聯）。一列不是一個 LRM nomen，而是
 * 「某個來源、在某個作品裡，用過這個字串」的一筆證據：LRM 2024 版把 nomen 的身分定為
 * res＋字串，要查 nomen 時用 `DISTINCT (res_type, res_id, string)` 推，不另存，也刻意不加
 * `UNIQUE(res_type, res_id, string)`——還沒對上節點的列 res 是空的，晚一點才會補上；同一個
 * 字串從好幾個來源來，正是要留下的多筆證據。還沒對上節點的字串嚴格說不算 nomen，這裡一起存。
 *
 * 唯一鍵 `(source, context_type, context_id, normalized)`：
 * - `source` 細到欄位（`front_matter.about_techniques`、`front_matter.about_projects`），
 *   同一篇文章兩個欄位寫了同一個字串時是兩列，不會互相覆蓋。
 * - `context_type`、`context_id` 不可為空。唯一索引把 NULL 當成彼此不同，沒有作品位置的來源
 *   改用固定值（`Nomen::NO_CONTEXT_TYPE`、`Nomen::NO_CONTEXT_ID`），重跑才不會多出一列。
 *
 * `res_type`＋`res_id` 不加外鍵，跟 `entity_relations` 一樣：指向的表隨 `res_type` 變動。
 *
 * 這一步只新增資料表，不改任何既有的表；程式照舊寫邊，nomens 只是旁邊多記一份。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 外鍵會對 relations 拿 SHARE ROW EXCLUSIVE，排隊等鎖時會擋住後面對 relations 的寫入；等太久就放棄這次部署
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('nomens', function (Blueprint $table) {
            $table->id();
            $table->string('string')->comment('讀到的原樣字串，同一來源、同一作品裡第一次看到的寫法');
            $table->string('normalized')->comment('比對用的鍵，在 PHP 算好（NomenResolver::normalize），比對時只做完全相等');
            $table->string('source', 48)->comment('從哪個來源的哪個欄位讀到，例如 github_language、front_matter.about_techniques');
            $table->string('scheme', 32)->nullable()->comment('字串屬於哪套命名，例如 linguist、github-topics、site');
            $table->string('context_type', 32)->comment('出現在哪種作品：implementation、documentation；沒有作品位置的來源用固定值');
            $table->unsignedBigInteger('context_id')->comment('出現在哪個作品；無 DB 層外鍵，指向的表隨 context_type 變動');
            $table->foreignId('predicate_id')->nullable()->constrained('relations')->nullOnDelete()->comment('這次出現主張的述詞；只當佐證、不主張述詞的是空值');
            $table->unsignedBigInteger('weight')->nullable()->comment('證據量：GitHub 語言的位元組數；其他來源是空值');
            $table->string('res_type', 32)->nullable()->comment('對上的節點種類：technique、implementation；沒對上是空值');
            $table->unsignedBigInteger('res_id')->nullable()->comment('對上的節點；無 DB 層外鍵，指向的表隨 res_type 變動');
            $table->string('resolution', 32)->comment('怎麼對上或為什麼沒對上，例如 exact、variant:suffix、unresolved');
            $table->string('locale', 16)->nullable()->comment('人寫的名稱是哪種自然語言（例如 zh-Hant）；GitHub 來的字串留空');
            $table->timestamp('first_seen_at')->comment('這個來源第一次讀到這個字串的時間');
            $table->timestamp('last_seen_at')->comment('這個來源最後一次讀到這個字串的時間');
            $table->timestamps();

            $table->unique(['source', 'context_type', 'context_id', 'normalized']);
            $table->index('normalized');
            $table->index(['res_type', 'res_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomens');
    }
};
