<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation 之間三個述詞（accompanies、precedes、succeeds）的外部詞彙對照，
 * 從 Tillett (1987) 的分類名改成有官方定義的屬性：
 *
 * - `accompanies` → RDA `P10103`（is complemented by work），對稱
 * - `precedes` → BIBFRAME `succeededBy`：A precedes B 就是 A bf:succeededBy B，方向要看清楚
 * - `succeeds` → BIBFRAME `precededBy`
 *
 * 只改 `source_vocabulary`、`source_term`、`note` 三欄，述詞名稱、id、類號、反向配對都不動。
 * seeder 只建不改，已經種過舊值的資料庫靠這支改；全新的資料庫由 RelationSeeder 直接種出新值，
 * 這支什麼都不做。只改還停在舊詞彙的列，後台改過詞彙的不動。`down()` 還原成舊值。
 */
return new class extends Migration
{
    /** 新值，跟 RelationSeeder 同一份（RelationSourceVocabularyTest 守著兩邊一致）。 */
    public const NEW = [
        'accompanies' => [
            'source_vocabulary' => 'rda',
            'source_term' => 'P10103',
            'note' => 'RDA P10103 (is complemented by work), http://rdaregistry.info/Elements/w/P10103 — "Relates a work to a work that is paired with a related work without either work being considered to be predominant." 對稱關係：兩個獨立但相伴而生、共同組成同一個產品的 repo（例如前後端拆分）。',
        ],
        'precedes' => [
            'source_vocabulary' => 'bibframe',
            'source_term' => 'succeededBy',
            'note' => 'BIBFRAME succeededBy, http://id.loc.gov/ontologies/bibframe/succeededBy — "Resource that succeeds the resource being described, e.g., later in time or after in a narrative". A precedes B 即 A bf:succeededBy B。依建立時間前後相接的同性質練習專案。',
        ],
        'succeeds' => [
            'source_vocabulary' => 'bibframe',
            'source_term' => 'precededBy',
            'note' => 'BIBFRAME precededBy, http://id.loc.gov/ontologies/bibframe/precededBy — "Resource that precedes the resource being described, e.g., is earlier in time or before in narrative". Reverse of precedes：A succeeds B 即 A bf:precededBy B。',
        ],
    ];

    /** 改之前的值（當時的快照，down() 用）。 */
    public const OLD = [
        'accompanies' => [
            'source_vocabulary' => 'tillett1987',
            'source_term' => 'Accompanying',
            'note' => 'Tillett (1987) bibliographic-relationships taxonomy 的 Accompanying 類別命名（非 DCMI/SPDX 正式詞彙）。對稱關係：兩個獨立但相伴而生、共同組成同一個產品的 repo（例如前後端拆分）。',
        ],
        'precedes' => [
            'source_vocabulary' => 'tillett1987',
            'source_term' => 'Sequential',
            'note' => 'Tillett (1987) bibliographic-relationships taxonomy 的 Sequential 類別命名（非 DCMI/SPDX 正式詞彙）。依建立時間前後相接的同性質練習專案。',
        ],
        'succeeds' => [
            'source_vocabulary' => 'tillett1987',
            'source_term' => 'Sequential',
            'note' => 'Reverse of precedes.',
        ],
    ];

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    /** 把名字對得上、且詞彙欄位還是 $from 的列改成 $to；其他列（含後台改過的）不動。 */
    private function swap(array $from, array $to): void
    {
        DB::transaction(function () use ($from, $to) {
            foreach ($to as $name => $values) {
                DB::table('relations')
                    ->where('name', $name)
                    ->where('source_vocabulary', $from[$name]['source_vocabulary'])
                    ->update($values + ['updated_at' => now()]);
            }
        });
    }
};
