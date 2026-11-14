<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * PostgreSQL 上分兩步，不用 `unique()->online()`（見 docs/zero-downtime-migrations.md §4.3）：
 *
 * 1. `CREATE UNIQUE INDEX CONCURRENTLY`：不擋讀寫，但不能在 transaction 裡跑，所以這支關掉 transaction。
 * 2. `ADD CONSTRAINT ... UNIQUE USING INDEX` 把索引掛成約束：只改目錄、很快，但要拿 ACCESS EXCLUSIVE，
 *    排隊等鎖時會擋住後面的查詢。包在自己的 transaction 裡加 `SET LOCAL lock_timeout`，等太久就放棄這次部署。
 *    `unique()->online()` 也會產生這兩句，但第二句沒有 lock_timeout。
 *
 * 不用整個連線的 `SET lock_timeout`：它也會套到第 1 步，CONCURRENTLY 要等表上進行中的 transaction 結束，
 * 等超過時限就失敗，留下 INVALID 索引。
 *
 * 這支失敗時不會記進 `migrations` 表，但第 1 步建好的索引（INVALID，或已建好但還沒掛成約束）會留著，
 * 重跑會撞 already exists。先刪掉再重跑：
 * `DROP INDEX CONCURRENTLY IF EXISTS documentations_source_repo_source_key_unique;`
 * 索引已經掛成約束（這支其實跑完了）時，這句會報錯、不會刪掉。
 */
return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY 不能在 transaction 裡跑
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('documentations', function (Blueprint $table) {
                $table->unique(['source_repo', 'source_key']);
            });

            return;
        }

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY documentations_source_repo_source_key_unique ON documentations (source_repo, source_key)');

        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE documentations ADD CONSTRAINT documentations_source_repo_source_key_unique UNIQUE USING INDEX documentations_source_repo_source_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documentations', function (Blueprint $table) {
            $table->dropUnique(['source_repo', 'source_key']);
        });
    }
};
