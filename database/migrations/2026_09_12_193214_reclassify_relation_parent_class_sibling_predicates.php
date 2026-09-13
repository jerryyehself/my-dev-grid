<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 一次性回填修正：RelationSeeder 先前把 assists/assisted-by 的 parent_class
 * 指向 uses/used、isRequiredBy 的 parent_class 指向 requires——這兩組都是誤用。
 *
 * assists 的 note 明講「AI 輔助但非直接產出/執行成品」，明確排除了 uses 的情況；
 * 依 RDFS rdfs7（子屬性的每一筆事實都蘊含父屬性成立），assists ⊑ uses 會導出
 * 「A assists B」同時蘊含「A uses B」，這跟 assists 自己的定義互相矛盾——
 * 兩者該是平輩（互斥），不是父子。外部先例：SPDX 3.0.1 RelationshipType 把
 * usesTool／dependsOn 並列為平輩。
 *
 * isRequiredBy 是 requires 的反向關係，反向關係該用 reverse_id 表達（本來就已經
 * 設定），不該同時又用 parent_class 表達成子關係。外部先例：DCMI Metadata Terms
 * 的 dcterms:requires／dcterms:isRequiredBy 皆為 dcterms:relation 的子屬性、
 * 彼此平輩，沒有互為父子。
 *
 * 這兩筆 relation 目前都沒有被任何真實 EntityRelation／pivot link 引用
 * （Relation::isReferenced() 為 false），不受 Relation::LOCKED_FIELDS 保護，
 * 可以安全回填。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('relations')
            ->whereIn('name', ['assists', 'assisted-by'])
            ->update(['parent_class' => null]);

        DB::table('relations')
            ->where('name', 'isRequiredBy')
            ->update(['parent_class' => null]);
    }

    public function down(): void
    {
        $uses = DB::table('relations')->where('name', 'uses')->value('id');
        $used = DB::table('relations')->where('name', 'used')->value('id');
        $requires = DB::table('relations')->where('name', 'requires')->value('id');

        DB::table('relations')->where('name', 'assists')->update(['parent_class' => $uses]);
        DB::table('relations')->where('name', 'assisted-by')->update(['parent_class' => $used]);
        DB::table('relations')->where('name', 'isRequiredBy')->update(['parent_class' => $requires]);
    }
};
