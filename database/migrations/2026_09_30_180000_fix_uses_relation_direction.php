<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `uses` 的方向跟 ER model 概念圖（docs/readme/er_model_concept.svg）反了——2026-09-30
 * 使用者指出。概念圖的意思是「實作 uses 技術」（Implementation 當主詞），但 RelationSeeder
 * 一開始把 `uses` 定義成 Technique → Implementation，反向那筆才是 Implementation → Technique
 * 的 `used`。結果圖譜上 84 條邊讀起來是「html5-qrcode uses isbn-scanner」。seeder 的 note
 * 自己也承認「Technique 當主詞是本專案慣例，不是 PROV-O 的字面對應」。
 *
 * 修法是**只改名字，不動 id、不搬任何一條邊**：
 * - 原本的 `uses`（Technique → Implementation，class_number 12）改名 `usedBy`
 * - 原本的 `used`（Implementation → Technique，class_number 21）改名 `uses`
 *
 * 邊不用搬的原因：三張 pivot 表的主受詞型別由表本身決定（見 RelationEdgeQuery），
 * `technique_implementation` 一律是 Technique 當主詞，所以那 84 條邊本來就該指向 class 12
 * 那一筆。改名之後它們的意思從「技術 uses 實作」變成「技術 usedBy 實作」，也就是「實作 uses
 * 技術」的另一頭讀法——邊本身一個字都不用改。反向名稱用 `usedBy`，不沿用 `used`：跟
 * `specifiedBy`／`documentedBy` 同一種寫法，過去式 `used` 看不出是被動。
 *
 * 外部詞彙出處（source_term）跟著修，方向照 SPDX 規格逐字核對過：
 * - `uses`（實作 → 技術）＝ SPDX 2.3 `DEPENDS_ON`（「A depends on B」，A 是實作）
 * - `usedBy`（技術 → 實作）＝ SPDX 2.3 `DEPENDENCY_OF`
 * - `assisted-by`（實作 → AI 工具）改成 SPDX 3.0.1 `usesTool`（「The from Element uses each
 *   to Element as a tool」，from 是實作）。原本對到 `dependsOn`，那是 `uses` 的詞條，跟
 *   「assists 與 uses 是平輩，比照 SPDX usesTool／dependsOn 並列」的說法自相矛盾。`assists`
 *   （AI 工具 → 實作）SPDX 3 沒有反向詞條，照 `documentedBy` 的慣例沿用 `usesTool`，不用改。
 *
 * 全新的資料庫：migration 先跑、那時 relations 是空的，這支什麼都不做，之後 RelationSeeder
 * 直接種出新名字。只有已經種過舊名字的資料庫才會真的改名。
 */
return new class extends Migration
{
    /** 新名字對應的 note 與詞條，RelationSeeder 裡同一份內容（RelationSourceVocabularyTest 守著兩邊一致）。 */
    public const RENAMED = [
        'uses' => [
            'note' => 'SPDX 2.3 DEPENDS_ON (A depends on B) — an Implementation uses a Technique. Close in spirit to PROV-O prov:used (Activity used Entity).',
            'source_vocabulary' => 'spdx',
            'source_term' => 'DEPENDS_ON',
        ],
        'usedBy' => [
            'note' => 'Reverse of uses.',
            'source_vocabulary' => 'spdx',
            'source_term' => 'DEPENDENCY_OF',
        ],
    ];

    public const ASSISTED_BY_TERM = 'usesTool';

    public function up(): void
    {
        $oldUses = DB::table('relations')->where('name', 'uses')->first();
        $oldUsed = DB::table('relations')->where('name', 'used')->first();

        // 空資料庫（之後才 seed 新名字），或已經改過（`used` 不存在了）：不用做事。
        // 還是 `uses` 當主詞的是 Technique（class 12）才改——防止在已經是新定義的資料上反向改回去。
        if (! $oldUses || ! $oldUsed || $oldUses->class_number !== '12' || $oldUsed->class_number !== '21') {
            return;
        }

        DB::transaction(function () use ($oldUses, $oldUsed) {
            // 先把舊 `uses` 改掉，再把 `used` 改成 `uses`，中間不會有兩筆同名
            DB::table('relations')->where('id', $oldUses->id)->update(self::RENAMED['usedBy'] + ['name' => 'usedBy', 'updated_at' => now()]);
            DB::table('relations')->where('id', $oldUsed->id)->update(self::RENAMED['uses'] + ['name' => 'uses', 'updated_at' => now()]);
            DB::table('relations')->where('name', 'assisted-by')->update(['source_term' => self::ASSISTED_BY_TERM, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        $newUses = DB::table('relations')->where('name', 'uses')->first();
        $usedBy = DB::table('relations')->where('name', 'usedBy')->first();

        if (! $newUses || ! $usedBy || $newUses->class_number !== '21' || $usedBy->class_number !== '12') {
            return;
        }

        DB::transaction(function () use ($newUses, $usedBy) {
            DB::table('relations')->where('id', $newUses->id)->update([
                'name' => 'used',
                'note' => 'Reverse of uses.',
                'source_term' => 'DEPENDENCY_OF',
                'updated_at' => now(),
            ]);
            DB::table('relations')->where('id', $usedBy->id)->update([
                'name' => 'uses',
                'note' => 'Close in spirit to SPDX DEPENDS_ON/DEPENDENCY_OF and PROV-O prov:used (Activity used Entity); this project\'s subject direction (Technique as subject) is a project convention, not a literal PROV-O mapping — kept as-is since data already exists.',
                'source_term' => 'DEPENDS_ON',
                'updated_at' => now(),
            ]);
            DB::table('relations')->where('name', 'assisted-by')->update(['source_term' => 'dependsOn', 'updated_at' => now()]);
        });
    }
};
