<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `call_number` 在兩張表都是「有預設值、但 DB 層級不允許 NULL」，跟兩支
 * FormRequest 的 `nullable|numeric` 驗證規則對不上——這個落差在
 * `Relation::firstOrCreate()`／`Scope::create()` 上會炸開：Eloquent 收到
 * `call_number => null`（前端留空、經 `ConvertEmptyStringsToNull` 轉換後）
 * 會**明確**把 NULL 寫進 INSERT 語句，而不是省略這個欄位讓 DB 的
 * `default('00')` 生效——只有完全不出現在 INSERT 裡的欄位才吃得到預設值。
 *
 * 這個 bug 一直都在，Scope 編輯頁也中——只是先前的手動與 Playwright 測試
 * 剛好都有填 call_number，沒有人測過留空新增的路徑。這次做 Relation 編輯頁
 * 才用 Playwright 逐一走過空欄位的情況，第一次真的踩到。
 *
 * 兩張表原本用不同的欄位型別（`scopes.call_number` 是 `char(2)`，
 * `relations.call_number` 是 `string(2)`），這裡刻意不順手統一——那是另一個
 * 決定，不屬於這支 migration 要修的東西。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scopes', function (Blueprint $table) {
            $table->char('call_number', 2)->default('00')->nullable()->change();
        });

        Schema::table('relations', function (Blueprint $table) {
            $table->string('call_number', 2)->default('00')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scopes', function (Blueprint $table) {
            $table->char('call_number', 2)->default('00')->nullable(false)->change();
        });

        Schema::table('relations', function (Blueprint $table) {
            $table->string('call_number', 2)->default('00')->nullable(false)->change();
        });
    }
};
