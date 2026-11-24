<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新增 `res_labels`：人工宣告的「這個 res 也叫這個名字」，也就是詞表裡的別名（SKOS 的 altLabel、hiddenLabel）。
 *
 * 正式名不另存，仍是 `techniques.title`（＋`version`，見 `Technique::labelFrom()`）與 `implementations.title`，
 * 不會有兩份要同步。跟 `nomens` 分工：`nomens` 是「這個字串在某個作品出現過」的證據，這張表是人工維護的名稱。
 *
 * - `res_type`＋`res_id` 不加外鍵，跟 `nomens`、`entity_relations` 一樣：指向的表隨 `res_type` 變動。
 *   `res_type` 只能是哪幾種、`res_id` 要指到存在的列，由 model 在寫入前檢查（`ResLabel`）。
 * - `normalized` 是比對用的鍵（`NomenResolver::normalize()` 算出來的），不是外鍵，也不設唯一：
 *   同形異義的兩個 res（例如兩個概念都有「token」這個別名）都要存得進去。
 * - 唯一鍵 `(res_type, res_id, normalized)`：同一個 res 不會把同一個字串（含只差大小寫、全半形的寫法）
 *   存兩次，也就不會同一個字串既是 alt 又是 hidden。刻意不含 `locale`：名稱的身分只由 res＋字串決定，
 *   語言只是詮釋用的屬性。這個索引也涵蓋「列出某個 res 的別名」（前兩欄），不另建 `(res_type, res_id)`。
 *
 * 只新增資料表、沒有外鍵，不鎖任何既有的表，所以不需要 `lock_timeout`；舊版程式不知道這張表，照常運作。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('res_labels', function (Blueprint $table) {
            $table->id();
            $table->string('res_type', 32)->comment('哪種 res：technique、implementation');
            $table->unsignedBigInteger('res_id')->comment('哪個 res；無 DB 層外鍵，指向的表隨 res_type 變動');
            $table->string('label')->comment('別名的原樣字串');
            $table->string('normalized')->comment('比對用的鍵，在 PHP 算好（NomenResolver::normalize），比對時只做完全相等');
            $table->string('label_type', 16)->comment('alt（可以顯示的別名）或 hidden（只拿來比對、不顯示，例如拼錯、topic 寫法）');
            $table->string('locale', 16)->nullable()->comment('人寫的名稱是哪種自然語言（例如 zh-Hant、en）；GitHub 來的字串留空');
            $table->string('note')->nullable()->comment('這個別名的來由，例如舊正式名、GitHub topic 寫法');
            $table->timestamps();

            $table->unique(['res_type', 'res_id', 'normalized']);
            $table->index('normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('res_labels');
    }
};
