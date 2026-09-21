<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 述詞的外部詞彙出處，結構化成獨立欄位（分類帳低優先度項，2026-09-18 記錄）。
 *
 * 在此之前，出處只寫在 `note` 這一段散文裡——資訊都在，但只有人讀得懂，機器
 * 查不了。這兩個欄位是一對 CURIE 風格的前綴／局部名（本專案的 `SetCURIEAttribute`
 * 已經有「用兩段式代碼指一個東西」這個概念，這裡是同一個形狀，換一個場景）：
 * `source_vocabulary` 是外部詞彙表的簡稱（'spdx'、'dcterms'、'prov'、
 * 'tillett1987'——最後一個是書目關係分類法，不是 RDF 詞彙，但一樣是外部、
 * 具名、可查證的出處，不是本專案自創），`source_term` 是那個詞彙表裡的
 * 具體詞條名稱。`null` 代表這條述詞是本專案自創，查無乾淨的外部對應。
 *
 * **這個欄位記錄的是事實（抄自哪裡），不是意圖（我認為它該不該被淘汰）**——
 * 這正是 issue #59（棄用機制）卡住的地方沒辦法做到的事：對一個借來的述詞，
 * 「我當初定義錯了」這個解釋大部分被排除掉，因為定義本來就不是本專案下的；
 * 對一個自創的述詞，這個解釋仍然成立。細節與查證見
 * `management-debt-ledger.md` 這一列與 #59 那一列。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relations', function (Blueprint $table) {
            $table->string('source_vocabulary', 30)->nullable()->after('note')
                ->comment('外部詞彙表簡稱，null 代表本專案自創');
            $table->string('source_term', 100)->nullable()->after('source_vocabulary')
                ->comment('該詞彙表裡的具體詞條名稱');
        });
    }

    public function down(): void
    {
        Schema::table('relations', function (Blueprint $table) {
            $table->dropColumn(['source_vocabulary', 'source_term']);
        });
    }
};
