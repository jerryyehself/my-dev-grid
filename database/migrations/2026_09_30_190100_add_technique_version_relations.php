<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 技術的版本關係：Dublin Core 的 dcterms:isVersionOf／dcterms:hasVersion（2026-09-30 使用者同意）。
 *
 * 每個版本自己是一筆 Technique——title 跟原本的技術相同、`version` 欄位填主版號
 * （例如 title=Vue、version=3），再用 isVersionOf 連回版本留空的那一筆（作品層級的 Vue）。
 * 這樣一個專案可以同時連到 Vue 2 和 Vue 3，歷史不會被蓋掉；版本放在連結表的欄位上就做
 * 不到，因為連結表的唯一鍵是（專案、技術、關係）。
 *
 * 跟 requires／isRequiredBy 一樣是 Technique×Technique（class 11），走 entity_relations。
 *
 * 全新的資料庫：這時 relations 是空的，什麼都不做，由 RelationSeeder 種出來。
 * RelationSeeder 把這兩筆放在最後面，種出來的 id 才會跟這支 migration 補進舊資料庫的一樣。
 */
return new class extends Migration
{
    /** RelationSeeder 裡同一份定義（RelationSourceVocabularyTest 守著兩邊一致） */
    public const RELATIONS = [
        'isVersionOf' => [
            'call_number' => '20',
            'note' => 'dcterms:isVersionOf — this Technique record is one version (e.g. Vue 3) of the version-less Technique it points to (Vue).',
            'source_term' => 'isVersionOf',
        ],
        'hasVersion' => [
            'call_number' => '30',
            'note' => 'dcterms:hasVersion — reverse of isVersionOf.',
            'source_term' => 'hasVersion',
        ],
    ];

    public function up(): void
    {
        if (! DB::table('relations')->exists() || DB::table('relations')->where('name', 'isVersionOf')->exists()) {
            return;
        }

        $technique = DB::table('scopes')->whereNull('parent_class')->where('class_number', '10')->whereNull('deleted_at')->value('id');
        if (! $technique) {
            return;
        }

        DB::transaction(function () use ($technique) {
            $ids = [];
            foreach (self::RELATIONS as $name => $definition) {
                $ids[$name] = DB::table('relations')->insertGetId([
                    'subject_id' => $technique,
                    'object_id' => $technique,
                    'class_number' => '11',
                    'call_number' => $definition['call_number'],
                    'name' => $name,
                    'note' => $definition['note'],
                    'source_vocabulary' => 'dcterms',
                    'source_term' => $definition['source_term'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('relations')->where('id', $ids['isVersionOf'])->update(['reverse_id' => $ids['hasVersion']]);
            DB::table('relations')->where('id', $ids['hasVersion'])->update(['reverse_id' => $ids['isVersionOf']]);
        });
    }

    public function down(): void
    {
        $ids = DB::table('relations')->whereIn('name', array_keys(self::RELATIONS))->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        // 已經有版本邊掛在上面的話，一起拿掉——那些邊只能用這兩個述詞表達，述詞沒了邊也沒有意義
        DB::transaction(function () use ($ids) {
            DB::table('entity_relations')->whereIn('relation_id', $ids)->delete();
            DB::table('relations')->whereIn('id', $ids)->update(['reverse_id' => null]);
            DB::table('relations')->whereIn('id', $ids)->delete();
        });
    }
};
