<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `accompanies` 的外部詞彙對照，從 RDA `P10103`（rdaw，is complemented by work，定義域 Work）
 * 改成 RDA `P30027`（rdam，is accompanied by manifestation，定義域 Manifestation）。
 *
 * 原因：本站的作品（Implementation）是實體／實際存在的那一層，不是抽象的 Work 層，
 * 所以對到 Manifestation 層的屬性才貼切。RDA 沒有把 P30027 宣告成對稱，也沒有給反向屬性，
 * 本站的 accompanies 是對稱的，這是近似對照，note 裡有寫明。
 *
 * 只改 `source_term`、`note` 兩欄（`source_vocabulary` 一直是 `rda`），述詞名稱、id、類號、反向配對都不動；
 * `precedes`／`succeeds` 不在這支範圍。seeder 只建不改，已經種過 P10103 的資料庫靠這支改；
 * 全新的資料庫由 RelationSeeder 直接種出新值，這支什麼都不做。只改還停在 P10103 的列，
 * 後台改過詞彙的不動。`down()` 還原成 P10103，同樣只動還停在 P30027 的列。
 */
return new class extends Migration
{
    /** 新值，跟 RelationSeeder 同一份（測試守著兩邊一致）。 */
    public const NEW = [
        'source_vocabulary' => 'rda',
        'source_term' => 'P30027',
        'note' => 'RDA P30027 (is accompanied by manifestation), http://rdaregistry.info/Elements/m/P30027 — "Relates a manifestation to a manifestation that is a part of the same manifestation as another part manifestation." 對稱關係：兩個獨立但相伴而生、共同組成同一個產品的 repo（例如前後端拆分）。RDA 沒有把 P30027 宣告成對稱，也沒有給反向屬性；本站的 accompanies 是對稱的，所以這是近似對照。',
    ];

    /** 改之前的值（當時的快照，down() 用）。 */
    public const OLD = [
        'source_vocabulary' => 'rda',
        'source_term' => 'P10103',
        'note' => 'RDA P10103 (is complemented by work), http://rdaregistry.info/Elements/w/P10103 — "Relates a work to a work that is paired with a related work without either work being considered to be predominant." 對稱關係：兩個獨立但相伴而生、共同組成同一個產品的 repo（例如前後端拆分）。',
    ];

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    /** 把名字是 accompanies、且詞彙欄位還是 $from 的列改成 $to；其他列（含後台改過的）不動。 */
    private function swap(array $from, array $to): void
    {
        DB::transaction(function () use ($from, $to) {
            DB::table('relations')
                ->where('name', 'accompanies')
                ->where('source_vocabulary', $from['source_vocabulary'])
                ->where('source_term', $from['source_term'])
                ->update($to + ['updated_at' => now()]);
        });
    }
};
