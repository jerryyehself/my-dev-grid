<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 新增 `article_units`：文章切成的段落單位（`ArticleUnitSplitter` 的結果），每次從文章來源 repo
 * 匯入或更新文章時，由 `ArticleUnitSync` 寫入。
 *
 * - 鍵是 `(article_id, unit_id)`：`unit_id` 是原稿標題行尾寫的 `{#id}`（導言固定是 `lead`），
 *   一篇文章裡唯一。還沒補寫 `{#id}` 的單位 `unit_id` 是 NULL；唯一索引在 SQLite 與 PostgreSQL
 *   都把 NULL 當成彼此不同，所以同一篇文章可以有好幾個還沒有 ID 的單位。
 * - `article_id` 指向 `documentations`（文章是 `documentations` 的一種），刪掉文章（force delete）時
 *   單位跟著刪；軟刪除不影響。
 * - `parent_id` 指向同一張表的另一列（H3 所屬的 H2）。用列的 id 而不是父單位的 `unit_id`：
 *   父單位可能還沒有 ID。
 * - 原稿裡不見的單位不刪，填 `removed_at`，ID 保留給引用它的地方顯示「段落已移除」。
 * - `content_hash` 只用來偵測標題或內容有沒有變，不是單位的身分。
 *
 * 這一步只新增資料表，不改任何既有的表；舊版程式不知道這張表，照常運作。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 外鍵會對 documentations 拿 SHARE ROW EXCLUSIVE，排隊等鎖時會擋住後面對 documentations 的寫入；等太久就放棄這次部署
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('article_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('documentations')->cascadeOnDelete()->comment('所屬的文章（documentations.id）');
            $table->string('unit_id')->nullable()->comment('原稿標題的明確 ID（{#id}），導言固定是 lead；還沒補寫的是空值');
            $table->foreignId('parent_id')->nullable()->constrained('article_units')->nullOnDelete()->comment('所屬的上層單位（H3 所屬的 H2）；H2 與導言是空值');
            $table->string('role', 16)->comment('intro（導言）、references（來源／參考文獻節）或 body（一般）');
            $table->unsignedTinyInteger('level')->nullable()->comment('標題層級（2 或 3）；導言是空值');
            $table->text('heading')->nullable()->comment('標題文字（已去掉 {#id}）；導言是空值');
            $table->text('content')->comment('單位的 Markdown 內容，不含標題行');
            $table->char('content_hash', 64)->comment('標題與內容的 SHA-256（hex），只用來偵測內容有沒有變');
            $table->unsignedInteger('position')->comment('在文章裡的順序（0 起算）；被移除的單位停在最後一次出現的位置');
            $table->timestamp('removed_at')->nullable()->comment('原稿裡不再出現的時間；還在的是空值');
            $table->timestamps();

            $table->unique(['article_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_units');
    }
};
