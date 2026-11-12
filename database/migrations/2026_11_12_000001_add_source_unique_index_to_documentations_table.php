<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 同一個文章來源 repo 的同一篇文章（source_repo＋source_key）只能對到一筆文章（#113）。
 *
 * 刻意不排除已軟刪除的列（不是 `WHERE deleted_at IS NULL` 的部分索引）：後台刪掉的文章
 * 仍然佔著這組鍵，匯入時撞到唯一索引，就不會再建一筆把它帶回來。
 *
 * 沒有來源的文章兩欄都是 NULL，不受影響：PostgreSQL 的唯一索引預設 NULLS DISTINCT，
 * SQLite 也把每個 NULL 當成不同的值。
 *
 * 用 `->online()`（PostgreSQL 的 CREATE INDEX CONCURRENTLY）建，不擋讀寫；它不能在
 * transaction 裡跑，所以這支關掉 transaction，也只放這一個索引（見 docs/zero-downtime-migrations.md §4.3）。
 * 在 PostgreSQL 上 Laravel 會先建索引，再用 `ADD CONSTRAINT ... UNIQUE USING INDEX` 掛成約束；
 * 第二句只改目錄、很快，但會短暫拿 ACCESS EXCLUSIVE。第二句失敗時索引已經建好，
 * 重跑前要先刪掉它（跟 §4.3「失敗會留下 INVALID 索引」同樣的處理）。
 */
return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY 不能在 transaction 裡跑
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('documentations', function (Blueprint $table) {
            $table->unique(['source_repo', 'source_key'])->online();
        });
    }

    public function down(): void
    {
        Schema::table('documentations', function (Blueprint $table) {
            $table->dropUnique(['source_repo', 'source_key']);
        });
    }
};
